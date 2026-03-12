<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\ElevenLabs\ElevenLabsClient;
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
    public int $tries = 2; // Allow 1 retry (covers horizon restart killing in-flight jobs)

    public function __construct(
        public string $sessionId,
        public int    $index,
        public string $text,
        public float  $startTime,
        public float  $endTime,
        public string $language,
        public string $speaker = 'M1',
        public ?float $slotEnd = null,
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

            // 1. Generate TTS audio with configured driver
            $tmpDir = '/tmp/instant-dub-' . $this->sessionId;
            @mkdir($tmpDir, 0755, true);

            $rawMp3 = "{$tmpDir}/seg_{$this->index}.mp3";

            // Read per-speaker driver from voice map
            $voiceKey = "instant-dub:{$this->sessionId}:voices";
            $voiceMap = json_decode(Redis::get($voiceKey), true) ?? [];
            $speakerEntry = $voiceMap[$this->speaker] ?? [];
            $driver = $speakerEntry['driver'] ?? config('dubber.tts.default', 'edge');

            if ($driver === 'elevenlabs' && !empty($speakerEntry['voice_id'])) {
                $this->generateWithElevenLabs($rawMp3, $speakerEntry['voice_id']);
            } elseif ($driver === 'aisha') {
                $this->generateWithAisha($rawMp3, $speakerEntry);
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
                    // Too long — speed up (cap 1.15x to avoid robotic sound)
                    $tempo = min($ratio, 1.15);
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

            // 3. Encode to base64 and store in Redis
            $audioBase64 = base64_encode(file_get_contents($finalMp3));

            $chunkKey = "{$sessionKey}:chunk:{$this->index}";
            Redis::setex($chunkKey, 50400, json_encode([
                'index' => $this->index,
                'start_time' => $this->startTime,
                'end_time' => $this->endTime,
                'text' => $this->text,
                'speaker' => $this->speaker,
                'audio_base64' => $audioBase64,
                'audio_duration' => $ttsDuration,
            ]));

            // 4. Pre-generate AAC with background audio for HLS
            $this->generateHlsAac($session, $finalMp3, $ttsDuration);
            @unlink($finalMp3);

            // 5. Increment ready counter
            $this->incrementReady();

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

        // Try up to 3 times: original voice, then default voice, then en-US fallback
        $attempts = [
            ['voice' => $voice, 'pitch' => $pitch, 'rate' => $rate],
            ['voice' => $voice, 'pitch' => '+0Hz', 'rate' => '+0%'],
            ['voice' => $this->getDefaultEdgeVoice(), 'pitch' => '+0Hz', 'rate' => '+0%'],
        ];

        // Deduplicate attempts
        $seen = [];
        $uniqueAttempts = [];
        foreach ($attempts as $a) {
            $key = "{$a['voice']}|{$a['pitch']}|{$a['rate']}";
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueAttempts[] = $a;
            }
        }

        $lastError = '';
        foreach ($uniqueAttempts as $i => $attempt) {
            if ($i > 0) {
                usleep(500000); // 500ms delay between retries
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

    private function generateBackgroundOnlyAac(array $session): void
    {
        $originalAudioPath = $session['original_audio_path'] ?? null;
        $hasBg = $originalAudioPath && file_exists($originalAudioPath);

        $slotStart = $this->index === 0 ? 0.0 : $this->startTime;
        $slotEnd = $this->slotEnd ?? $this->endTime;
        $slotDuration = round(max(0.1, $slotEnd - $slotStart), 3);

        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");
        $aacFile = "{$aacDir}/{$this->index}.aac";

        if (!is_dir($aacDir)) {
            @mkdir($aacDir, 0755, true);
        }

        try {
            if ($hasBg) {
                Process::timeout(15)->run([
                    'ffmpeg', '-y',
                    '-ss', (string) round($slotStart, 3),
                    '-t', (string) $slotDuration,
                    '-i', $originalAudioPath,
                    '-af', 'volume=0.2',
                    '-ac', '1', '-ar', '44100', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $aacFile,
                ]);
            } else {
                Process::timeout(10)->run([
                    'ffmpeg', '-y', '-f', 'lavfi', '-t', (string) $slotDuration,
                    '-i', 'anullsrc=r=44100:cl=mono',
                    '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $aacFile,
                ]);
            }
        } catch (\Throwable) {
            // Best effort
        }
    }

    private function generateHlsAac(array $session, string $ttsMp3, float $ttsDuration): void
    {
        $originalAudioPath = $session['original_audio_path'] ?? null;
        $hasBg = $originalAudioPath && file_exists($originalAudioPath);

        // Compute slot bounds: segment absorbs surrounding silence until next segment starts
        $slotStart = $this->index === 0 ? 0.0 : $this->startTime;
        $slotEnd = $this->slotEnd ?? $this->endTime;

        $slotDuration = round(max(0.1, $slotEnd - $slotStart), 3);
        $preGap = max(0, $this->startTime - $slotStart);
        $preGapMs = (int) round($preGap * 1000);

        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");
        $aacFile = "{$aacDir}/{$this->index}.aac";

        if (!is_dir($aacDir)) {
            @mkdir($aacDir, 0755, true);
        }

        try {
            $delayFilter = $preGapMs > 0 ? "adelay={$preGapMs}|{$preGapMs}," : '';
            $mixed = false;

            if ($hasBg) {
                $result = Process::timeout(20)->run([
                    'ffmpeg', '-y',
                    '-i', $ttsMp3,
                    '-ss', (string) round($slotStart, 3),
                    '-t', (string) $slotDuration,
                    '-i', $originalAudioPath,
                    '-filter_complex',
                    "[0:a]aresample=44100,volume=0.7,{$delayFilter}apad=whole_dur={$slotDuration}[tts];[1:a]volume=0.2[bg];[tts][bg]amix=inputs=2:duration=first:normalize=0",
                    '-t', (string) $slotDuration,
                    '-ac', '1', '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
                ]);
                $mixed = $result->successful() && file_exists($aacFile) && filesize($aacFile) > 100;
                if (!$mixed) {
                    Log::warning("[DUB] Segment #{$this->index} bg mix failed, falling back to TTS-only", [
                        'session' => $this->sessionId,
                        'error' => Str::limit($result->errorOutput(), 200),
                    ]);
                }
            }

            if (!$mixed) {
                Process::timeout(15)->run([
                    'ffmpeg', '-y', '-i', $ttsMp3,
                    '-af', "aresample=44100,{$delayFilter}apad=whole_dur={$slotDuration}",
                    '-t', (string) $slotDuration,
                    '-ac', '1', '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("[DUB] Segment #{$this->index} HLS AAC pre-generation failed: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
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
            local total = session['total_segments'] or 999999
            if session['segments_ready'] >= total then
                session['status'] = 'complete'
                session['playable'] = true
            elseif not session['playable'] and session['segments_ready'] >= math.min(3, total) then
                session['playable'] = true
            end
            redis.call('SETEX', KEYS[1], 50400, cjson.encode(session))
            return session['segments_ready']
        LUA;

        Redis::eval($lua, 1, $sessionKey);
    }
}
