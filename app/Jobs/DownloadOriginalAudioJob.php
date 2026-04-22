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

        if (str_contains($this->videoUrl, 'youtube.com') || str_contains($this->videoUrl, 'youtu.be')) {
            $this->handleYoutube();
            return;
        }

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

        // Group TS segments into 30-second chunks — balances first-chunk latency
        // (~8s) with queue size (~240 jobs for 2h film instead of ~1400).
        $chunkIndex  = 0;
        $chunkStart  = 0.0;
        $chunkEnd    = 0.0;
        $CHUNK_SIZE  = 30.0;

        foreach ($segments as $seg) {
            $chunkEnd += (float) $seg['duration'];
            if ($chunkEnd - $chunkStart >= $CHUNK_SIZE) {
                DownloadAudioChunkJob::dispatch(
                    $this->sessionId, $chunkIndex, $chunkStart, $chunkEnd,
                )->onQueue('default');
                $chunkStart = $chunkEnd;
                $chunkIndex++;
            }
        }
        if ($chunkEnd > $chunkStart) {
            DownloadAudioChunkJob::dispatch(
                $this->sessionId, $chunkIndex, $chunkStart, $chunkEnd,
            )->onQueue('default');
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

    private function handleYoutube(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $workDir = storage_path("app/instant-dub/{$this->sessionId}");
        @mkdir($workDir, 0755, true);

        $audioPath = "{$workDir}/source_audio.m4a";

        Log::info("[DUB] YouTube: downloading audio via yt-dlp", ['session' => $this->sessionId]);

        $result = Process::timeout(300)->run([
            'yt-dlp',
            '-f', 'bestaudio[ext=m4a]/bestaudio',
            '-o', $audioPath,
            '--no-playlist', '--quiet', '--no-warnings',
            '--user-agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            '--force-ipv4',
            '--extractor-args', 'youtube:player_client=web',
            $this->videoUrl,
        ]);

        if (!$result->successful() || !file_exists($audioPath) || filesize($audioPath) < 1000) {
            Log::warning("[DUB] YouTube audio download failed", [
                'session' => $this->sessionId,
                'error'   => Str::limit($result->errorOutput(), 300),
            ]);
            return;
        }

        // Get total duration via ffprobe
        $probeResult = Process::timeout(15)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $audioPath,
        ]);
        $totalDuration = (float) trim($probeResult->output());
        if ($totalDuration <= 0) {
            Log::warning("[DUB] YouTube: could not determine audio duration", ['session' => $this->sessionId]);
            return;
        }

        // Update session with video duration
        $sessionJson = Redis::get($sessionKey);
        if ($sessionJson) {
            $s = json_decode($sessionJson, true);
            $s['video_duration'] = $totalDuration;
            Redis::setex($sessionKey, 50400, json_encode($s));
        }

        // Dispatch 30s chunks using local file path
        $CHUNK_SIZE  = 30.0;
        $chunkIndex  = 0;
        $chunkStart  = 0.0;

        while ($chunkStart < $totalDuration) {
            $chunkEnd = min($chunkStart + $CHUNK_SIZE, $totalDuration);
            DownloadAudioChunkJob::dispatch(
                $this->sessionId, $chunkIndex, $chunkStart, $chunkEnd, $audioPath,
            )->onQueue('default');
            $chunkStart = $chunkEnd;
            $chunkIndex++;
        }

        $sessionJson = Redis::get($sessionKey);
        if ($sessionJson) {
            $s = json_decode($sessionJson, true);
            $s['total_bg_chunks'] = $chunkIndex;
            Redis::setex($sessionKey, 50400, json_encode($s));
        }

        Log::info("[DUB] YouTube audio dispatched ({$chunkIndex} chunks, {$totalDuration}s)", [
            'session' => $this->sessionId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning("[DUB] DownloadOriginalAudioJob failed", [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
