<?php

namespace App\Jobs;

use App\Support\DubSession;
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

    public int $timeout = 7200; // 2 hours — large video downloads need time
    public int $tries = 2;

    public function __construct(
        public string  $sessionId,
        public string  $videoUrl,
        public ?string $audioUrl = null,
    ) {}

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['status'] ?? '') === 'stopped') return;

        if (str_contains($this->videoUrl, 'youtube.com') || str_contains($this->videoUrl, 'youtu.be')) {
            // Always use yt-dlp — ffmpeg direct URL downloads are truncated by YouTube CDN
            // at DASH range boundaries (~130s), causing audio to stop at 2:10 for long videos.
            $this->handleYoutube();
            return;
        }

        if (!str_contains($this->videoUrl, '.m3u8')) return;

        $segments = $this->parseAudioPlaylist();
        if (empty($segments)) {
            DubSession::patch($this->sessionId, [
                'status' => 'error',
                'error' => 'No audio segments found in HLS source.',
            ]);
            Log::warning("[DUB] No audio segments found in HLS", ['session' => $this->sessionId]);
            return;
        }

        $totalDuration = array_sum(array_column($segments, 'duration'));
        Redis::setex(DubSession::audioSegmentsKey($this->sessionId), 86400, json_encode($segments));
        DubSession::patch($this->sessionId, ['video_duration' => $totalDuration]);

        $session = DubSession::get($this->sessionId) ?? $session;
        $subtitleEnd = (float) ($session['expected_duration'] ?? 0.0);
        if ($this->subtitleCoverageLooksBroken($subtitleEnd, $totalDuration)) {
            DubSession::patch($this->sessionId, [
                'status' => 'error',
                'error' => sprintf(
                    'Subtitle track looks broken: captions end at %ss but HLS audio is %ss.',
                    round($subtitleEnd, 1),
                    round($totalDuration, 1),
                ),
            ]);
            Log::warning("[DUB] HLS subtitle coverage too short; refusing background-only dub", [
                'session' => $this->sessionId,
                'subtitle_end' => $subtitleEnd,
                'video_duration' => $totalDuration,
            ]);
            return;
        }

        $this->dispatchChunks($totalDuration, function (int $idx, float $start, float $end) {
            DownloadAudioChunkJob::dispatch($this->sessionId, $idx, $start, $end)->onQueue('audio-downloads');
        }, segments: $segments);
    }

    private function subtitleCoverageLooksBroken(float $subtitleEnd, float $totalDuration): bool
    {
        if ($totalDuration < 600.0 || $subtitleEnd <= 0.0) {
            return false;
        }

        $missingTail = $totalDuration - $subtitleEnd;

        return $subtitleEnd < ($totalDuration * 0.7) && $missingTail > 300.0;
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

            $playlistUrl = $this->videoUrl;
            $playlistBody = $master;

            if (!str_contains($master, '#EXTINF')) {
                // Prefer a dedicated audio rendition, but fall back to the first
                // media variant for muxed HLS where audio lives inside TS/fMP4.
                preg_match_all('/^#EXT-X-MEDIA:.*TYPE=AUDIO.*$/m', $master, $audioLines);
                $playlistUri = null;
                foreach ($audioLines[0] ?? [] as $line) {
                    if (preg_match('/URI="([^"]+)"/', $line, $m)) {
                        $playlistUri = $m[1];
                        break;
                    }
                }

                if (!$playlistUri) {
                    $playlistUri = $this->firstVariantPlaylistUri($master);
                }

                if (!$playlistUri) return [];

                $playlistUrl = $this->resolveHlsUrl($baseUrl, $playlistUri, $query);
                $playlistResp = Http::timeout(10)->get($playlistUrl);
                if ($playlistResp->failed()) return [];
                $playlistBody = $playlistResp->body();
            }

            $audioBase = preg_replace('#/[^/]+$#', '/', strtok($playlistUrl, '?'));

            // Parse #EXTINF durations and segment URLs
            $segments = [];
            $lines = explode("\n", $playlistBody);
            $nextDuration = null;
            $currentTime = 0.0;
            $currentKeyTag = null;
            $currentMapTag = null;

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, '#EXT-X-KEY:')) {
                    $currentKeyTag = $this->resolveHlsTagUri($trimmed, $audioBase, $query);
                } elseif (str_starts_with($trimmed, '#EXT-X-MAP:')) {
                    $currentMapTag = $this->resolveHlsTagUri($trimmed, $audioBase, $query);
                } elseif (preg_match('/^#EXTINF:([\d.]+)/', $trimmed, $m)) {
                    $nextDuration = (float) $m[1];
                } elseif ($nextDuration !== null && $trimmed !== '' && !str_starts_with($trimmed, '#')) {
                    $segmentStart = $currentTime;
                    $segmentEnd = $segmentStart + $nextDuration;
                    $segments[] = [
                        'url' => $this->resolveHlsUrl($audioBase, $trimmed, $query),
                        'duration' => $nextDuration,
                        'start' => $segmentStart,
                        'end' => $segmentEnd,
                        'key' => $currentKeyTag,
                        'map' => $currentMapTag,
                    ];
                    $currentTime = $segmentEnd;
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

    private function firstVariantPlaylistUri(string $master): ?string
    {
        $expectUri = false;
        foreach (explode("\n", $master) as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                $expectUri = true;
                continue;
            }
            if ($expectUri && $trimmed !== '' && !str_starts_with($trimmed, '#')) {
                return $trimmed;
            }
        }

        return null;
    }

    private function resolveHlsUrl(string $baseUrl, string $uri, string $parentQuery = ''): string
    {
        if (str_starts_with($uri, 'http')) {
            return $uri;
        }

        if (str_starts_with($uri, '//')) {
            return 'https:' . $uri;
        }

        if (str_starts_with($uri, '/')) {
            $parts = parse_url($baseUrl);
            $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            if (!empty($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
            $url = $origin . $uri;
        } else {
            $url = rtrim($baseUrl, '/') . '/' . $uri;
        }

        if ($parentQuery && !str_contains($url, '?')) {
            $url .= '?' . $parentQuery;
        }

        return $url;
    }

    private function resolveHlsTagUri(string $tag, string $baseUrl, string $parentQuery = ''): string
    {
        return preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($baseUrl, $parentQuery) {
            return 'URI="' . $this->resolveHlsUrl($baseUrl, $m[1], $parentQuery) . '"';
        }, $tag);
    }

    private function handleYoutubeDirectUrl(string $audioUrl): void
    {
        $audioPath = $this->workDirPath('source_audio.m4a');
        Log::info("[DUB] Downloading audio via direct URL", ['session' => $this->sessionId]);

        $result = Process::timeout(3600)->run([
            'ffmpeg', '-y',
            '-user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            '-i', $audioUrl,
            '-vn', '-acodec', 'copy', $audioPath,
        ]);

        if (!file_exists($audioPath) || filesize($audioPath) < 1000) {
            Log::warning("[DUB] Direct audio download failed, falling back to yt-dlp", [
                'session' => $this->sessionId,
                'error'   => Str::limit($result->errorOutput(), 300),
            ]);
            $this->handleYoutube();
            return;
        }

        // Detect CDN range truncation: compare downloaded duration vs expected from subtitles
        $probeResult = Process::timeout(10)->run([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $audioPath,
        ]);
        $audioDuration = (float) trim($probeResult->output());

        $session          = DubSession::get($this->sessionId);
        $expectedDuration = (float) ($session['expected_duration'] ?? 0);

        if ($expectedDuration > 120 && $audioDuration > 0 && $audioDuration < $expectedDuration * 0.75) {
            Log::warning("[DUB] Direct URL audio truncated ({$audioDuration}s vs expected {$expectedDuration}s), falling back to yt-dlp", [
                'session' => $this->sessionId,
            ]);
            @unlink($audioPath);
            $this->handleYoutube();
            return;
        }

        $this->dispatchChunksFromLocalFile($audioPath);
    }

    private function handleYoutube(): void
    {
        Log::info("[DUB] YouTube: fetching duration metadata", ['session' => $this->sessionId]);

        // Fast metadata-only call — gets duration without downloading audio (~2s)
        $metaResult = Process::timeout(30)->run([
            'yt-dlp', '--print', 'duration', '--no-download',
            '--extractor-args', 'youtube:player_client=web_creator,mweb,ios',
            $this->videoUrl,
        ]);

        $totalDuration = (float) trim($metaResult->output());

        if ($totalDuration <= 0) {
            Log::warning("[DUB] YouTube: could not get duration, falling back to single-file download", [
                'session' => $this->sessionId,
                'error'   => Str::limit($metaResult->errorOutput(), 300),
            ]);
            $this->handleYoutubeFallback();
            return;
        }

        DubSession::patch($this->sessionId, ['video_duration' => $totalDuration]);

        // Pre-write total_bg_chunks so hlsAudioPlaylist / checkPlayable never sees 0
        $totalBgChunks = (int) ceil($totalDuration / DownloadYouTubeWindowJob::CHUNK_SIZE);
        DubSession::patch($this->sessionId, ['total_bg_chunks' => $totalBgChunks]);
        for ($idx = 0; $idx < $totalBgChunks; $idx++) {
            $start = $idx * DownloadYouTubeWindowJob::CHUNK_SIZE;
            $end = min($start + DownloadYouTubeWindowJob::CHUNK_SIZE, $totalDuration);
            DubSession::mergeBgChunk($this->sessionId, $idx, [
                'start'   => $start,
                'end'     => $end,
                'planned' => true,
            ]);
        }

        // Dispatch one window job per 5-minute slice (FIFO: window 0 starts TTS immediately)
        $windowSize  = 300.0; // 5 minutes
        $windowIndex = 0;
        for ($start = 0.0; $start < $totalDuration; $start += $windowSize) {
            $end = min($start + $windowSize, $totalDuration);
            DownloadYouTubeWindowJob::dispatch($this->sessionId, $this->videoUrl, $windowIndex++, $start, $end)
                ->onQueue('audio-downloads');
        }

        Log::info("[DUB] YouTube: dispatched {$windowIndex} windows for {$totalDuration}s", [
            'session' => $this->sessionId,
        ]);
    }

    private function handleYoutubeFallback(): void
    {
        $audioPath = $this->workDirPath('source_audio.m4a');
        Log::info("[DUB] YouTube: single-file fallback download via yt-dlp", ['session' => $this->sessionId]);

        $result = Process::timeout(7200)->run([
            'yt-dlp',
            '-f', 'bestaudio[ext=m4a]/bestaudio',
            '-o', $audioPath,
            '--no-playlist', '--quiet', '--no-warnings',
            '--extractor-args', 'youtube:player_client=web_creator,mweb,ios',
            $this->videoUrl,
        ]);

        if (!$result->successful() || !file_exists($audioPath) || filesize($audioPath) < 1000) {
            Log::warning("[DUB] YouTube single-file download failed", [
                'session' => $this->sessionId,
                'error'   => Str::limit($result->errorOutput(), 1000),
            ]);
            return;
        }

        $this->dispatchChunksFromLocalFile($audioPath);
    }

    private function dispatchChunksFromLocalFile(string $audioPath): void
    {
        $probeResult = Process::timeout(15)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $audioPath,
        ]);
        $totalDuration = (float) trim($probeResult->output());
        if ($totalDuration <= 0) {
            Log::warning("[DUB] Could not determine audio duration", ['session' => $this->sessionId]);
            return;
        }

        DubSession::patch($this->sessionId, ['video_duration' => $totalDuration]);

        $this->dispatchChunks($totalDuration, function (int $idx, float $start, float $end) use ($audioPath) {
            DownloadAudioChunkJob::dispatch($this->sessionId, $idx, $start, $end, $audioPath)->onQueue('audio-downloads');
        });
    }

    /**
     * Pre-calculate all chunk boundaries, write total_bg_chunks to Redis BEFORE
     * dispatching so hlsAudioPlaylist never reads 0 (race condition fix).
     *
     * @param  float         $totalDuration
     * @param  callable      $dispatch  fn(int $idx, float $start, float $end): void
     * @param  array|null    $segments  HLS segment list (optional — used to count without dispatch)
     */
    private function dispatchChunks(float $totalDuration, callable $dispatch, ?array $segments = null): void
    {
        $CHUNK_SIZE    = 30.0;
        $pendingChunks = [];
        $chunkIndex    = 0;

        if ($segments !== null) {
            $chunkStart = 0.0;
            $chunkEnd   = 0.0;
            foreach ($segments as $seg) {
                $chunkEnd += (float) $seg['duration'];
                if ($chunkEnd - $chunkStart >= $CHUNK_SIZE) {
                    $pendingChunks[] = [$chunkIndex++, $chunkStart, $chunkEnd];
                    $chunkStart = $chunkEnd;
                }
            }
            if ($chunkEnd > $chunkStart) {
                $pendingChunks[] = [$chunkIndex++, $chunkStart, $chunkEnd];
            }
        } else {
            $chunkStart = 0.0;
            while ($chunkStart < $totalDuration) {
                $chunkEnd = min($chunkStart + $CHUNK_SIZE, $totalDuration);
                $pendingChunks[] = [$chunkIndex++, $chunkStart, $chunkEnd];
                $chunkStart = $chunkEnd;
            }
        }

        // Write total BEFORE dispatching so hlsAudioPlaylist sees it immediately
        DubSession::patch($this->sessionId, ['total_bg_chunks' => $chunkIndex]);
        foreach ($pendingChunks as [$idx, $start, $end]) {
            DubSession::mergeBgChunk($this->sessionId, $idx, [
                'start'   => $start,
                'end'     => $end,
                'planned' => true,
            ]);
        }

        foreach ($pendingChunks as [$idx, $start, $end]) {
            $dispatch($idx, $start, $end);
        }

        Log::info("[DUB] Audio dispatched ({$chunkIndex} chunks, {$totalDuration}s)", [
            'session' => $this->sessionId,
        ]);
    }

    private function workDirPath(string $filename): string
    {
        $dir = storage_path("app/instant-dub/{$this->sessionId}");
        @mkdir($dir, 0755, true);
        return "{$dir}/{$filename}";
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning("[DUB] DownloadOriginalAudioJob failed", [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
