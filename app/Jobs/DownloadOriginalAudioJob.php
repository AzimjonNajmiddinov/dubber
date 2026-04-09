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
    public int $tries = 3;

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

        if (!str_contains($this->videoUrl, '.m3u8')) return;

        // Parse audio playlist to get segment URLs + durations
        $segments = $this->parseAudioPlaylist();
        if (empty($segments)) {
            Log::warning("[DUB] No audio segments found in HLS", ['session' => $this->sessionId]);
            return;
        }

        // Store segments info in Redis for chunked download jobs
        $totalDuration = array_sum(array_column($segments, 'duration'));
        Redis::setex("instant-dub:{$this->sessionId}:audio-segments", 86400, json_encode($segments));

        // Update session with video duration
        $sessionJson = Redis::get($sessionKey);
        if ($sessionJson) {
            $s = json_decode($sessionJson, true);
            $s['video_duration'] = $totalDuration;
            Redis::setex($sessionKey, 50400, json_encode($s));
        }

        // Dispatch one small DownloadAudioChunkJob per HLS .ts segment (~2-6s each).
        // This lets the first chunk become available in seconds, unblocking playback
        // far earlier than waiting for the entire video to download.
        $chunkIndex = 0;
        $currentTime = 0.0;
        foreach ($segments as $seg) {
            $end = $currentTime + (float) $seg['duration'];
            DownloadAudioChunkJob::dispatch(
                $this->sessionId,
                $chunkIndex,
                $currentTime,
                $end,
            )->onQueue('default');
            $currentTime = $end;
            $chunkIndex++;
        }

        // Store total so the playlist knows when all bg chunks are available
        $sJson = Redis::get($sessionKey);
        if ($sJson) {
            $s = json_decode($sJson, true);
            $s['total_bg_chunks'] = $chunkIndex;
            Redis::setex($sessionKey, 50400, json_encode($s));
        }

        Log::info("[DUB] Audio download dispatched ({$chunkIndex} chunks, {$totalDuration}s total)", [
            'session' => $this->sessionId,
        ]);
    }

    private function parseAudioPlaylist(): array
    {
        try {
            $masterResp = Http::timeout(10)->get($this->videoUrl);
            if ($masterResp->failed()) return [];

            $master = $masterResp->body();
            $urlWithoutQuery = strtok($this->videoUrl, '?');
            $baseUrl = preg_replace('#/[^/]+$#', '/', $urlWithoutQuery);
            $query = parse_url($this->videoUrl, PHP_URL_QUERY) ?? '';

            // Find first audio track
            preg_match_all('/^#EXT-X-MEDIA:.*TYPE=AUDIO.*$/m', $master, $audioLines);
            $audioUri = null;
            foreach ($audioLines[0] ?? [] as $line) {
                if (preg_match('/URI="([^"]+)"/', $line, $m)) {
                    $audioUri = $m[1];
                    break;
                }
            }
            if (!$audioUri) return [];

            $audioPlaylistUrl = str_starts_with($audioUri, 'http') ? $audioUri : $baseUrl . $audioUri;
            if ($query) $audioPlaylistUrl .= (str_contains($audioPlaylistUrl, '?') ? '&' : '?') . $query;

            $audioResp = Http::timeout(10)->get($audioPlaylistUrl);
            if ($audioResp->failed()) return [];

            $audioBase = preg_replace('#/[^/]+$#', '/', strtok($audioPlaylistUrl, '?'));

            // Parse #EXTINF durations and segment URLs
            $segments = [];
            $lines = explode("\n", $audioResp->body());
            $nextDuration = null;

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (preg_match('/^#EXTINF:([\d.]+)/', $trimmed, $m)) {
                    $nextDuration = (float) $m[1];
                } elseif ($nextDuration !== null && $trimmed !== '' && !str_starts_with($trimmed, '#')) {
                    $url = str_starts_with($trimmed, 'http') ? $trimmed : $audioBase . $trimmed;
                    if ($query && !str_contains($url, '?')) {
                        $url .= '?' . $query;
                    }
                    $segments[] = [
                        'url' => $url,
                        'duration' => $nextDuration,
                    ];
                    $nextDuration = null;
                }
            }

            return $segments;
        } catch (\Throwable $e) {
            Log::warning("[DUB] Audio playlist parsing failed", [
                'session' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning("[DUB] DownloadOriginalAudioJob failed", [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
