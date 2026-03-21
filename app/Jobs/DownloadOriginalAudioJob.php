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

    public int $timeout = 120;
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

        if (!str_contains($this->videoUrl, '.m3u8')) {
            Log::info("[DUB] Not an HLS URL, skipping audio download", ['session' => $this->sessionId]);
            return;
        }

        // Prepare the playlist file for ffmpeg
        $playlistPath = $this->preparePlaylist();
        if (!$playlistPath) {
            Log::warning("[DUB] Could not prepare audio playlist", ['session' => $this->sessionId]);
            return;
        }

        // Start ffmpeg as a detached background process — no timeout limits
        $tmpDir = storage_path("app/instant-dub/{$this->sessionId}");
        $outputPath = "{$tmpDir}/original_audio.aac";
        $donePath = "{$tmpDir}/audio_download.done";
        $logPath = "{$tmpDir}/ffmpeg_download.log";

        // Clean up any previous attempt
        @unlink($outputPath);
        @unlink($donePath);

        $cmd = sprintf(
            'ffmpeg -y -protocol_whitelist file,http,https,tcp,tls,crypto -i %s -vn -ac 1 -ar 44100 -c:a aac -b:a 96k -f adts %s > %s 2>&1 && touch %s &',
            escapeshellarg($playlistPath),
            escapeshellarg($outputPath),
            escapeshellarg($logPath),
            escapeshellarg($donePath),
        );

        exec($cmd);

        Log::info("[DUB] Audio download started as background process", ['session' => $this->sessionId]);

        // Dispatch polling job to wait for completion and remix
        WaitForAudioDownloadJob::dispatch($this->sessionId)
            ->delay(now()->addSeconds(15))
            ->onQueue('default');
    }

    private function preparePlaylist(): ?string
    {
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

            $audioPlaylistUrl = str_starts_with($audioUri, 'http') ? $audioUri : $baseUrl . $audioUri;
            if ($query) $audioPlaylistUrl .= (str_contains($audioPlaylistUrl, '?') ? '&' : '?') . $query;

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

            $tmpDir = storage_path("app/instant-dub/{$this->sessionId}");
            @mkdir($tmpDir, 0755, true);
            $localPlaylist = "{$tmpDir}/audio_playlist.m3u8";
            file_put_contents($localPlaylist, $rewritten);

            return $localPlaylist;
        } catch (\Throwable $e) {
            Log::warning("[DUB] Audio playlist preparation failed", [
                'session' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning("[DUB] DownloadOriginalAudioJob failed (non-critical)", [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
