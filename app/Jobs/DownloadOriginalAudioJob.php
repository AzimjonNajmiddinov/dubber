<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class DownloadOriginalAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public string $sessionId,
        public string $videoUrl,
    ) {}

    public function handle(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;

        $session = json_decode($sessionJson, true);
        if (($session['status'] ?? '') === 'stopped') return;

        $title = $session['title'] ?? 'Untitled';

        $originalAudioPath = $this->downloadOriginalAudio();
        if ($originalAudioPath) {
            // Atomically update session with the audio path
            $sessionJson = Redis::get($sessionKey);
            if ($sessionJson) {
                $session = json_decode($sessionJson, true);
                $session['original_audio_path'] = $originalAudioPath;
                Redis::setex($sessionKey, 50400, json_encode($session));
            }
            // Regenerate lead.aac and early segments that were processed before audio was available
            $this->remixEarlySegments($originalAudioPath);

            Log::info("[DUB] [{$title}] Original audio ready, early segments remixed", [
                'session' => $this->sessionId,
            ]);
        } else {
            Log::info("[DUB] [{$title}] No original audio available, segments will be TTS-only", [
                'session' => $this->sessionId,
            ]);
        }

        // Clear audio_download_pending and set playable if enough segments are ready
        $this->markAudioReady();
    }

    private function markAudioReady(): void
    {
        $lua = <<<'LUA'
            local data = redis.call('GET', KEYS[1])
            if not data then return 0 end
            local session = cjson.decode(data)
            session['audio_download_pending'] = nil
            local ready = session['segments_ready'] or 0
            local total = session['total_segments'] or 999999
            if ready >= total then
                session['status'] = 'complete'
                session['playable'] = true
            elseif not session['playable'] and ready >= math.min(3, total) then
                session['playable'] = true
            end
            redis.call('SETEX', KEYS[1], 50400, cjson.encode(session))
            return ready
        LUA;

        Redis::eval($lua, 1, "instant-dub:{$this->sessionId}");
    }

    private function downloadOriginalAudio(): ?string
    {
        if (!str_contains($this->videoUrl, '.m3u8')) return null;

        try {
            $masterResp = Http::timeout(10)->get($this->videoUrl);
            if ($masterResp->failed()) return null;

            $master = $masterResp->body();
            $urlWithoutQuery = strtok($this->videoUrl, '?');
            $baseUrl = preg_replace('#/[^/]+$#', '/', $urlWithoutQuery);
            $query = parse_url($this->videoUrl, PHP_URL_QUERY) ?? '';

            // Find first audio track with URI
            preg_match_all('/^#EXT-X-MEDIA:.*TYPE=AUDIO.*$/m', $master, $audioLines);
            $audioUri = null;
            foreach ($audioLines[0] ?? [] as $line) {
                if (preg_match('/URI="([^"]+)"/', $line, $m)) {
                    $audioUri = $m[1];
                    break;
                }
            }

            if (!$audioUri) return null;

            // Resolve to absolute URL
            $audioPlaylistUrl = str_starts_with($audioUri, 'http') ? $audioUri : $baseUrl . $audioUri;
            if ($query) $audioPlaylistUrl .= (str_contains($audioPlaylistUrl, '?') ? '&' : '?') . $query;

            // Fetch audio playlist and rewrite segment URIs to absolute
            $audioResp = Http::timeout(10)->get($audioPlaylistUrl);
            if ($audioResp->failed()) return null;

            $audioPlaylist = $audioResp->body();
            $audioBase = preg_replace('#/[^/]+$#', '/', strtok($audioPlaylistUrl, '?'));

            $rewritten = '';
            foreach (explode("\n", $audioPlaylist) as $pLine) {
                $trimmed = trim($pLine);
                if ($trimmed !== '' && !str_starts_with($trimmed, '#')) {
                    if (!str_starts_with($trimmed, 'http')) {
                        $trimmed = $audioBase . $trimmed;
                    }
                    if ($query && !str_contains($trimmed, '?')) {
                        $trimmed .= '?' . $query;
                    }
                    $rewritten .= $trimmed . "\n";
                } else {
                    $rewritten .= $pLine . "\n";
                }
            }

            // Save rewritten playlist and download via ffmpeg
            $tmpDir = storage_path("app/instant-dub/{$this->sessionId}");
            @mkdir($tmpDir, 0755, true);
            $localPlaylist = "{$tmpDir}/audio_playlist.m3u8";
            $outputPath = "{$tmpDir}/original_audio.aac";

            file_put_contents($localPlaylist, $rewritten);

            $result = Process::timeout(300)->run([
                'ffmpeg', '-y',
                '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
                '-i', $localPlaylist,
                '-vn', '-ac', '1', '-ar', '44100',
                '-c:a', 'aac', '-b:a', '96k',
                '-f', 'adts',
                $outputPath,
            ]);

            @unlink($localPlaylist);

            if ($result->successful() && file_exists($outputPath) && filesize($outputPath) > 1000) {
                $probe = Process::timeout(10)->run([
                    'ffprobe', '-hide_banner', '-loglevel', 'error',
                    '-show_entries', 'format=duration',
                    '-of', 'default=nw=1:nk=1',
                    $outputPath,
                ]);
                if ($probe->successful()) {
                    $audioDuration = (float) trim($probe->output());
                    // Store audio/video duration in session for trailing silence
                    if ($audioDuration > 0) {
                        $sJson = Redis::get("instant-dub:{$this->sessionId}");
                        if ($sJson) {
                            $s = json_decode($sJson, true);
                            $s['video_duration'] = $audioDuration;
                            Redis::setex("instant-dub:{$this->sessionId}", 50400, json_encode($s));
                        }
                    }
                    Log::info("[DUB] Original audio downloaded (" . round(filesize($outputPath) / 1024) . " KB, " . round($audioDuration) . "s)", [
                        'session' => $this->sessionId,
                    ]);
                    return $outputPath;
                }
                Log::warning("[DUB] Original audio file corrupt, deleting", [
                    'session' => $this->sessionId,
                    'error' => Str::limit($probe->errorOutput(), 200),
                ]);
                @unlink($outputPath);
            }

            Log::warning("[DUB] Original audio download failed", [
                'session' => $this->sessionId,
                'error' => Str::limit($result->errorOutput(), 300),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[DUB] Original audio download error", [
                'session' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Regenerate lead.aac and early segments that were created before original audio was available.
     */
    private function remixEarlySegments(string $originalAudioPath): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");

        if (!is_dir($aacDir)) return;

        try {
            // Regenerate lead.aac with background audio
            $leadFile = "{$aacDir}/lead.aac";
            if (file_exists($leadFile)) {
                // Get first segment start time from chunk 0
                $chunk0Json = Redis::get("{$sessionKey}:chunk:0");
                if ($chunk0Json) {
                    $chunk0 = json_decode($chunk0Json, true);
                    $firstStart = (float) ($chunk0['start_time'] ?? 0);
                    if ($firstStart > 1.0) {
                        Process::timeout(30)->run([
                            'ffmpeg', '-y',
                            '-ss', '0', '-t', (string) round($firstStart, 3),
                            '-i', $originalAudioPath,
                            '-af', 'volume=0.2',
                            '-ac', '1', '-ar', '44100', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $leadFile,
                        ]);
                        Log::info("[DUB] Remixed lead.aac with background audio ({$firstStart}s)", [
                            'session' => $this->sessionId,
                        ]);
                    }
                }
            }

            // Regenerate early segments that have no background audio mixed in
            // These are segments whose AAC was created before this job finished
            $sessionJson = Redis::get($sessionKey);
            if (!$sessionJson) return;
            $session = json_decode($sessionJson, true);
            $total = (int) ($session['total_segments'] ?? 0);

            for ($i = 0; $i < min($total, 20); $i++) {
                $aacFile = "{$aacDir}/{$i}.aac";
                if (!file_exists($aacFile)) continue;

                $chunkJson = Redis::get("{$sessionKey}:chunk:{$i}");
                if (!$chunkJson) continue;

                $chunk = json_decode($chunkJson, true);
                $audioBase64 = $chunk['audio_base64'] ?? null;
                if (!$audioBase64) continue; // Error chunk, skip

                $startTime = (float) ($chunk['start_time'] ?? 0);
                $endTime = (float) ($chunk['end_time'] ?? 0);

                // Compute slot end (next segment's start)
                $nextChunkJson = Redis::get("{$sessionKey}:chunk:" . ($i + 1));
                $slotEnd = $nextChunkJson
                    ? (float) (json_decode($nextChunkJson, true)['start_time'] ?? $endTime)
                    : $endTime;

                $slotDuration = round(max(0.1, $slotEnd - $startTime), 3);

                // Decode TTS audio from Redis to temp file
                $tmpMp3 = "/tmp/remix_{$this->sessionId}_{$i}.mp3";
                file_put_contents($tmpMp3, base64_decode($audioBase64));

                $result = Process::timeout(20)->run([
                    'ffmpeg', '-y',
                    '-i', $tmpMp3,
                    '-ss', (string) round($startTime, 3),
                    '-t', (string) $slotDuration,
                    '-i', $originalAudioPath,
                    '-filter_complex',
                    "[0:a]aresample=44100,apad=whole_dur={$slotDuration}[tts];[1:a]volume=0.2[bg];[tts][bg]amix=inputs=2:duration=first:normalize=0",
                    '-t', (string) $slotDuration,
                    '-ac', '1', '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
                ]);

                @unlink($tmpMp3);

                if (!$result->successful()) {
                    Log::warning("[DUB] Remix segment #{$i} failed", [
                        'session' => $this->sessionId,
                        'error' => Str::limit($result->errorOutput(), 200),
                    ]);
                }
            }

            Log::info("[DUB] Early segments remixed with background audio", [
                'session' => $this->sessionId,
            ]);
        } catch (\Throwable $e) {
            Log::warning("[DUB] Remix early segments failed: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Clear pending flag so playback isn't blocked
        $this->markAudioReady();

        Log::warning("[DUB] DownloadOriginalAudioJob failed (non-critical)", [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
