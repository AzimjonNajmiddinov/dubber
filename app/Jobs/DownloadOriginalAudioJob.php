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
            Log::info("[DUB] [{$title}] Original audio ready, subsequent segments will use background mixing", [
                'session' => $this->sessionId,
            ]);
        } else {
            Log::info("[DUB] [{$title}] No original audio available, segments will be TTS-only", [
                'session' => $this->sessionId,
            ]);
        }
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
                $probe = Process::timeout(10)->run(['ffprobe', '-hide_banner', '-loglevel', 'error', $outputPath]);
                if ($probe->successful()) {
                    Log::info("[DUB] Original audio downloaded (" . round(filesize($outputPath) / 1024) . " KB)", [
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

    public function failed(\Throwable $exception): void
    {
        Log::warning("[DUB] DownloadOriginalAudioJob failed (non-critical)", [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
