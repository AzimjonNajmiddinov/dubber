<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Http\Controllers\AdminVoicePoolController;
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

            // 4. Pre-generate AAC for HLS
            $bgAudioPath = $this->findBgChunkForTime($session, $this->startTime);
            $hasBg = $bgAudioPath !== null;

            if ($hasBg) {
                $session['original_audio_path'] = $bgAudioPath;
                $this->generateHlsAac($session, $finalMp3, $ttsDuration);
            } else {
                $this->generateTtsOnlyAac($finalMp3);
            }

            // 4b. Pre-generate lead-in segment
            if ($this->index === 0 && $this->startTime > 1.0) {
                if ($hasBg) {
                    $session['original_audio_path'] = $bgAudioPath;
                    $this->generateLeadAac($session);
                }
            }

            // 4c. Pre-generate trailing silent segment for last segment
            if ($this->slotEnd === null) {
                $this->generateTailAac($session);
            }

            @unlink($finalMp3);

            // 5. Probe actual AAC duration, then save chunk to Redis
            // IMPORTANT: chunk must be saved AFTER AAC is generated so aac_duration
            // is available from the first playlist reload. Otherwise EXTINF changes
            // between reloads which violates EVENT playlist rules and crashes AVPlayer.
            $aacDur = 0.0;
            $aacFile = storage_path("app/instant-dub/{$this->sessionId}/aac/{$this->index}.aac");
            if (file_exists($aacFile)) {
                $aacDur = round($this->getAudioDuration($aacFile), 3);
            }

            $chunkKey = "{$sessionKey}:chunk:{$this->index}";
            Redis::setex($chunkKey, 50400, json_encode([
                'index' => $this->index,
                'start_time' => $this->startTime,
                'end_time' => $this->endTime,
                'slot_end' => $this->slotEnd,
                'text' => $this->text,
                'source_text' => $this->sourceText,
                'speaker' => $this->speaker,
                'audio_base64' => $audioBase64,
                'audio_duration' => $ttsDuration,
                'aac_duration' => $aacDur > 0 ? $aacDur : null,
            ]));

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

            // Generate background-only AAC so HLS has no gaps
            $this->generateBackgroundOnlyAac($session);

            $this->incrementReady();
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Queue worker killed the job (timeout/max attempts) — bypassed our try/catch.
        // Still increment ready counter so the session can reach "complete".
        $sessionKey = "instant-dub:{$this->sessionId}";

        $chunkKey = "{$sessionKey}:chunk:{$this->index}";
        if (!Redis::exists($chunkKey)) {
            Redis::setex($chunkKey, 50400, json_encode([
                'index' => $this->index,
                'start_time' => $this->startTime,
                'end_time' => $this->endTime,
                'text' => $this->text,
                'speaker' => $this->speaker,
                'audio_base64' => null,
                'audio_duration' => 0,
                'error' => 'Job killed: ' . Str::limit($exception->getMessage(), 100),
            ]));
        }

        $sessionJson = Redis::get($sessionKey);
        if ($sessionJson) {
            $session = json_decode($sessionJson, true);
            $this->generateBackgroundOnlyAac($session);
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

    private function generateWithMms(string $outputMp3, string $tmpDir, array $speakerEntry = []): void
    {
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

        $client = new MmsTtsClient();
        if (!$voiceId) {
            $name    = pathinfo($voiceFile, PATHINFO_FILENAME);
            $voiceId = $client->addVoice("pool-{$name}", [$voiceFile]);
            \Illuminate\Support\Facades\Redis::setex($cacheKey, 604800, $voiceId);
        }

        $name  = pathinfo($voiceFile, PATHINFO_FILENAME);
        $speed = AdminVoicePoolController::getSpeed($gender, $name);
        $tau   = AdminVoicePoolController::getTau($gender, $name);

        $text    = TextNormalizer::normalize($this->text, $this->language);
        $wavData = $client->synthesize($voiceId, $text, [
            'language' => $this->language,
            'speed'    => $speed,
            'tau'      => $tau,
        ]);

        $tmpWav = "{$tmpDir}/seg_{$this->index}_mms.wav";
        file_put_contents($tmpWav, $wavData);

        $result = Process::timeout(15)->run([
            'ffmpeg', '-y', '-i', $tmpWav,
            '-codec:a', 'libmp3lame', '-b:a', '128k',
            $outputMp3,
        ]);
        @unlink($tmpWav);

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

    private function generateBackgroundOnlyAac(array $session): void
    {
        $bgAudioPath = $this->findBgChunkForTime($session, $this->startTime);
        $hasBg = $bgAudioPath !== null;
        $bgChunkStart = $hasBg ? $this->findBgChunkStart($session, $this->startTime) : 0;
        $seekInBg = max(0, $this->startTime - $bgChunkStart);

        $slotStart = $this->startTime;
        // Frame-aligned duration from absolute positions (prevents cumulative drift)
        $slotEnd = $this->slotEnd ?? $this->endTime;
        $slotDuration = round($this->frameAlignedDuration($slotStart, $slotEnd), 6);

        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");
        $aacFile = "{$aacDir}/{$this->index}.aac";

        if (!is_dir($aacDir)) {
            @mkdir($aacDir, 0755, true);
        }

        $timeout = max(30, (int) ceil($slotDuration) + 30);
        try {
            if ($hasBg) {
                Process::timeout($timeout)->run([
                    'ffmpeg', '-y',
                    '-f', 'lavfi', '-t', (string) $slotDuration, '-i', 'anullsrc=r=44100:cl=mono',
                    '-ss', (string) round($seekInBg, 3), '-t', (string) $slotDuration, '-i', $bgAudioPath,
                    '-filter_complex', '[1:a]volume=0.2[bg];[0:a][bg]amix=inputs=2:duration=first:normalize=0',
                    '-ac', '1', '-ar', '44100', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $aacFile,
                ]);
            } else {
                Process::timeout($timeout)->run([
                    'ffmpeg', '-y', '-f', 'lavfi', '-t', (string) $slotDuration,
                    '-i', 'anullsrc=r=44100:cl=mono',
                    '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $aacFile,
                ]);
            }
        } catch (\Throwable) {
            // Best effort
        }
    }

    /**
     * Find a background audio chunk file that covers the given time.
     */
    private function findBgChunkForTime(array $session, float $time): ?string
    {
        $bgChunks = $session['bg_chunks'] ?? [];
        foreach ($bgChunks as $chunk) {
            $path = $chunk['path'] ?? null;
            $start = (float) ($chunk['start'] ?? 0);
            $end = (float) ($chunk['end'] ?? 0);
            if ($path && file_exists($path) && $time >= $start && $time < $end) {
                return $path;
            }
        }
        return null;
    }

    private function findBgChunkStart(array $session, float $time): float
    {
        $bgChunks = $session['bg_chunks'] ?? [];
        foreach ($bgChunks as $chunk) {
            $start = (float) ($chunk['start'] ?? 0);
            $end = (float) ($chunk['end'] ?? 0);
            if ($time >= $start && $time < $end) {
                return $start;
            }
        }
        return 0;
    }

    private function generateTtsOnlyAac(string $ttsMp3): void
    {
        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");
        $aacFile = "{$aacDir}/{$this->index}.aac";
        if (!is_dir($aacDir)) @mkdir($aacDir, 0755, true);

        // Frame-aligned duration from absolute positions (prevents cumulative drift)
        $slotEnd = $this->slotEnd ?? $this->endTime;
        $speechDuration = round($this->frameAlignedDuration($this->startTime, $slotEnd), 6);

        try {
            $timeout = max(30, (int) ceil($speechDuration) + 30);
            Process::timeout($timeout)->run([
                'ffmpeg', '-y',
                '-f', 'lavfi', '-t', (string) $speechDuration, '-i', 'anullsrc=r=44100:cl=mono',
                '-i', $ttsMp3,
                '-filter_complex',
                "[1:a]aresample=44100[tts];[0:a][tts]amix=inputs=2:duration=first:normalize=0",
                '-ac', '1', '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
            ]);
        } catch (\Throwable $e) {
            Log::warning("[DUB] TTS-only AAC failed for segment #{$this->index}: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
        }
    }

    private function generateGapAac(): void
    {
        $slotEnd = $this->slotEnd;
        if ($slotEnd === null) return; // last segment, no gap

        $gapStart = $this->endTime;
        $gapDuration = round($slotEnd - $gapStart, 3);
        if ($gapDuration < 0.5) return; // too short, skip

        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");
        $gapFile = "{$aacDir}/gap-{$this->index}.aac";

        try {
            Process::timeout(15)->run([
                'ffmpeg', '-y', '-f', 'lavfi', '-t', (string) $gapDuration,
                '-i', 'anullsrc=r=44100:cl=mono',
                '-c:a', 'aac', '-b:a', '32k', '-f', 'adts', $gapFile,
            ]);
        } catch (\Throwable $e) {
            Log::warning("[DUB] Gap AAC failed for segment #{$this->index}: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
        }
    }

    private function generateHlsAac(array $session, string $ttsMp3, float $ttsDuration): void
    {
        $originalAudioPath = $session['original_audio_path'] ?? null;
        $hasBg = $originalAudioPath && file_exists($originalAudioPath);

        $bgChunkStart = $this->findBgChunkStart($session, $this->startTime);
        $seekInBg = max(0, $this->startTime - $bgChunkStart);

        // Frame-aligned duration from absolute positions (prevents cumulative drift)
        $slotEnd = $this->slotEnd ?? $this->endTime;
        $slotDuration = round($this->frameAlignedDuration($this->startTime, $slotEnd), 6);

        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");
        $aacFile = "{$aacDir}/{$this->index}.aac";

        if (!is_dir($aacDir)) {
            @mkdir($aacDir, 0755, true);
        }

        try {
            if ($hasBg) {
                // anullsrc with frame-aligned duration: exact frame count, no cumulative drift
                $result = Process::timeout(20)->run([
                    'ffmpeg', '-y',
                    '-f', 'lavfi', '-t', (string) $slotDuration, '-i', 'anullsrc=r=44100:cl=mono',
                    '-ss', (string) round($seekInBg, 3), '-t', (string) $slotDuration, '-i', $originalAudioPath,
                    '-i', $ttsMp3,
                    '-filter_complex',
                    "[1:a]volume=0.2,aresample=44100[bg];[2:a]aresample=44100[tts];[0:a][bg][tts]amix=inputs=3:duration=first:normalize=0",
                    '-ac', '1', '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
                ]);
                if (!$result->successful() || !file_exists($aacFile) || filesize($aacFile) < 100) {
                    $this->generateTtsOnlyAac($ttsMp3);
                }
            } else {
                $this->generateTtsOnlyAac($ttsMp3);
            }
        } catch (\Throwable $e) {
            Log::warning("[DUB] Segment #{$this->index} HLS AAC failed: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
        }
    }

    private function generateGapAacWithBg(array $session): void
    {
        $slotEnd = $this->slotEnd;
        if ($slotEnd === null) return;

        $gapStart = $this->endTime;
        $gapDuration = round($slotEnd - $gapStart, 3);
        if ($gapDuration < 0.5) return;

        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");
        $gapFile = "{$aacDir}/gap-{$this->index}.aac";

        $originalAudioPath = $session['original_audio_path'] ?? null;
        $hasBg = $originalAudioPath && file_exists($originalAudioPath);

        // Seek relative to chunk start, not absolute video time
        $bgChunkStart = $this->findBgChunkStart($session, $gapStart);
        $seekInBg = max(0, $gapStart - $bgChunkStart);

        try {
            if ($hasBg) {
                // Use anullsrc base to guarantee exact duration in ADTS
                Process::timeout(20)->run([
                    'ffmpeg', '-y',
                    '-f', 'lavfi', '-t', (string) $gapDuration, '-i', 'anullsrc=r=44100:cl=mono',
                    '-ss', (string) round($seekInBg, 3), '-t', (string) $gapDuration, '-i', $originalAudioPath,
                    '-filter_complex',
                    "[1:a]volume=0.2[bg];[0:a][bg]amix=inputs=2:duration=first:normalize=0",
                    '-ac', '1', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $gapFile,
                ]);
            } else {
                Process::timeout(15)->run([
                    'ffmpeg', '-y', '-f', 'lavfi', '-t', (string) $gapDuration,
                    '-i', 'anullsrc=r=44100:cl=mono',
                    '-c:a', 'aac', '-b:a', '32k', '-f', 'adts', $gapFile,
                ]);
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    private function generateTailAac(array $session): void
    {
        // Re-read session to get video_duration (may have been set by DownloadOriginalAudioJob)
        $sessionJson = Redis::get("instant-dub:{$this->sessionId}");
        if ($sessionJson) {
            $session = json_decode($sessionJson, true);
        }

        $videoDuration = (float) ($session['video_duration'] ?? 0);
        $tailStart = $this->endTime;

        // If we don't know video duration, add 3 minutes of padding
        $tailEnd = $videoDuration > $tailStart ? $videoDuration : $tailStart + 180;
        $tailDuration = round($this->frameAlignedDuration($tailStart, $tailEnd), 6);

        if ($tailDuration < 5) return; // No significant tail needed

        $bgAudioPath = $this->findBgChunkForTime($session, $tailStart);
        $hasBg = $bgAudioPath !== null;
        $bgChunkStart = $hasBg ? $this->findBgChunkStart($session, $tailStart) : 0;
        $seekInBg = max(0, $tailStart - $bgChunkStart);

        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");
        $aacFile = "{$aacDir}/tail.aac";

        if (!is_dir($aacDir)) {
            @mkdir($aacDir, 0755, true);
        }

        $timeout = max(60, (int) ceil($tailDuration) + 30);
        try {
            if ($hasBg) {
                Process::timeout($timeout)->run([
                    'ffmpeg', '-y',
                    '-f', 'lavfi', '-t', (string) $tailDuration, '-i', 'anullsrc=r=44100:cl=mono',
                    '-ss', (string) round($seekInBg, 3), '-t', (string) $tailDuration, '-i', $bgAudioPath,
                    '-filter_complex',
                    "[1:a]volume=0.2[bg];[0:a][bg]amix=inputs=2:duration=first:normalize=0",
                    '-ac', '1', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $aacFile,
                ]);
            } else {
                Process::timeout($timeout)->run([
                    'ffmpeg', '-y', '-f', 'lavfi', '-t', (string) $tailDuration,
                    '-i', 'anullsrc=r=44100:cl=mono',
                    '-c:a', 'aac', '-b:a', '32k', '-f', 'adts', $aacFile,
                ]);
            }

            if (file_exists($aacFile) && filesize($aacFile) > 100) {
                // Store tail metadata in session for playlist generation
                $sessionJson = Redis::get("instant-dub:{$this->sessionId}");
                if ($sessionJson) {
                    $s = json_decode($sessionJson, true);
                    $s['tail_start'] = $tailStart;
                    $s['tail_duration'] = $tailDuration;
                    Redis::setex("instant-dub:{$this->sessionId}", 50400, json_encode($s));
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[DUB] Tail AAC generation failed: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
        }
    }

    private function generateLeadAac(array $session): void
    {
        $originalAudioPath = $session['original_audio_path'] ?? null;
        $hasBg = $originalAudioPath && file_exists($originalAudioPath);
        $duration = round($this->frameAlignedDuration(0, $this->startTime), 6);

        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");
        $aacFile = "{$aacDir}/lead.aac";

        if (file_exists($aacFile)) return;

        if (!is_dir($aacDir)) {
            @mkdir($aacDir, 0755, true);
        }

        $timeout = max(30, (int) ceil($duration) + 30);
        try {
            if ($hasBg) {
                Process::timeout($timeout)->run([
                    'ffmpeg', '-y',
                    '-f', 'lavfi', '-t', (string) $duration, '-i', 'anullsrc=r=44100:cl=mono',
                    '-ss', '0', '-t', (string) $duration, '-i', $originalAudioPath,
                    '-filter_complex',
                    "[1:a]volume=0.2[bg];[0:a][bg]amix=inputs=2:duration=first:normalize=0",
                    '-ac', '1', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $aacFile,
                ]);
            } else {
                Process::timeout($timeout)->run([
                    'ffmpeg', '-y', '-f', 'lavfi', '-t', (string) $duration,
                    '-i', 'anullsrc=r=44100:cl=mono',
                    '-c:a', 'aac', '-b:a', '32k', '-f', 'adts', $aacFile,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("[DUB] Lead-in AAC generation failed: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
        }
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
            local hasBg = session['bg_chunks'] ~= nil and next(session['bg_chunks']) ~= nil
            if session['segments_ready'] >= total then
                session['status'] = 'complete'
                if hasBg then session['playable'] = true end
            elseif not session['playable'] and session['segments_ready'] >= math.min(math.ceil(total * 0.1), 30) and hasBg then
                session['playable'] = true
            end
            redis.call('SETEX', KEYS[1], 50400, cjson.encode(session))
            return session['segments_ready']
        LUA;

        Redis::eval($lua, 1, $sessionKey, now()->timestamp);
    }
}
