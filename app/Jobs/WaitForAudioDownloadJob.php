<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class WaitForAudioDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    private const BG_FILTER = 'volume=0.2';

    public function __construct(
        public string $sessionId,
    ) {}

    public function handle(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;

        $session = json_decode($sessionJson, true);
        if (($session['status'] ?? '') === 'stopped') return;

        $title = $session['title'] ?? 'Untitled';
        $tmpDir = storage_path("app/instant-dub/{$this->sessionId}");
        $outputPath = "{$tmpDir}/original_audio.aac";
        $donePath = "{$tmpDir}/audio_download.done";

        // Check if download is done
        if (!file_exists($donePath)) {
            // Still downloading — re-dispatch to check again in 15s
            // Give up after 30 minutes (120 polls × 15s)
            $pollCount = (int) Redis::get("instant-dub:{$this->sessionId}:audio-poll-count");
            if ($pollCount >= 120) {
                Log::warning("[DUB] [{$title}] Audio download timed out after 30 minutes", [
                    'session' => $this->sessionId,
                ]);
                Redis::del("instant-dub:{$this->sessionId}:audio-poll-count");
                return;
            }

            Redis::setex("instant-dub:{$this->sessionId}:audio-poll-count", 3600, $pollCount + 1);

            self::dispatch($this->sessionId)
                ->delay(now()->addSeconds(15))
                ->onQueue('default');
            return;
        }

        // Download done — clean up poll counter and playlist
        Redis::del("instant-dub:{$this->sessionId}:audio-poll-count");
        @unlink("{$tmpDir}/audio_playlist.m3u8");
        @unlink($donePath);

        // Verify the file
        if (!file_exists($outputPath) || filesize($outputPath) < 1000) {
            Log::warning("[DUB] [{$title}] Audio download produced empty/invalid file", [
                'session' => $this->sessionId,
            ]);
            return;
        }

        // Get audio duration
        $probe = Process::timeout(10)->run([
            'ffprobe', '-hide_banner', '-loglevel', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1',
            $outputPath,
        ]);

        $audioDuration = $probe->successful() ? (float) trim($probe->output()) : 0;

        // Update session with audio path and duration
        $sessionJson = Redis::get($sessionKey);
        if ($sessionJson) {
            $session = json_decode($sessionJson, true);
            $session['original_audio_path'] = $outputPath;
            if ($audioDuration > 0) {
                $session['video_duration'] = $audioDuration;
            }
            Redis::setex($sessionKey, 50400, json_encode($session));
        }

        Log::info("[DUB] [{$title}] Original audio downloaded (" . round(filesize($outputPath) / 1024) . " KB, " . round($audioDuration) . "s)", [
            'session' => $this->sessionId,
        ]);

        // Remix all segments with background audio
        $this->remixAllSegments($outputPath);

        Log::info("[DUB] [{$title}] Original audio ready, segments remixed", [
            'session' => $this->sessionId,
        ]);
    }

    private function remixAllSegments(string $originalAudioPath): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");

        if (!is_dir($aacDir)) return;

        $bgFilter = self::BG_FILTER;

        try {
            // Regenerate lead.aac with background audio
            $leadFile = "{$aacDir}/lead.aac";
            if (file_exists($leadFile)) {
                $chunk0Json = Redis::get("{$sessionKey}:chunk:0");
                if ($chunk0Json) {
                    $chunk0 = json_decode($chunk0Json, true);
                    $firstStart = (float) ($chunk0['start_time'] ?? 0);
                    if ($firstStart > 1.0) {
                        $leadDur = round($firstStart, 3);
                        $leadTimeout = max(30, (int) ceil($leadDur) + 30);
                        Process::timeout($leadTimeout)->run([
                            'ffmpeg', '-y',
                            '-f', 'lavfi', '-t', (string) $leadDur, '-i', 'anullsrc=r=44100:cl=mono',
                            '-ss', '0', '-t', (string) $leadDur, '-i', $originalAudioPath,
                            '-filter_complex', "[1:a]{$bgFilter}[bg];[0:a][bg]amix=inputs=2:duration=first:normalize=0",
                            '-ac', '1', '-ar', '44100', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $leadFile,
                        ]);
                        Log::info("[DUB] Remixed lead.aac with background audio ({$firstStart}s)", [
                            'session' => $this->sessionId,
                        ]);
                    }
                }
            }

            // Remix all segments
            $sessionJson = Redis::get($sessionKey);
            if (!$sessionJson) return;
            $session = json_decode($sessionJson, true);
            $total = (int) ($session['total_segments'] ?? 0);

            $remixed = 0;
            for ($i = 0; $i < $total; $i++) {
                $aacFile = "{$aacDir}/{$i}.aac";
                if (!file_exists($aacFile)) continue;

                $chunkJson = Redis::get("{$sessionKey}:chunk:{$i}");
                if (!$chunkJson) continue;

                $chunk = json_decode($chunkJson, true);
                $audioBase64 = $chunk['audio_base64'] ?? null;
                if (!$audioBase64) continue;

                $startTime = (float) ($chunk['start_time'] ?? 0);
                $endTime = (float) ($chunk['end_time'] ?? 0);

                $nextChunkJson = Redis::get("{$sessionKey}:chunk:" . ($i + 1));
                $slotEnd = $nextChunkJson
                    ? (float) (json_decode($nextChunkJson, true)['start_time'] ?? $endTime)
                    : $endTime;

                $slotDuration = round(max(0.1, $slotEnd - $startTime), 3);

                $tmpMp3 = "/tmp/remix_{$this->sessionId}_{$i}.mp3";
                file_put_contents($tmpMp3, base64_decode($audioBase64));

                $result = Process::timeout(20)->run([
                    'ffmpeg', '-y',
                    '-i', $tmpMp3,
                    '-ss', (string) round($startTime, 3),
                    '-t', (string) $slotDuration,
                    '-i', $originalAudioPath,
                    '-filter_complex',
                    "[0:a]aresample=44100,apad=whole_dur={$slotDuration}[tts];[1:a]{$bgFilter}[bg];[tts][bg]amix=inputs=2:duration=first:normalize=0",
                    '-t', (string) $slotDuration,
                    '-ac', '1', '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
                ]);

                @unlink($tmpMp3);

                if ($result->successful()) {
                    $remixed++;
                } else {
                    Log::warning("[DUB] Remix segment #{$i} failed", [
                        'session' => $this->sessionId,
                        'error' => Str::limit($result->errorOutput(), 200),
                    ]);
                }
            }

            Log::info("[DUB] Remixed {$remixed}/{$total} segments with background audio", [
                'session' => $this->sessionId,
            ]);
        } catch (\Throwable $e) {
            Log::warning("[DUB] Remix failed: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning("[DUB] WaitForAudioDownloadJob failed", [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
