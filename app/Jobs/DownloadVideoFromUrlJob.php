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

        // Strategy 2: Cobalt API (requires API key in COBALT_API_KEY env)
        if ($this->tryCobaltApi($url, $absolutePath)) {
            $this->finalizeDownload($video, $relativePath, $absolutePath);
            return;
        }

        // Strategy 3: Invidious API (free, no auth, YouTube only)
        if ($this->tryInvidiousApi($url, $absolutePath)) {
            $this->finalizeDownload($video, $relativePath, $absolutePath);
            return;
        }

        // Strategy 4: Direct HTTP download (for direct video URLs)
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
     * Requires COBALT_API_KEY in .env (free key from cobalt.tools).
     */
    private function tryCobaltApi(string $url, string $outputPath): bool
    {
        $apiKey = env('COBALT_API_KEY');
        if (!$apiKey) {
            return false; // Skip if no API key configured
        }

        $cleanUrl = preg_replace('/[&?](t|si|feature|pp)=[^&]*/', '', $url);
        $cleanUrl = rtrim($cleanUrl, '?&');

        Log::info('Attempting Cobalt API download', ['url' => $cleanUrl]);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => "Api-Key {$apiKey}",
                ])
                ->post('https://api.cobalt.tools', [
                    'url' => $cleanUrl,
                    'videoQuality' => '720',
                    'filenameStyle' => 'basic',
                ]);

            if (!$response->successful()) {
                Log::warning('Cobalt API request failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
                return false;
            }

            $data = $response->json();
            $downloadUrl = $data['url'] ?? null;
            $status = $data['status'] ?? '';

            if (!$downloadUrl || !in_array($status, ['redirect', 'tunnel', 'stream'])) {
                return false;
            }

            $dlResponse = Http::withOptions([
                'sink' => $outputPath,
                'timeout' => 3600,
                'connect_timeout' => 30,
            ])->get($downloadUrl);

            if (file_exists($outputPath) && filesize($outputPath) > 10000) {
                Log::info('Cobalt API download successful', ['size' => filesize($outputPath)]);
                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('Cobalt API error', ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Download YouTube video via Invidious API (free, no auth).
     * Uses public Invidious instances to get direct video stream URLs.
     */
    private function tryInvidiousApi(string $url, string $outputPath): bool
    {
        // Extract YouTube video ID
        $videoId = $this->extractYouTubeId($url);
        if (!$videoId) {
            return false; // Not a YouTube URL
        }

        $instances = [
            'https://inv.nadeko.net',
            'https://invidious.nerdvpn.de',
            'https://invidious.jing.rocks',
            'https://iv.nboez.com',
            'https://invidious.privacyredirect.com',
        ];

        foreach ($instances as $instance) {
            Log::info('Attempting Invidious download', ['videoId' => $videoId, 'instance' => $instance]);

            try {
                $response = Http::timeout(15)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get("{$instance}/api/v1/videos/{$videoId}");

                if (!$response->successful()) {
                    continue;
                }

                $data = $response->json();
                if (!is_array($data)) {
                    continue;
                }

                // Find best video stream (prefer 720p mp4)
                $downloadUrl = null;
                $streams = $data['formatStreams'] ?? [];

                // Sort by quality - prefer 720p
                foreach ($streams as $stream) {
                    $quality = $stream['qualityLabel'] ?? '';
                    $streamUrl = $stream['url'] ?? null;
                    $type = $stream['type'] ?? '';

                    if (!$streamUrl || !str_contains($type, 'video/mp4')) {
                        continue;
                    }

                    $downloadUrl = $streamUrl;

                    // Prefer 720p, but accept anything
                    if (str_contains($quality, '720')) {
                        break;
                    }
                }

                if (!$downloadUrl) {
                    Log::warning('Invidious: no suitable stream found', ['instance' => $instance]);
                    continue;
                }

                Log::info('Invidious: downloading stream', ['instance' => $instance]);

                // Download the video stream
                $dlResponse = Http::withOptions([
                    'sink' => $outputPath,
                    'timeout' => 3600,
                    'connect_timeout' => 30,
                ])->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])->get($downloadUrl);

                if (file_exists($outputPath) && filesize($outputPath) > 10000) {
                    Log::info('Invidious download successful', [
                        'instance' => $instance,
                        'size' => filesize($outputPath),
                    ]);
                    return true;
                }
            } catch (\Throwable $e) {
                Log::warning('Invidious error', ['instance' => $instance, 'error' => $e->getMessage()]);
            }
        }

        return false;
    }

    /**
     * Extract YouTube video ID from various URL formats.
     */
    private function extractYouTubeId(string $url): ?string
    {
        $patterns = [
            '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
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
