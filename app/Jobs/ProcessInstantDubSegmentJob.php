<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Http\Controllers\AdminVoicePoolController;
use App\Jobs\DownloadAudioChunkJob;
use App\Jobs\PersistDubCacheJob;
use App\Services\ElevenLabs\ElevenLabsClient;
use App\Services\MmsTts\MmsTtsClient;
use App\Services\TextNormalizer;
use App\Services\Tts\Drivers\AishaTtsDriver;
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
    ) {}

    public function handle(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";

        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) {
            return;
        }

        $session = json_decode($sessionJson, true);
        if (($session['status'] ?? '') === 'stopped') {
            return;
        }

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

            // Read per-speaker driver from voice map
            $voiceKey = "instant-dub:{$this->sessionId}:voices";
            $voiceMap = json_decode(Redis::get($voiceKey), true) ?? [];
            $speakerEntry = $voiceMap[$this->speaker] ?? [];
            $driver = $speakerEntry['driver'] ?? ($session['tts_driver'] ?? config('dubber.tts.default', 'edge'));

            // force_voice_id was pre-registered in PrepareInstantDubJob (single worker, no race).
            // Inject it into speakerEntry so generateWithMms uses it directly — no per-job pool lookup.
            if (!empty($session['force_voice_id'])) {
                $speakerEntry['driver']       = 'mms';
                $speakerEntry['mms_voice_id'] = $session['force_voice_id'];
                $speakerEntry['tau']          = 0.8;
                $driver = 'mms';
            } elseif ($driver === 'mms' && empty($speakerEntry['pool_name']) && !empty($session['force_voice'])) {
                // Fallback: voice map expired from Redis, reconstruct from session
                $fv = $session['force_voice'];
                $speakerEntry['driver']    = 'mms';
                $speakerEntry['pool_name'] = $fv;
                $speakerEntry['gender']    = str_starts_with($fv, 'F') ? 'female'
                    : (str_starts_with($fv, 'C') ? 'child' : 'male');
                $speakerEntry['tau']       = 0.8;
            }

            if ($driver === 'elevenlabs' && !empty($speakerEntry['voice_id'])) {
                $this->generateWithElevenLabs($rawMp3, $speakerEntry['voice_id']);
            } elseif ($driver === 'aisha') {
                $this->generateWithAisha($rawMp3, $speakerEntry);
            } elseif ($driver === 'mms') {
                $this->generateWithMms($rawMp3, $tmpDir, $speakerEntry);
            } else {
                $this->generateWithEdgeTts($rawMp3, $tmpDir, $speakerEntry);
            }

            // 2. Adjust tempo so TTS fills the timeslot naturally
            $ttsDuration = $this->getAudioDuration($rawMp3);
            $finalMp3 = $rawMp3;

            if ($slotDuration > 0.5 && $ttsDuration > 0.1) {
                $ratio = $ttsDuration / $slotDuration;
                $tempo = null;

                if ($ratio > 1.05) {
                    // Too long — speed up (cap 1.35x to keep words intelligible)
                    $tempo = min($ratio, 1.35);
                } elseif ($ratio < 0.9) {
                    // Too short — slow down to fill ~95% of slot (cap 0.8x to stay natural)
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

            // 3. Encode to base64
            $audioBase64 = base64_encode(file_get_contents($finalMp3));

            @unlink($finalMp3);

            // 4. Save TTS chunk to Redis
            $chunkKey = "{$sessionKey}:chunk:{$this->index}";
            Redis::setex($chunkKey, 50400, json_encode([
                'index'          => $this->index,
                'start_time'     => $this->startTime,
                'end_time'       => $this->endTime,
                'slot_end'       => $this->slotEnd,
                'text'           => $this->text,
                'source_text'    => $this->sourceText,
                'speaker'        => $this->speaker,
                'audio_base64'   => $audioBase64,
                'audio_duration' => $ttsDuration,
            ]));

            // 5. Energy transfer — bg chunk tayyor bo'lsa shu yerda apply qilish
            // (yangi kinoda bg tez, TTS kech bo'ladi; shuning uchun TTS tarafdan ham chaqiramiz)
            $this->applyEnergyTransferIfBgReady();

            // 6. Re-generate bg-{i}.aac for any bg chunks that overlap this segment
            $freshJson = Redis::get($sessionKey);
            if ($freshJson) {
                $fresh    = json_decode($freshJson, true);
                $bgChunks = $fresh['bg_chunks'] ?? [];
                foreach ($bgChunks as $bgIdx => $bgChunk) {
                    $cs   = (float) ($bgChunk['start'] ?? 0);
                    $ce   = (float) ($bgChunk['end'] ?? 0);
                    $path = $bgChunk['path'] ?? null;
                    if ($path && file_exists($path) && $this->startTime < $ce && $this->endTime > $cs) {
                        $job = new DownloadAudioChunkJob($this->sessionId, (int) $bgIdx, $cs, $ce);
                        $job->generateBgChunkAac($path);
                    }
                }
            }

            // 5. Increment ready counter and dispatch cache persist on completion
            $this->incrementReady();
            $this->dispatchPersistIfComplete($sessionKey);

            Log::info("[DUB] [{$title}] Segment #{$this->index} ready ({$this->speaker}, " . round($ttsDuration, 2) . "s): " . Str::limit($this->text, 60), [
                'session' => $this->sessionId,
            ]);

        } catch (\Throwable $e) {
            Log::error("[DUB] [{$title}] Segment #{$this->index} FAILED: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);

            // Store error chunk so polling doesn't stall
            $chunkKey = "{$sessionKey}:chunk:{$this->index}";
            Redis::setex($chunkKey, 50400, json_encode([
                'index' => $this->index,
                'start_time' => $this->startTime,
                'end_time' => $this->endTime,
                'text' => $this->text,
                'speaker' => $this->speaker,
                'audio_base64' => null,
                'audio_duration' => 0,
                'error' => $e->getMessage(),
            ]));

            $this->incrementReady();
        }
    }

    public function failed(\Throwable $exception): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";

        $chunkKey = "{$sessionKey}:chunk:{$this->index}";
        if (!Redis::exists($chunkKey)) {
            Redis::setex($chunkKey, 50400, json_encode([
                'index'          => $this->index,
                'start_time'     => $this->startTime,
                'end_time'       => $this->endTime,
                'text'           => $this->text,
                'speaker'        => $this->speaker,
                'audio_base64'   => null,
                'audio_duration' => 0,
                'error'          => 'Job killed: ' . Str::limit($exception->getMessage(), 100),
            ]));
        }

        $this->incrementReady();

        Log::error("[DUB] Segment #{$this->index} killed by worker: " . Str::limit($exception->getMessage(), 100), [
            'session' => $this->sessionId,
        ]);
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

        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;
        $session  = json_decode($sessionJson, true);
        if ($session['disable_prosody'] ?? false) return;
        $bgChunks = $session['bg_chunks'] ?? [];

        $bgPath = null; $bgStart = null; $bgEnd = null;
        foreach ($bgChunks as $bgChunk) {
            $cs   = (float) ($bgChunk['start'] ?? 0);
            $ce   = (float) ($bgChunk['end'] ?? 0);
            $path = $bgChunk['path'] ?? null;
            if ($path && file_exists($path) && $this->startTime >= $cs && $this->startTime < $ce) {
                $bgPath = $path; $bgStart = $cs; $bgEnd = $ce;
                break;
            }
        }
        if (!$bgPath) return;

        $chunkKey  = "{$sessionKey}:chunk:{$this->index}";
        $chunkJson = Redis::get($chunkKey);
        if (!$chunkJson) return;
        $chunk = json_decode($chunkJson, true);
        $b64   = $chunk['audio_base64'] ?? null;
        if (!$b64) return;

        $tmpDir = '/tmp/instant-dub-' . $this->sessionId;
        @mkdir($tmpDir, 0755, true);

        try {
            $tmpMp3 = "{$tmpDir}/et_{$this->index}.mp3";
            file_put_contents($tmpMp3, base64_decode($b64));

            $ttsWav = "{$tmpDir}/et_{$this->index}_tts.wav";
            $conv = Process::timeout(10)->run([
                'ffmpeg', '-y', '-i', $tmpMp3, '-ar', '44100', '-ac', '1', '-f', 'wav', $ttsWav,
            ]);
            @unlink($tmpMp3);
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

            $chunk['audio_base64'] = base64_encode(file_get_contents($outMp3));
            @unlink($outMp3);
            Redis::setex($chunkKey, 50400, json_encode($chunk));

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

    private function applyProsodyTransfer(string $ttsWav, string $tmpDir): ?string
    {
        $serviceUrl = config('services.prosody_transfer.url') ?: env('PROSODY_TRANSFER_SERVICE_URL');
        if (!$serviceUrl) return null;

        // Segment vaqtini qamrab oluvchi bg_chunk ni topish
        $sessionJson = Redis::get("instant-dub:{$this->sessionId}");
        if (!$sessionJson) return null;
        $session  = json_decode($sessionJson, true);
        if ($session['disable_prosody'] ?? false) return null;
        $bgChunks = $session['bg_chunks'] ?? [];

        $vocalsPath = null;
        $chunkStart = null;
        foreach ($bgChunks as $bgChunk) {
            $cs = (float) ($bgChunk['start'] ?? 0);
            $ce = (float) ($bgChunk['end'] ?? 0);
            $vp = $bgChunk['vocals_path'] ?? null;
            if ($vp && file_exists($vp) && $this->startTime < $ce && $this->endTime > $cs) {
                $vocalsPath = $vp;
                $chunkStart = $cs;
                break;
            }
        }
        if (!$vocalsPath) return null;

        // Vocals faylidan segment vaqtini kesish
        $segOffset = max(0.0, $this->startTime - $chunkStart);
        $segDur    = $this->endTime - $this->startTime;
        $refClip   = "{$tmpDir}/ref_{$this->index}.wav";

        $cut = Process::timeout(10)->run([
            'ffmpeg', '-y',
            '-ss', (string) round($segOffset, 3),
            '-t',  (string) round($segDur, 3),
            '-i',  $vocalsPath,
            $refClip,
        ]);
        if (!$cut->successful() || !file_exists($refClip) || filesize($refClip) < 100) {
            return null;
        }

        try {
            $outWav = "{$tmpDir}/seg_{$this->index}_prosody.wav";
            $resp = \Illuminate\Support\Facades\Http::timeout(30)
                ->attach('tts_audio',  file_get_contents($ttsWav),  'tts.wav')
                ->attach('reference',  file_get_contents($refClip), 'ref.wav')
                ->post("{$serviceUrl}/transfer", [
                    'f0_mode'         => 'stats',
                    'energy_transfer' => 'true',
                ]);

            @unlink($refClip);

            if ($resp->failed()) return null;

            file_put_contents($outWav, $resp->body());
            if (!file_exists($outWav) || filesize($outWav) < 100) return null;

            Log::info("[DUB] Prosody transfer ok — seg #{$this->index}", ['session' => $this->sessionId]);
            return $outWav;

        } catch (\Throwable $e) {
            @unlink($refClip);
            Log::warning("[DUB] Prosody transfer failed — seg #{$this->index}: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
            return null;
        }
    }

    private function generateWithMms(string $outputMp3, string $tmpDir, array $speakerEntry = []): void
    {
        $client = new MmsTtsClient();

        // Force voice: direct MMS service voice ID — skip local pool lookup
        if (!empty($speakerEntry['mms_voice_id'])) {
            $voiceId = $speakerEntry['mms_voice_id'];
            $speed   = $speakerEntry['speed'] ?? 1.0;
            $tau     = $speakerEntry['tau']   ?? 0.8;
        } else {
            $gender = $speakerEntry['gender']
                ?? (str_starts_with($this->speaker, 'F') ? 'female'
                    : (str_starts_with($this->speaker, 'C') ? 'child' : 'male'));
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
        }

        $text = TextNormalizer::normalize($this->text, $this->language);
        // MMS model vocabulary is ASCII+basic-Latin — replace U+02BB/02BC (Uzbek apostrophe)
        // with plain ASCII apostrophe to avoid torch embedding crash on unknown tokens
        $text = str_replace(["\u{02BB}", "\u{02BC}", "\u{02B0}"], "'", $text);
        $wavData = $client->synthesize($voiceId, $text, [
            'language' => $this->language,
            'speed'    => $speed,
            'tau'      => $tau,
        ]);

        $tmpWav = "{$tmpDir}/seg_{$this->index}_mms.wav";
        file_put_contents($tmpWav, $wavData);

        // Prosody transfer: referens vocals topilsa, F0+energy ko'chirish
        $transferredWav = $this->applyProsodyTransfer($tmpWav, $tmpDir);
        $sourceWav = $transferredWav ?? $tmpWav;

        $result = Process::timeout(15)->run([
            'ffmpeg', '-y', '-i', $sourceWav,
            '-codec:a', 'libmp3lame', '-b:a', '128k',
            $outputMp3,
        ]);
        @unlink($tmpWav);
        if ($transferredWav) @unlink($transferredWav);

        if (!$result->successful() || !file_exists($outputMp3) || filesize($outputMp3) < 200) {
            throw new \RuntimeException('MMS WAV→MP3 conversion failed');
        }
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

    /**
     * Compute frame-aligned duration from absolute positions.
     * Uses cumulative frame counting so rounding errors don't accumulate
     * across segments (max drift: 23ms at any point, bounded).
     */
    private function frameAlignedDuration(float $start, float $end): float
    {
        $startFrames = (int) round($start * 44100 / 1024);
        $endFrames = (int) round($end * 44100 / 1024);
        return max(1, $endFrames - $startFrames) * 1024 / 44100;
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

    private function dispatchPersistIfComplete(string $sessionKey): void
    {
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;
        $s = json_decode($sessionJson, true);
        if (($s['status'] ?? '') === 'complete') {
            PersistDubCacheJob::dispatch($this->sessionId)->onQueue('default');
        }
    }

    private function incrementReady(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";

        // Atomic increment + completion check via Lua script
        // Avoids race condition where concurrent jobs read same counter
        $lua = <<<'LUA'
            local data = redis.call('GET', KEYS[1])
            if not data then return 0 end
            local session = cjson.decode(data)
            session['segments_ready'] = (session['segments_ready'] or 0) + 1
            session['last_progress_at'] = tonumber(ARGV[1])
            local total = session['total_segments'] or 999999
            if session['segments_ready'] >= total then
                session['status'] = 'complete'
                session['playable'] = true
            elseif not session['playable'] and session['segments_ready'] >= math.min(math.ceil(total * 0.1), 30) then
                session['playable'] = true
            end
            redis.call('SETEX', KEYS[1], 50400, cjson.encode(session))
            return session['segments_ready']
        LUA;

        Redis::eval($lua, 1, $sessionKey, now()->timestamp);
    }
}
