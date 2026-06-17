<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Http\Controllers\AdminVoicePoolController;
use App\Jobs\DispatchWaveJob;
use App\Jobs\GenerateBgChunkJob;
use App\Jobs\PersistDubCacheJob;
use App\Services\ElevenLabs\ElevenLabsClient;
use App\Services\MmsTts\MmsTtsClient;
use App\Services\TextNormalizer;
use App\Services\Tts\Drivers\AishaTtsDriver;
use App\Support\AudioFrame;
use App\Support\DubSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ProcessInstantDubSegmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;
    public int $tries = 4; // Allow retries to survive multiple horizon restarts during deploys

    public function __construct(
        public string  $sessionId,
        public int     $index,
        public string  $text,
        public float   $startTime,
        public float   $endTime,
        public string  $language,
        public string  $speaker = 'M1',
        public ?float  $slotEnd = null,
        public ?string $sourceText = null,
        public ?string $delivery = null, // "emotion|pace" e.g. "angry|fast"
    ) {}

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['status'] ?? '') === 'stopped') {
            return;
        }

        // Strip delivery hints that may have leaked through translation ({calm|slow} or {emotion:calm|pace:slow})
        $this->text = trim(preg_replace('/\s*\{(?:emotion:)?[a-z]+\|(?:pace:)?[a-z]+\}\s*$/i', '', trim($this->text)));

        $title = $session['title'] ?? 'Untitled';

        try {
            $slotDuration = $this->endTime - $this->startTime;

            // Empty text = background-only (from failed translation batch)
            if (trim($this->text) === '') {
                $this->generateBackgroundOnlyAac($session);
                $this->incrementReady();
                Log::info("[DUB] [{$title}] Segment #{$this->index} ready (background-only, failed translation)", [
                    'session' => $this->sessionId,
                ]);
                return;
            }

            // 1. Generate TTS audio with configured driver
            $tmpDir = '/tmp/instant-dub-' . $this->sessionId;
            @mkdir($tmpDir, 0755, true);

            $rawMp3 = "{$tmpDir}/seg_{$this->index}.mp3";
            $rawWav = "{$tmpDir}/seg_{$this->index}.wav";

            // Read per-speaker voice entry from voice map
            $voiceMap     = json_decode(Redis::get(DubSession::voicesKey($this->sessionId)), true) ?? [];
            $speakerEntry = $voiceMap[$this->speaker] ?? [];
            $driver       = $speakerEntry['driver'] ?? ($session['tts_driver'] ?? config('dubber.tts.default', 'edge'));

            // Voice map expired from Redis — reconstruct from session force_voice
            if (empty($speakerEntry) && !empty($session['force_voice'])) {
                $entry = \App\Services\VoiceMapBuilder::forceVoiceEntry($driver, $session['force_voice']);
                if ($entry) $speakerEntry = $entry;
            }

            // Inject pre-registered MMS voice ID to skip /add_voice API call per segment
            if ($driver === 'mms' && !empty($session['force_voice_id']) && empty($speakerEntry['mms_voice_id'])) {
                $fv  = $session['force_voice'] ?? null;
                $fvG = $fv ? \App\Services\VoiceMapBuilder::genderFromTag($fv) : ($speakerEntry['gender'] ?? 'male');
                $speakerEntry['mms_voice_id'] = $session['force_voice_id'];
                $speakerEntry['tau']   ??= \App\Http\Controllers\AdminVoicePoolController::getTau($fvG, $fv ?? '');
                $speakerEntry['speed'] ??= \App\Http\Controllers\AdminVoicePoolController::getSpeed($fvG, $fv ?? '');
            }

            try {
                if ($driver === 'elevenlabs' && !empty($speakerEntry['voice_id'])) {
                    $this->generateWithElevenLabs($rawMp3, $speakerEntry['voice_id']);
                } elseif ($driver === 'aisha') {
                    $this->generateWithAisha($rawMp3, $speakerEntry);
                } elseif ($driver === 'mms') {
                    // MMS outputs WAV directly — no MP3 encoding to avoid quality loss
                    $this->generateWithMms($rawWav, $tmpDir, $speakerEntry);
                    $rawMp3 = $rawWav;
                } elseif ($driver === 'openai') {
                    try {
                        $this->generateWithOpenAi($rawMp3, $speakerEntry);
                    } catch (\RuntimeException $e) {
                        if (str_starts_with($e->getMessage(), 'openai_billing:')) {
                            Log::warning("[DUB] OpenAI billing/quota, falling back to Edge TTS — seg #{$this->index}", ['session' => $this->sessionId]);
                            $this->generateWithEdgeTts($rawMp3, $tmpDir, $speakerEntry);
                        } else {
                            throw $e;
                        }
                    }
                } else {
                    $this->generateWithEdgeTts($rawMp3, $tmpDir, $speakerEntry);
                }
            } catch (\Throwable $ttsEx) {
                Log::warning("[DUB] [{$title}] TTS unavailable for seg #{$this->index} ({$driver}), using background-only: " . $ttsEx->getMessage(), [
                    'session' => $this->sessionId,
                ]);
                $this->generateBackgroundOnlyAac($session);
                $this->incrementReady();
                return;
            }

            // 2. Adjust tempo so TTS fills the timeslot naturally
            // Skipped for MMS (WAV→MP3 encode artifacts) and force_voice sessions.
            $ttsDuration = $this->getAudioDuration($rawMp3);
            $finalMp3 = $rawMp3;
            $isForceVoice = !empty($session['force_voice']);
            $isMms = ($driver === 'mms');

            if (!$isForceVoice && !$isMms && $slotDuration > 0.5 && $ttsDuration > 0.1) {
                $ratio = $ttsDuration / $slotDuration;
                $tempo = null;

                if ($ratio > 1.05) {
                    $tempo = min($ratio, 1.35);
                } elseif ($ratio < 0.9) {
                    $targetDuration = $slotDuration * 0.95;
                    $tempo = max($ttsDuration / $targetDuration, 0.8);
                }

                if ($tempo !== null) {
                    $adjustedMp3 = "{$tmpDir}/seg_{$this->index}_adj.mp3";
                    $result = Process::timeout(15)->run([
                        'ffmpeg', '-y', '-i', $rawMp3,
                        '-filter:a', "atempo={$tempo}",
                        '-codec:a', 'libmp3lame', '-b:a', '128k',
                        $adjustedMp3,
                    ]);

                    if ($result->successful() && file_exists($adjustedMp3) && filesize($adjustedMp3) > 200) {
                        @unlink($rawMp3);
                        $finalMp3 = $adjustedMp3;
                        $ttsDuration = $this->getAudioDuration($finalMp3);
                    }
                }
            }

            // 3. Move audio to persistent per-session directory (avoids base64 bloat in Redis)
            $audioDir = DubSession::audioDir($this->sessionId);
            @mkdir($audioDir, 0755, true);
            $ext       = pathinfo($finalMp3, PATHINFO_EXTENSION) ?: 'mp3';
            $audioPath = "{$audioDir}/seg_{$this->index}.{$ext}";
            rename($finalMp3, $audioPath);

            // 4. Save TTS chunk metadata to Redis (path only — no base64)
            Redis::setex(DubSession::chunkKey($this->sessionId, $this->index), DubSession::TTL, json_encode([
                'index'          => $this->index,
                'start_time'     => $this->startTime,
                'end_time'       => $this->endTime,
                'slot_end'       => $this->slotEnd,
                'text'           => $this->text,
                'source_text'    => $this->sourceText,
                'speaker'        => $this->speaker,
                'audio_path'     => $audioPath,
                'audio_duration' => $ttsDuration,
            ]));

            // 5. Energy transfer — bg chunk tayyor bo'lsa shu yerda apply qilish
            // (yangi kinoda bg tez, TTS kech bo'ladi; shuning uchun TTS tarafdan ham chaqiramiz)
            $this->applyEnergyTransferIfBgReady();

            // 6. Dispatch GenerateBgChunkJob for bg chunks overlapping this segment.
            // ShouldBeUnique + 3s delay collapses concurrent TTS completions into one ffmpeg run.
            $bgHashData = Redis::hgetall(DubSession::bgChunksKey($this->sessionId)) ?? [];
            foreach ($bgHashData as $bgIdx => $bgJson) {
                $bg = json_decode($bgJson, true);
                $cs = (float) ($bg['start'] ?? 0);
                $ce = (float) ($bg['end']   ?? 0);
                if ($this->startTime < $ce && $this->endTime > $cs) {
                    GenerateBgChunkJob::dispatch($this->sessionId, (int) $bgIdx, $cs, $ce)
                        ->onQueue('bg-mix')
                        ->delay(now()->addSeconds(3));
                }
            }

            // 5. Increment ready counter and dispatch cache persist on completion
            $this->incrementReady();
            $this->dispatchPersistIfComplete();

            Log::info("[DUB] [{$title}] Segment #{$this->index} ready ({$this->speaker}, " . round($ttsDuration, 2) . "s): " . Str::limit($this->text, 60), [
                'session' => $this->sessionId,
            ]);

        } catch (\Throwable $e) {
            Log::error("[DUB] [{$title}] Segment #{$this->index} FAILED: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);

            // Store error chunk so polling doesn't stall
            Redis::setex(DubSession::chunkKey($this->sessionId, $this->index), DubSession::TTL, json_encode([
                'index'          => $this->index,
                'start_time'     => $this->startTime,
                'end_time'       => $this->endTime,
                'text'           => $this->text,
                'speaker'        => $this->speaker,
                'audio_path'     => null,
                'audio_duration' => 0,
                'error'          => $e->getMessage(),
            ]));

            $this->incrementReady();
        }
    }

    public function failed(\Throwable $exception): void
    {
        $chunkKey = DubSession::chunkKey($this->sessionId, $this->index);
        if (!Redis::exists($chunkKey)) {
            Redis::setex($chunkKey, DubSession::TTL, json_encode([
                'index'          => $this->index,
                'start_time'     => $this->startTime,
                'end_time'       => $this->endTime,
                'text'           => $this->text,
                'speaker'        => $this->speaker,
                'audio_path'     => null,
                'audio_duration' => 0,
                'error'          => 'Job killed: ' . Str::limit($exception->getMessage(), 100),
            ]));
        }

        $this->incrementReady();

        Log::error("[DUB] Segment #{$this->index} killed by worker: " . Str::limit($exception->getMessage(), 100), [
            'session' => $this->sessionId,
        ]);
    }

    private function generateWithOpenAi(string $outputMp3, array $speakerEntry = []): void
    {
        $text   = TextNormalizer::normalize($this->text, $this->language);
        $gender = strtolower($speakerEntry['gender'] ?? \App\Services\VoiceMapBuilder::genderFromTag($this->speaker));

        // Voice: user-selected or gender default
        $defaultVoice = match($gender) { 'female' => 'nova', 'child' => 'shimmer', default => 'onyx' };
        $voice = $speakerEntry['openai_voice'] ?? $defaultVoice;

        // Parse delivery hint "emotion|pace" from translation
        $emotion = 'neutral';
        $pace    = 'normal';
        if ($this->delivery) {
            [$emotion, $pace] = array_pad(explode('|', $this->delivery, 2), 2, 'normal');
        }

        // Build natural-language instructions for gpt-4o-mini-tts
        $emotionMap = [
            'angry'   => 'angry and aggressive',
            'happy'   => 'cheerful and warm',
            'sad'     => 'sad and heavy-hearted',
            'fearful' => 'fearful and anxious',
            'excited' => 'excited and energetic',
            'calm'    => 'calm and composed',
            'whisper' => 'in a quiet whisper',
            'neutral' => 'natural and conversational',
        ];
        $emotionDesc = $emotionMap[$emotion] ?? 'natural and conversational';

        $paceDesc  = match($pace) { 'fast' => ' Speak quickly.', 'slow' => ' Speak slowly and deliberately.', default => '' };
        $speed     = match($pace) { 'fast' => 1.12, 'slow' => 0.88, default => 1.0 };

        $instructions = "Speak {$emotionDesc}.{$paceDesc}";

        $model  = 'gpt-4o-mini-tts'; // supports instructions parameter
        $apiKey = config('services.openai.key');

        if (!$apiKey) {
            throw new \RuntimeException('OpenAI API key not configured — set OPENAI_API_KEY in .env');
        }

        $payload = [
            'model'           => $model,
            'input'           => $text,
            'voice'           => $voice,
            'instructions'    => $instructions,
            'speed'           => $speed,
            'response_format' => 'mp3',
        ];

        $lastError = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) usleep(($attempt - 1) * 1500 * 1000);

            $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/audio/speech', $payload);

            if ($response->successful()) {
                file_put_contents($outputMp3, $response->body());
                if (file_exists($outputMp3) && filesize($outputMp3) >= 200) {
                    return;
                }
                $lastError = 'Empty response body';
            } else {
                $lastError = $response->status() . ': ' . Str::limit($response->body(), 200);
                $status    = $response->status();
                if ($status === 401 || $status === 403) break;
                // Billing/quota errors — no point retrying
                if ($status === 402 || ($status === 429 && str_contains($response->body(), 'quota'))) {
                    throw new \RuntimeException("openai_billing: {$lastError}");
                }
            }

            Log::warning("[DUB] Segment #{$this->index} OpenAI TTS attempt {$attempt} failed: {$lastError}", [
                'session' => $this->sessionId,
            ]);
        }

        throw new \RuntimeException("OpenAI TTS failed after 3 attempts: {$lastError}");
    }

    private function generateWithEdgeTts(string $outputMp3, string $tmpDir, array $speakerEntry = []): void
    {
        if (!empty($speakerEntry['voice'])) {
            $voice = $speakerEntry['voice'];
            $pitch = $speakerEntry['pitch'] ?? '+0Hz';
            $rate = $speakerEntry['rate'] ?? '+0%';
        } else {
            $voice = $this->getDefaultEdgeVoice();
            $pitch = '+0Hz';
            $rate = '+0%';
        }

        $text = TextNormalizer::normalize($this->text, $this->language);
        $edgeTts = $this->resolveEdgeTts();

        // Try up to 5 times with increasing delays for network resilience
        $attempts = [
            ['voice' => $voice, 'pitch' => $pitch, 'rate' => $rate, 'delay' => 0],
            ['voice' => $voice, 'pitch' => $pitch, 'rate' => $rate, 'delay' => 1000],
            ['voice' => $voice, 'pitch' => '+0Hz', 'rate' => '+0%', 'delay' => 2000],
            ['voice' => $this->getDefaultEdgeVoice(), 'pitch' => '+0Hz', 'rate' => '+0%', 'delay' => 3000],
            ['voice' => $this->getDefaultEdgeVoice(), 'pitch' => '+0Hz', 'rate' => '+0%', 'delay' => 5000],
        ];

        // Deduplicate but keep extra retries for network errors
        $lastError = '';
        foreach ($attempts as $i => $attempt) {
            if ($attempt['delay'] > 0) {
                usleep($attempt['delay'] * 1000);
            }

            $tmpTxt = "{$tmpDir}/text_{$this->index}.txt";
            file_put_contents($tmpTxt, $text);

            $cmd = [
                $edgeTts, '-f', $tmpTxt,
                '--voice', $attempt['voice'],
                "--pitch={$attempt['pitch']}",
                "--rate={$attempt['rate']}",
                '--write-media', $outputMp3,
            ];

            $result = Process::timeout(30)->run($cmd);
            @unlink($tmpTxt);

            if ($result->successful() && file_exists($outputMp3) && filesize($outputMp3) >= 500) {
                return; // Success
            }

            $lastError = Str::limit($result->errorOutput() ?: $result->output(), 300);
            @unlink($outputMp3);

            Log::warning("[DUB] Segment #{$this->index} Edge TTS attempt " . ($i + 1) . " failed ({$attempt['voice']}), retrying", [
                'session' => $this->sessionId,
            ]);
        }

        throw new \RuntimeException('Edge TTS failed after all retries (bin=' . $edgeTts . '): ' . $lastError);
    }

    private function generateWithElevenLabs(string $outputMp3, string $voiceId): void
    {
        $text = TextNormalizer::normalize($this->text, $this->language);

        $client = new ElevenLabsClient();
        $mp3Data = $client->synthesize($voiceId, $text);

        file_put_contents($outputMp3, $mp3Data);

        if (!file_exists($outputMp3) || filesize($outputMp3) < 200) {
            throw new \RuntimeException('ElevenLabs TTS returned invalid audio');
        }
    }

    private function generateWithAisha(string $outputMp3, array $speakerEntry = []): void
    {
        $model = $speakerEntry['voice'] ?? (str_starts_with($this->speaker, 'F') ? 'gulnoza' : 'jaxongir');
        $mood = $speakerEntry['mood'] ?? 'neutral';

        $text = TextNormalizer::normalize($this->text, $this->language);

        $aisha = new AishaTtsDriver();
        $mp3Data = $aisha->callAishaApi($text, $this->language, $model, $mood);

        file_put_contents($outputMp3, $mp3Data);

        if (!file_exists($outputMp3) || filesize($outputMp3) < 200) {
            throw new \RuntimeException('AISHA TTS returned invalid audio');
        }
    }

    /**
     * Segment vaqtiga mos vocals referensini topib, prosody-transfer-service ga yuboradi.
     * Muvaffaqiyatli bo'lsa transfer qilingan WAV faylini qaytaradi, aks holda null.
     */
    private function applyEnergyTransferIfBgReady(): void
    {
        $serviceUrl = config('services.prosody_transfer.url') ?: env('PROSODY_TRANSFER_SERVICE_URL');
        if (!$serviceUrl) return;

        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['disable_prosody'] ?? false)) return;

        $bgPath = null; $bgStart = null; $bgEnd = null;
        $bgHashData = Redis::hgetall(DubSession::bgChunksKey($this->sessionId)) ?? [];
        foreach ($bgHashData as $bgJson) {
            $bg   = json_decode($bgJson, true);
            $cs   = (float) ($bg['start'] ?? 0);
            $ce   = (float) ($bg['end']   ?? 0);
            $path = $bg['path'] ?? null;
            if ($path && file_exists($path) && $this->startTime >= $cs && $this->startTime < $ce) {
                $bgPath = $path; $bgStart = $cs; $bgEnd = $ce;
                break;
            }
        }
        if (!$bgPath) return;

        $chunkKey  = DubSession::chunkKey($this->sessionId, $this->index);
        $chunkJson = Redis::get($chunkKey);
        if (!$chunkJson) return;
        $chunk     = json_decode($chunkJson, true);
        $audioPath = $chunk['audio_path'] ?? null;
        if (!$audioPath || !file_exists($audioPath)) return;

        $tmpDir = '/tmp/instant-dub-' . $this->sessionId;
        @mkdir($tmpDir, 0755, true);

        try {
            $ttsWav = "{$tmpDir}/et_{$this->index}_tts.wav";
            $conv = Process::timeout(10)->run([
                'ffmpeg', '-y', '-i', $audioPath, '-ar', '44100', '-ac', '1', '-f', 'wav', $ttsWav,
            ]);
            if (!$conv->successful() || !file_exists($ttsWav) || filesize($ttsWav) < 100) return;

            $segOffset    = max(0.0, $this->startTime - $bgStart);
            $availableRef = max(0.0, ($bgEnd - $bgStart) - $segOffset);
            if ($availableRef < 0.15) { @unlink($ttsWav); return; }

            $refDur  = min($this->endTime - $this->startTime, $availableRef);
            $refClip = "{$tmpDir}/et_{$this->index}_ref.wav";
            $cut = Process::timeout(10)->run([
                'ffmpeg', '-y',
                '-ss', (string) round($segOffset, 3),
                '-t',  (string) round($refDur, 3),
                '-i',  $bgPath,
                '-af', 'highpass=f=300,lowpass=f=3400',
                '-ar', '44100', '-ac', '1',
                $refClip,
            ]);
            if (!$cut->successful() || !file_exists($refClip) || filesize($refClip) < 100) {
                @unlink($ttsWav); return;
            }

            $resp = \Illuminate\Support\Facades\Http::timeout(30)
                ->attach('tts_audio', file_get_contents($ttsWav), 'tts.wav')
                ->attach('reference', file_get_contents($refClip), 'ref.wav')
                ->post(rtrim($serviceUrl, '/') . '/transfer');

            @unlink($ttsWav); @unlink($refClip);
            if ($resp->failed()) return;

            $outWav = "{$tmpDir}/et_{$this->index}_out.wav";
            $outMp3 = "{$tmpDir}/et_{$this->index}_out.mp3";
            file_put_contents($outWav, $resp->body());
            if (!file_exists($outWav) || filesize($outWav) < 1000) { @unlink($outWav); return; }

            $enc = Process::timeout(10)->run([
                'ffmpeg', '-y', '-i', $outWav, '-codec:a', 'libmp3lame', '-b:a', '128k', $outMp3,
            ]);
            @unlink($outWav);
            if (!$enc->successful() || !file_exists($outMp3) || filesize($outMp3) < 500) {
                @unlink($outMp3); return;
            }

            // Replace the original audio file with the energy-transferred version
            @unlink($audioPath);
            rename($outMp3, $audioPath);

            // Update chunk in Redis if the path/extension changed (e.g. wav → mp3)
            if (($chunk['audio_path'] ?? '') !== $audioPath) {
                $chunk['audio_path'] = $audioPath;
                Redis::setex($chunkKey, DubSession::TTL, json_encode($chunk));
            }

            Log::info("[DUB] Energy transfer ok — seg #{$this->index}", ['session' => $this->sessionId]);

        } catch (\Throwable $e) {
            Log::warning("[DUB] Energy transfer failed — seg #{$this->index}: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
            foreach ([
                "{$tmpDir}/et_{$this->index}.mp3",
                "{$tmpDir}/et_{$this->index}_tts.wav",
                "{$tmpDir}/et_{$this->index}_ref.wav",
                "{$tmpDir}/et_{$this->index}_out.wav",
                "{$tmpDir}/et_{$this->index}_out.mp3",
            ] as $f) @unlink($f);
        }
    }

    private function generateWithMms(string $outputMp3, string $tmpDir, array $speakerEntry = []): void
    {
        $client    = new MmsTtsClient();
        $voiceFile = null;
        $cacheKey  = null;

        $seed = null;
        if (!empty($speakerEntry['mms_voice_id'])) {
            $voiceId = $speakerEntry['mms_voice_id'];
            $speed   = $speakerEntry['speed'] ?? 1.0;
            $tau     = $speakerEntry['tau']   ?? 0.4;
            // Look up seed from pool JSON if not already in voice map
            if (!isset($speakerEntry['seed']) && !empty($speakerEntry['pool_name'])) {
                $g = $speakerEntry['gender'] ?? 'male';
                $seed = AdminVoicePoolController::getSeed($g, $speakerEntry['pool_name']);
            } else {
                $seed = $speakerEntry['seed'] ?? null;
            }
        } else {
            $gender = $speakerEntry['gender'] ?? \App\Services\VoiceMapBuilder::genderFromTag($this->speaker);
            $poolName   = $speakerEntry['pool_name'] ?? null;
            $speakerIdx = (int) preg_replace('/\D/', '', $this->speaker) ?: 0;

            $voiceFile = $poolName
                ? $this->mmsPoolFileByName($gender, $poolName)
                : $this->mmsPoolFile($gender, $speakerIdx);

            if (!$voiceFile) {
                throw new \RuntimeException("MMS: no voice pool file for gender '{$gender}'" . ($poolName ? " name '{$poolName}'" : ''));
            }

            $cacheKey = 'voice-pool-id:mms:' . md5($voiceFile);
            $voiceId  = \Illuminate\Support\Facades\Redis::get($cacheKey);
            if (!$voiceId) {
                // Lock prevents parallel workers from creating duplicate MMS voice clones
                $lock = \Illuminate\Support\Facades\Cache::lock('mms-voice-register:' . md5($voiceFile), 30);
                $lock->block(30, function () use ($cacheKey, $voiceFile, $client, &$voiceId) {
                    $voiceId = \Illuminate\Support\Facades\Redis::get($cacheKey);
                    if (!$voiceId) {
                        $name    = pathinfo($voiceFile, PATHINFO_FILENAME);
                        $voiceId = $client->findVoiceByName("pool-{$name}") ?? $client->addVoice("pool-{$name}", [$voiceFile]);
                        \Illuminate\Support\Facades\Redis::setex($cacheKey, 604800, $voiceId);
                    }
                });
            }

            $name  = pathinfo($voiceFile, PATHINFO_FILENAME);
            $speed = $speakerEntry['speed'] ?? AdminVoicePoolController::getSpeed($gender, $name);
            $tau   = $speakerEntry['tau']   ?? AdminVoicePoolController::getTau($gender, $name);
            $seed  = $speakerEntry['seed']  ?? AdminVoicePoolController::getSeed($gender, $name);
        }

        // MMS model vocabulary is ASCII+basic-Latin — replace Uzbek apostrophe variants
        $text = TextNormalizer::normalize($this->text, $this->language);
        $text = str_replace(["\u{02BB}", "\u{02BC}", "\u{02B0}"], "'", $text);

        // Strip any character outside MMS vocabulary to prevent FloatTensor embedding crash.
        // MMS uz accepts: ASCII printable + Uzbek-specific ' (apostrophe) only.
        // Unknown characters make the tokenizer return float indices → pytorch crash.
        $text = preg_replace('/[^\x20-\x7E\'\x{02BB}]/u', ' ', $text);
        $text = preg_replace('/\s{2,}/', ' ', trim($text));

        $options = [
            'language' => $this->language,
            'speed'    => $speed,
            'tau'      => $tau,
            'seed'     => $seed,
        ];

        // Try to use original actor's voice as reference for this segment
        $refAudioBytes = $this->extractSegmentRefAudio();

        if ($refAudioBytes !== null) {
            Log::info("[DUB] Segment #{$this->index} synthesizeWithRef (ref bytes=" . strlen($refAudioBytes) . ")", ['session' => $this->sessionId]);
            $wavData = $client->synthesizeWithRef($text, $refAudioBytes, $options);
        } else {
            Log::info("[DUB] Segment #{$this->index} no ref audio — using pool voice", ['session' => $this->sessionId]);
            try {
                $wavData = $client->synthesize($voiceId, $text, $options);
            } catch (\RuntimeException $e) {
                // MMS service lost voice (pod restart) — re-register and retry once
                if (!str_contains(strtolower($e->getMessage()), 'not found') && !str_contains($e->getMessage(), '404')) {
                    throw $e;
                }
                $voiceId = $this->reRegisterMmsVoice($client, $voiceFile, $cacheKey);
                $wavData = $client->synthesize($voiceId, $text, $options);
            }
        }

        // Write WAV directly — no encoding artifacts
        file_put_contents($outputMp3, $wavData);

        if (!file_exists($outputMp3) || filesize($outputMp3) < 200) {
            throw new \RuntimeException('MMS synthesis returned empty audio');
        }
    }

    /**
     * Extract segment audio from original bg_chunk as voice reference.
     * Returns WAV bytes, or null if bg_chunk not ready or segment too short.
     */
    private function extractSegmentRefAudio(): ?string
    {
        $duration = $this->endTime - $this->startTime;
        if ($duration < 1.0) {
            Log::info("[DUB] Segment #{$this->index} ref skip: too short ({$duration}s)", ['session' => $this->sessionId]);
            return null;
        }

        $bgHashData = Redis::hgetall(DubSession::bgChunksKey($this->sessionId)) ?? [];

        Log::info("[DUB] Segment #{$this->index} ref search: start={$this->startTime} end={$this->endTime} chunks=" . count($bgHashData), ['session' => $this->sessionId]);

        foreach ($bgHashData as $bgJson) {
            $bgChunk = json_decode($bgJson, true);
            $cs      = (float) ($bgChunk['start'] ?? 0);
            $ce      = (float) ($bgChunk['end']   ?? 0);

            if ($this->startTime < $cs || $this->startTime >= $ce) continue;

            // Use raw original audio with frequency filter (Demucs removed)
            $rawPath  = $bgChunk['path'] ?? null;
            $refPath  = $rawPath;

            if (!$refPath || !file_exists($refPath)) {
                continue;
            }

            $segOffset = round($this->startTime - $cs, 3);
            $segDur    = round(min($duration, $ce - $this->startTime), 3);

            Log::info("[DUB] Segment #{$this->index} ref cutting raw {$cs}-{$ce} offset={$segOffset} dur={$segDur}", ['session' => $this->sessionId]);

            $tmpWav = "/tmp/ref_{$this->sessionId}_{$this->index}.wav";

            $ffmpegArgs = array_merge(
                ['ffmpeg', '-y', '-ss', (string) $segOffset, '-t', (string) $segDur, '-i', $refPath],
                ['-af', 'highpass=f=150,lowpass=f=4000'],
                ['-ar', '22050', '-ac', '1', $tmpWav]
            );

            $result = \Illuminate\Support\Facades\Process::timeout(10)->run($ffmpegArgs);

            if ($result->successful() && file_exists($tmpWav) && filesize($tmpWav) > 2000) {
                $bytes = file_get_contents($tmpWav);
                @unlink($tmpWav);
                Log::info("[DUB] Segment #{$this->index} ref ok bytes=" . strlen($bytes), ['session' => $this->sessionId]);
                return $bytes;
            }

            Log::warning("[DUB] Segment #{$this->index} ref ffmpeg failed: " . $result->errorOutput(), ['session' => $this->sessionId]);
            return null;
        }

        Log::info("[DUB] Segment #{$this->index} ref: no matching chunk found", ['session' => $this->sessionId]);
        return null;
    }

    private function reRegisterMmsVoice(MmsTtsClient $client, ?string $voiceFile, ?string $cacheKey): string
    {
        if (!$voiceFile) {
            $session   = DubSession::get($this->sessionId) ?? [];
            $fv        = $session['force_voice'] ?? null;
            if (!$fv) throw new \RuntimeException("MMS: cannot re-register — no force_voice in session");
            $gender    = DubSession::genderFromTag($fv);
            $voiceFile = $this->mmsPoolFileByName($gender, $fv);
            if (!$voiceFile) throw new \RuntimeException("MMS: cannot re-register — voice file not found for '{$fv}'");
            $cacheKey  = 'voice-pool-id:mms:' . md5($voiceFile);
        }

        $name    = pathinfo($voiceFile, PATHINFO_FILENAME);
        Redis::del($cacheKey);
        $voiceId = $client->findVoiceByName("pool-{$name}") ?? $client->addVoice("pool-{$name}", [$voiceFile]);
        Redis::setex($cacheKey, 604800, $voiceId);

        // Update force_voice_id in session if it was stale
        DubSession::patch($this->sessionId, ['force_voice_id' => $voiceId]);

        Log::info("[MMS] Re-registered voice after pod restart: {$voiceId}", ['session' => $this->sessionId]);
        return $voiceId;
    }

    private function mmsPoolFileByName(string $gender, string $name): ?string
    {
        foreach (['wav', 'mp3', 'm4a'] as $ext) {
            $path = storage_path("app/voice-pool/{$gender}/{$name}.{$ext}");
            if (file_exists($path)) return $path;
        }
        // Fallback: try other genders
        foreach (['male', 'female', 'child'] as $g) {
            if ($g === $gender) continue;
            foreach (['wav', 'mp3', 'm4a'] as $ext) {
                $path = storage_path("app/voice-pool/{$g}/{$name}.{$ext}");
                if (file_exists($path)) return $path;
            }
        }
        return null;
    }

    private function mmsPoolFile(string $gender, int $speakerIdx): ?string
    {
        if (!in_array($gender, ['male', 'female', 'child'])) {
            $gender = 'male';
        }
        $dir   = storage_path("app/voice-pool/{$gender}");
        $files = is_dir($dir) ? glob("{$dir}/*.{wav,mp3,m4a}", GLOB_BRACE) : [];
        if (empty($files)) {
            $dir   = storage_path('app/voice-pool/male');
            $files = is_dir($dir) ? glob("{$dir}/*.{wav,mp3,m4a}", GLOB_BRACE) : [];
        }
        if (empty($files)) return null;
        return $files[$speakerIdx % count($files)];
    }

    private function resolveEdgeTts(): string
    {
        // Check standard PATH first
        $which = trim(shell_exec('which edge-tts 2>/dev/null') ?? '');
        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        // Check common user-install locations (pip install --user, pipx)
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/root');
        $candidates = [
            "{$home}/.local/bin/edge-tts",
            "{$home}/bin/edge-tts",
            '/usr/local/bin/edge-tts',
            '/opt/pipx/bin/edge-tts',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Fallback — let the OS try to find it (will fail with clear error)
        return 'edge-tts';
    }

    private function getDefaultEdgeVoice(): string
    {
        $voices = [
            'uz' => 'uz-UZ-SardorNeural',
            'ru' => 'ru-RU-DmitryNeural',
            'en' => 'en-US-GuyNeural',
            'es' => 'es-ES-AlvaroNeural',
            'fr' => 'fr-FR-HenriNeural',
            'de' => 'de-DE-ConradNeural',
            'tr' => 'tr-TR-AhmetNeural',
            'ar' => 'ar-SA-HamedNeural',
            'zh' => 'zh-CN-YunxiNeural',
            'ja' => 'ja-JP-KeitaNeural',
            'ko' => 'ko-KR-InJoonNeural',
        ];

        return $voices[$this->language] ?? 'uz-UZ-SardorNeural';
    }

    private function getAudioDuration(string $path): float
    {
        $result = Process::timeout(10)->run([
            'ffprobe', '-hide_banner', '-loglevel', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1',
            $path,
        ]);

        return (float) trim($result->output());
    }

    private function dispatchPersistIfComplete(): void
    {
        $s = DubSession::get($this->sessionId);
        if ($s && ($s['status'] ?? '') === 'complete') {
            PersistDubCacheJob::dispatch($this->sessionId)->onQueue('default');
        }
    }

    private function generateBackgroundOnlyAac(array $session): void
    {
        Redis::setex(DubSession::chunkKey($this->sessionId, $this->index), DubSession::TTL, json_encode([
            'index'          => $this->index,
            'start_time'     => $this->startTime,
            'end_time'       => $this->endTime,
            'slot_end'       => $this->slotEnd,
            'text'           => '',
            'speaker'        => $this->speaker,
            'audio_path'     => null,
            'audio_duration' => 0,
        ]));
    }

    private function incrementReady(): void
    {
        $sessionKey = DubSession::key($this->sessionId);

        // Atomic increment + completion check via Lua.
        // playable=true is only set here when total_bg_chunks=0 (no bg audio expected).
        // When bg audio is present, GenerateBgChunkJob.checkPlayable() sets playable=true
        // once bg-0+bg-1 are on disk — prevents iOS from loading audio before files exist.
        $ttl = DubSession::TTL;
        $lua = <<<LUA
            local data = redis.call('GET', KEYS[1])
            if not data then return 0 end
            local session = cjson.decode(data)
            session['segments_ready'] = (session['segments_ready'] or 0) + 1
            session['last_progress_at'] = tonumber(ARGV[1])
            local total = session['total_segments'] or 999999
            if session['segments_ready'] >= total then
                session['status'] = 'complete'
                local totalBg = session['total_bg_chunks']
                if not totalBg or totalBg == 0 then
                    session['playable'] = true
                end
            end
            redis.call('SETEX', KEYS[1], {$ttl}, cjson.encode(session))

            return session['segments_ready']
        LUA;

        Redis::eval($lua, 1, $sessionKey, now()->timestamp);

        // Trigger next wave dispatch if this segment's wave is nearly done
        $this->dispatchNextWaveIfReady();
    }

    /**
     * Wave waterfall trigger: when 80% of a wave's segments are ready,
     * dispatch the next wave. This keeps the pipeline ahead of playback.
     */
    private function dispatchNextWaveIfReady(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session) return;

        $totalWaves = (int) ($session['total_waves'] ?? 0);
        if ($totalWaves <= 1) return; // single wave — nothing to dispatch

        // Determine which wave this segment belongs to
        $WAVE_DURATION = 300.0;
        $waveIndex = (int) floor($this->startTime / $WAVE_DURATION);

        // Read wave progress
        $progressKey = DubSession::waveProgressKey($this->sessionId, $waveIndex);
        $progressJson = Redis::get($progressKey);
        if (!$progressJson) return;

        $progress = json_decode($progressJson, true);
        $waveTotal = (int) ($progress['total'] ?? 0);
        if ($waveTotal === 0) return;

        // Atomic increment wave ready count
        $newReady = (int) Redis::incr($progressKey . ':ready');
        Redis::expire($progressKey . ':ready', DubSession::TTL);

        // Check if 80% threshold reached
        $threshold = (int) ceil($waveTotal * 0.8);
        if ($newReady < $threshold) return;

        // Only trigger once — check if next wave is already dispatched
        $nextWave = $waveIndex + 1;
        if ($nextWave >= $totalWaves) return;

        // Atomic check-and-set: only dispatch if not already dispatched
        $dispatched = (int) Redis::get(DubSession::wavesDispatchedKey($this->sessionId));
        if ($dispatched > $nextWave) return; // already dispatched

        // Try to claim this dispatch (atomic increment prevents double dispatch)
        $newDispatched = (int) Redis::incr(DubSession::wavesDispatchedKey($this->sessionId));
        Redis::expire(DubSession::wavesDispatchedKey($this->sessionId), DubSession::TTL);
        if ($newDispatched !== $nextWave + 1) return; // another segment already claimed it

        // Read wave offset
        $waveOffset = (int) Redis::get(DubSession::waveKey($this->sessionId, $nextWave) . ':offset');

        // Read language info from session
        $language = $session['language'] ?? 'uz';
        $translateFrom = $session['translate_from'] ?? '';

        DispatchWaveJob::dispatch(
            $this->sessionId, $nextWave, $language, $translateFrom, $waveOffset,
        )->onQueue('segment-generation');

        Log::info("[DUB] Wave {$waveIndex} at {$newReady}/{$waveTotal} ({$threshold} threshold) — dispatched wave {$nextWave}", [
            'session' => $this->sessionId,
        ]);
    }
}
