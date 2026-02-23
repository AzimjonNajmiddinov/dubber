<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves video source and dispatches chunk processing jobs IMMEDIATELY.
 *
 * For URL-based videos: resolves direct stream URLs via yt-dlp --get-url,
 * gets duration via ffprobe, and dispatches chunks that stream directly from CDN.
 * Full download runs in background (BackgroundDownloadJob) for stem separation.
 *
 * For file uploads: uses local file directly (original behavior).
 */
class StartChunkProcessingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 2;
    public int $uniqueFor = 3600;

    private const DEFAULT_CHUNK_DURATION = 10;

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return 'chunk_start_' . $this->videoId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('StartChunkProcessingJob failed', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        Video::where('id', $this->videoId)->update(['status' => 'failed']);
    }

    public function handle(): void
    {
        $video = Video::findOrFail($this->videoId);

        $hasLocalFile = $video->original_path && Storage::disk('local')->exists($video->original_path);

        if ($hasLocalFile) {
            // File upload path — use local file directly
            $this->handleLocalFile($video);
        } elseif ($video->source_url) {
            // URL path — resolve stream URLs, dispatch chunks immediately
            $this->handleStreamUrl($video);
        } else {
            throw new \RuntimeException('No video source (no local file, no source URL)');
        }
    }

    /**
     * Original flow for file uploads: local file exists, use it directly.
     */
    private function handleLocalFile(Video $video): void
    {
        $videoPath = Storage::disk('local')->path($video->original_path);
        $duration = $this->getMediaDuration($videoPath);

        if ($duration <= 0) {
            throw new \RuntimeException('Could not determine video duration');
        }

        $video->update(['duration' => $duration]);

        $this->dispatchChunks($video, $duration);

        // Start stem separation in background (extract audio + Demucs)
        $this->extractFullAudio($video);
        SeparateStemsJob::dispatch($video->id)->onQueue('default');
        Log::info('Stem separation dispatched to background', ['video_id' => $video->id]);
    }

    /**
     * Stream flow: resolve direct CDN URLs, get duration remotely, dispatch chunks.
     * Full download happens in background via BackgroundDownloadJob.
     */
    private function handleStreamUrl(Video $video): void
    {
        $video->update(['status' => 'resolving_stream']);

        // Step 1: Resolve direct stream URL(s) via yt-dlp --get-url
        $streamUrls = $this->resolveStreamUrls($video->source_url);

        if (!$streamUrls['video']) {
            // yt-dlp --get-url failed — fall back to full download
            Log::warning('Stream URL resolution failed, falling back to full download', [
                'video_id' => $video->id,
            ]);
            $this->downloadAndProcess($video);
            return;
        }

        // Step 2: Get duration via ffprobe on the stream URL
        $duration = $this->getMediaDuration($streamUrls['video']);

        if ($duration <= 0) {
            Log::warning('Could not get duration from stream URL, falling back to full download', [
                'video_id' => $video->id,
            ]);
            $this->downloadAndProcess($video);
            return;
        }

        // Step 3: Store stream info on video
        $video->update([
            'stream_url' => $streamUrls['video'],
            'stream_audio_url' => $streamUrls['audio'],
            'duration' => $duration,
        ]);

        Log::info('Stream URLs resolved', [
            'video_id' => $video->id,
            'has_separate_audio' => $streamUrls['audio'] !== null,
            'duration' => $duration,
        ]);

        // Step 4: Dispatch chunks immediately (they'll stream from CDN)
        $this->dispatchChunks($video, $duration);

        // Step 5: Start full download in background for stem separation
        BackgroundDownloadJob::dispatch($video->id)->onQueue('default');
        Log::info('Background download dispatched', ['video_id' => $video->id]);
    }

    /**
     * Resolve direct stream URLs using yt-dlp --get-url.
     * Returns 1 URL for muxed streams, 2 URLs for separate video+audio (DASH).
     *
     * @return array{video: ?string, audio: ?string}
     */
    private function resolveStreamUrls(string $sourceUrl): array
    {
        $cookiesFile = $this->findCookiesFile();

        $cmd = ['yt-dlp', '--get-url', '-f', 'bv[height<=720]+ba/b[height<=720]/b'];

        if ($cookiesFile) {
            $cmd[] = '--cookies';
            $cmd[] = $cookiesFile;
        }

        $cmd[] = '--no-playlist';
        $cmd[] = $sourceUrl;

        $result = Process::timeout(60)->run($cmd);

        if (!$result->successful()) {
            Log::warning('yt-dlp --get-url failed', [
                'error' => substr($result->errorOutput(), -300),
            ]);
            return ['video' => null, 'audio' => null];
        }

        $urls = array_filter(array_map('trim', explode("\n", trim($result->output()))));

        if (count($urls) >= 2) {
            // Separate video + audio streams (DASH)
            return ['video' => $urls[0], 'audio' => $urls[1]];
        } elseif (count($urls) === 1) {
            // Muxed stream (video + audio in one URL)
            return ['video' => $urls[0], 'audio' => null];
        }

        return ['video' => null, 'audio' => null];
    }

    /**
     * Fallback: full download then process (original behavior).
     */
    private function downloadAndProcess(Video $video): void
    {
        $this->downloadVideo($video);
        $video->refresh();

        if (!$video->original_path) {
            throw new \RuntimeException('Video download failed');
        }

        $this->handleLocalFile($video);
    }

    private function dispatchChunks(Video $video, float $duration): void
    {
        $chunks = $this->calculateChunks($duration);
        $video->update(['status' => 'processing_chunks']);

        Log::info('Dispatching chunk jobs', [
            'video_id' => $video->id,
            'duration' => $duration,
            'total_chunks' => count($chunks),
        ]);

        // Dispatch first chunk with HIGH priority (no delay)
        if (!empty($chunks)) {
            ProcessVideoChunkJob::dispatch(
                $video->id,
                0,
                $chunks[0]['start'],
                $chunks[0]['end']
            )->onQueue('chunks');

            Log::info('First chunk dispatched immediately', ['video_id' => $video->id]);
        }

        // Dispatch remaining chunks with staggered delays
        foreach ($chunks as $index => $chunk) {
            if ($index === 0) continue;

            $delay = $index <= 3 ? $index * 2 : 5;

            ProcessVideoChunkJob::dispatch(
                $video->id,
                $index,
                $chunk['start'],
                $chunk['end']
            )->onQueue('chunks')->delay(now()->addSeconds($delay));
        }

        Log::info('All chunk jobs dispatched', [
            'video_id' => $video->id,
            'total_chunks' => count($chunks),
        ]);
    }

    private function extractFullAudio(Video $video): void
    {
        $videoPath = Storage::disk('local')->path($video->original_path);
        $audioRel = "audio/original/{$video->id}.wav";
        Storage::disk('local')->makeDirectory('audio/original');
        $audioPath = Storage::disk('local')->path($audioRel);

        if (file_exists($audioPath)) {
            return;
        }

        $result = Process::timeout(300)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $videoPath,
            '-vn', '-ac', '2', '-ar', '48000', '-c:a', 'pcm_s16le',
            $audioPath,
        ]);

        if ($result->successful() && file_exists($audioPath)) {
            Log::info('Full audio extracted', ['video_id' => $video->id]);
        }
    }

    private function getMediaDuration(string $pathOrUrl): float
    {
        $result = Process::timeout(30)->run([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $pathOrUrl,
        ]);

        return $result->successful() ? (float) trim($result->output()) : 0;
    }

    private function calculateChunks(float $duration): array
    {
        $chunks = [];
        $start = 0;
        $chunkDuration = $this->getChunkDuration($duration);

        while ($start < $duration) {
            $end = min($start + $chunkDuration, $duration);
            $chunks[] = ['start' => $start, 'end' => $end];
            $start = $end;
        }

        return $chunks;
    }

    private function getChunkDuration(float $totalDuration): float
    {
        $envDuration = env('DUBBER_CHUNK_DURATION');
        if ($envDuration !== null && is_numeric($envDuration)) {
            return max(5, min(30, (float)$envDuration));
        }

        if ($totalDuration <= 60) {
            return 8;
        } elseif ($totalDuration <= 300) {
            return 10;
        } elseif ($totalDuration <= 1800) {
            return 12;
        } else {
            return 15;
        }
    }

    private function downloadVideo(Video $video): void
    {
        if (!$video->source_url) {
            throw new \RuntimeException('No source URL');
        }

        $video->update(['status' => 'downloading']);

        Storage::disk('local')->makeDirectory('videos/originals');
        $filename = \Illuminate\Support\Str::random(16) . '.mp4';
        $relativePath = "videos/originals/{$filename}";
        $absolutePath = Storage::disk('local')->path($relativePath);

        $url = $video->source_url;
        $isYouTube = str_contains($url, 'youtube.com') || str_contains($url, 'youtu.be');
        $isHLS = str_contains($url, '.m3u8');

        $downloaded = false;
        $lastError = '';

        $cookiesFile = $this->findCookiesFile();

        if ($isYouTube) {
            $clients = ['default', 'mweb', 'web', 'android'];
            foreach ($clients as $client) {
                @unlink($absolutePath);

                $cmd = [
                    'yt-dlp',
                    '-f', 'bestvideo[height<=720][ext=mp4]+bestaudio[ext=m4a]/best[height<=720][ext=mp4]/best',
                    '--merge-output-format', 'mp4',
                    '-o', $absolutePath,
                    '--no-playlist',
                ];

                if ($cookiesFile) {
                    $cmd[] = '--cookies';
                    $cmd[] = $cookiesFile;
                }

                if ($client !== 'default') {
                    $cmd[] = '--extractor-args';
                    $cmd[] = "youtube:player_client={$client}";
                }

                $cmd[] = $url;

                $result = Process::timeout(1800)->run($cmd);

                if ($result->successful() && file_exists($absolutePath) && filesize($absolutePath) > 10000) {
                    $downloaded = true;
                    break;
                }

                $lastError = $result->errorOutput() ?: $result->output();
            }
        } elseif ($isHLS) {
            $result = Process::timeout(1800)->run([
                'yt-dlp',
                '-f', 'bestvideo+bestaudio/best',
                '--merge-output-format', 'mp4',
                '-o', $absolutePath,
                $url,
            ]);

            if ($result->successful() && file_exists($absolutePath) && filesize($absolutePath) > 10000) {
                $downloaded = true;
            } else {
                $lastError = $result->errorOutput() ?: $result->output();
            }
        } else {
            $result = Process::timeout(1800)->run([
                'yt-dlp',
                '-f', 'bestvideo[height<=720]+bestaudio/best[height<=720]/best',
                '--merge-output-format', 'mp4',
                '-o', $absolutePath,
                $url,
            ]);

            if ($result->successful() && file_exists($absolutePath) && filesize($absolutePath) > 10000) {
                $downloaded = true;
            } else {
                $lastError = $result->errorOutput() ?: $result->output();
            }
        }

        if (!$downloaded) {
            $downloaded = $this->downloadWithInvidious($url, $absolutePath);
        }

        if (!$downloaded) {
            $video->update(['status' => 'download_failed']);
            throw new \RuntimeException('Video download failed: ' . substr($lastError, -200));
        }

        $video->update([
            'original_path' => $relativePath,
            'status' => 'uploaded',
        ]);
    }

    private function downloadWithInvidious(string $url, string $outputPath): bool
    {
        $videoId = $this->extractYouTubeId($url);
        if (!$videoId) {
            return false;
        }

        $instances = [
            'https://inv.nadeko.net',
            'https://invidious.nerdvpn.de',
            'https://invidious.jing.rocks',
            'https://iv.nboez.com',
        ];

        foreach ($instances as $instance) {
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(15)->get("{$instance}/api/v1/videos/{$videoId}");
                if (!$response->successful()) continue;

                $data = $response->json();
                $downloadUrl = null;

                foreach (($data['formatStreams'] ?? []) as $stream) {
                    if (!($stream['url'] ?? null) || !str_contains($stream['type'] ?? '', 'video/mp4')) continue;
                    $downloadUrl = $stream['url'];
                    if (str_contains($stream['qualityLabel'] ?? '', '720')) break;
                }

                if (!$downloadUrl) continue;

                \Illuminate\Support\Facades\Http::withOptions(['sink' => $outputPath, 'timeout' => 3600, 'connect_timeout' => 30])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get($downloadUrl);

                if (file_exists($outputPath) && filesize($outputPath) > 10000) {
                    return true;
                }
            } catch (\Throwable $e) {
                Log::warning('Invidious error', ['instance' => $instance, 'error' => $e->getMessage()]);
            }
        }

        return false;
    }

    private function extractYouTubeId(string $url): ?string
    {
        $patterns = [
            '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) return $m[1];
        }
        return null;
    }

    private function findCookiesFile(): ?string
    {
        $home = is_string($_SERVER['HOME'] ?? null) ? $_SERVER['HOME'] : (getenv('HOME') ?: '/home/' . get_current_user());

        $paths = [
            base_path('cookies.txt'),
            storage_path('app/cookies.txt'),
            $home . '/.config/yt-dlp/cookies.txt',
            $home . '/cookies.txt',
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && filesize($path) > 100) {
                return $path;
            }
        }

        return null;
    }
}
