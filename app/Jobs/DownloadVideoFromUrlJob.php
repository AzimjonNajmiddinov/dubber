<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DownloadVideoFromUrlJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 hour for large videos
    public int $tries = 3;
    public int $uniqueFor = 3600;

    public array $backoff = [60, 120, 300];

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DownloadVideoFromUrlJob failed permanently', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $video = Video::find($this->videoId);
            if ($video) {
                $video->update(['status' => 'download_failed']);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to update video status after DownloadVideoFromUrlJob failure', [
                'video_id' => $this->videoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(): void
    {
        /** @var Video $video */
        $video = Video::query()->findOrFail($this->videoId);

        if (!$video->source_url) {
            throw new \RuntimeException("No source URL for video {$video->id}");
        }

        $url = $video->source_url;

        Log::info('Starting video download from URL', [
            'video_id' => $video->id,
            'url' => $url,
        ]);

        $video->update(['status' => 'downloading']);

        // Create directory for downloads
        Storage::disk('local')->makeDirectory('videos/originals');

        $filename = Str::random(16) . '.mp4';
        $relativePath = "videos/originals/{$filename}";
        $absolutePath = Storage::disk('local')->path($relativePath);

        // Strategy 1: yt-dlp (handles YouTube, Vimeo, and many other sites)
        if ($this->tryYtDlp($url, $absolutePath)) {
            $this->finalizeDownload($video, $relativePath, $absolutePath);
            return;
        }

        // Strategy 2: Cobalt API (free, no auth, works for YouTube/Twitter/TikTok/etc)
        if ($this->tryCobaltApi($url, $absolutePath)) {
            $this->finalizeDownload($video, $relativePath, $absolutePath);
            return;
        }

        // Strategy 3: Direct HTTP download (for direct video URLs)
        if ($this->tryDirectDownload($url, $absolutePath)) {
            $this->finalizeDownload($video, $relativePath, $absolutePath);
            return;
        }

        throw new \RuntimeException("Failed to download video from URL: {$url}");
    }

    private function tryYtDlp(string $url, string $outputPath): bool
    {
        Log::info('Attempting yt-dlp download', ['url' => $url]);

        $clients = ['default', 'mweb', 'web', 'android'];

        $cookiesFile = $this->findCookiesFile();
        if ($cookiesFile) {
            Log::info('Using cookies file for yt-dlp', ['cookies' => $cookiesFile]);
        }

        foreach ($clients as $client) {
            @unlink($outputPath);

            $cmd = [
                'yt-dlp',
                '-f', 'bestvideo[ext=mp4][height<=1080]+bestaudio[ext=m4a]/best[ext=mp4][height<=1080]/best[height<=1080]/best',
                '--merge-output-format', 'mp4',
                '-o', $outputPath,
                '--no-playlist',
                '--no-warnings',
                '--user-agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                '--force-ipv4',
                '--retries', '3',
                '--fragment-retries', '3',
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

            $result = Process::timeout(3600)->run($cmd);

            if ($result->successful() && file_exists($outputPath) && filesize($outputPath) > 10000) {
                Log::info('yt-dlp download successful', [
                    'url' => $url,
                    'client' => $client,
                    'size' => filesize($outputPath),
                ]);
                return true;
            }

            Log::warning("yt-dlp download failed with client {$client}", [
                'url' => $url,
                'exit_code' => $result->exitCode(),
                'stderr' => mb_substr($result->errorOutput(), 0, 500),
            ]);
        }

        return false;
    }

    /**
     * Download video using Cobalt API (cobalt.tools).
     * Free, open-source, no authentication needed.
     * Supports YouTube, Twitter, TikTok, Instagram, Reddit, etc.
     */
    private function tryCobaltApi(string $url, string $outputPath): bool
    {
        // Clean YouTube URL - remove timestamp and tracking params
        $cleanUrl = preg_replace('/[&?](t|si|feature|pp)=[^&]*/', '', $url);
        $cleanUrl = rtrim($cleanUrl, '?&');

        $instances = [
            'https://api.cobalt.tools',
            'https://cobalt-api.kwiatekmiki.com',
        ];

        foreach ($instances as $apiBase) {
            Log::info('Attempting Cobalt API download', ['url' => $cleanUrl, 'instance' => $apiBase]);

            try {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->post($apiBase, [
                        'url' => $cleanUrl,
                        'videoQuality' => '720',
                        'filenameStyle' => 'basic',
                    ]);

                if (!$response->successful()) {
                    Log::warning('Cobalt API request failed', [
                        'instance' => $apiBase,
                        'status' => $response->status(),
                        'body' => mb_substr($response->body(), 0, 500),
                    ]);
                    continue;
                }

                $data = $response->json();
                $status = $data['status'] ?? '';
                $downloadUrl = $data['url'] ?? null;

                if (!$downloadUrl || !in_array($status, ['redirect', 'tunnel', 'stream'])) {
                    Log::warning('Cobalt API no download URL', [
                        'instance' => $apiBase,
                        'status' => $status,
                        'error' => $data['error'] ?? null,
                    ]);
                    continue;
                }

                Log::info('Cobalt API got download URL', [
                    'instance' => $apiBase,
                    'status' => $status,
                ]);

                // Download the actual video file
                $dlResponse = Http::withOptions([
                    'sink' => $outputPath,
                    'timeout' => 3600,
                    'connect_timeout' => 30,
                ])->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])->get($downloadUrl);

                if (file_exists($outputPath) && filesize($outputPath) > 10000) {
                    Log::info('Cobalt API download successful', [
                        'url' => $cleanUrl,
                        'instance' => $apiBase,
                        'size' => filesize($outputPath),
                    ]);
                    return true;
                }

                Log::warning('Cobalt API download file too small or missing', [
                    'instance' => $apiBase,
                    'exists' => file_exists($outputPath),
                    'size' => file_exists($outputPath) ? filesize($outputPath) : 0,
                ]);

            } catch (\Throwable $e) {
                Log::warning('Cobalt API error', [
                    'instance' => $apiBase,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    private function tryDirectDownload(string $url, string $outputPath): bool
    {
        $skipDomains = ['youtube.com', 'youtu.be', 'vimeo.com', 'dailymotion.com', 'tiktok.com'];
        foreach ($skipDomains as $domain) {
            if (str_contains($url, $domain)) {
                return false;
            }
        }

        Log::info('Attempting direct HTTP download', ['url' => $url]);

        try {
            $response = Http::withOptions([
                'sink' => $outputPath,
                'timeout' => 3600,
                'connect_timeout' => 30,
            ])->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ])->get($url);

            if ($response->successful() && file_exists($outputPath) && filesize($outputPath) > 10000) {
                Log::info('Direct download successful', [
                    'url' => $url,
                    'size' => filesize($outputPath),
                ]);
                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('Direct download failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    private function finalizeDownload(Video $video, string $relativePath, string $absolutePath): void
    {
        $probeResult = Process::timeout(60)->run([
            'ffprobe',
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height,duration',
            '-of', 'json',
            $absolutePath,
        ]);

        if (!$probeResult->successful()) {
            @unlink($absolutePath);
            throw new \RuntimeException('Downloaded file is not a valid video');
        }

        $probeData = json_decode($probeResult->output(), true);
        $hasVideo = !empty($probeData['streams']);

        if (!$hasVideo) {
            @unlink($absolutePath);
            throw new \RuntimeException('Downloaded file has no video stream');
        }

        Log::info('Video download complete', [
            'video_id' => $video->id,
            'path' => $relativePath,
            'size' => filesize($absolutePath),
            'probe' => $probeData,
        ]);

        $video->update([
            'original_path' => $relativePath,
            'status' => 'uploaded',
        ]);

        ExtractAudioJob::dispatch($video->id);
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
