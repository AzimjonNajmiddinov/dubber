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

/**
 * Downloads full video in background (non-blocking).
 * After download completes, extracts audio and triggers stem separation.
 * This runs on the default queue while chunk processing proceeds in parallel.
 */
class BackgroundDownloadJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 2;
    public int $uniqueFor = 3600;

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return 'bg_download_' . $this->videoId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('BackgroundDownloadJob failed - chunks continue with stream URLs', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function handle(): void
    {
        $video = Video::findOrFail($this->videoId);

        // Skip if already downloaded
        if ($video->original_path && Storage::disk('local')->exists($video->original_path)) {
            Log::info('Video already downloaded, skipping to audio extraction', ['video_id' => $video->id]);
            $this->extractFullAudioAndSeparate($video);
            return;
        }

        if (!$video->source_url) {
            Log::warning('No source URL for background download', ['video_id' => $video->id]);
            return;
        }

        Log::info('Starting background download', ['video_id' => $video->id]);

        $this->downloadVideo($video);
        $video->refresh();

        if (!$video->original_path || !Storage::disk('local')->exists($video->original_path)) {
            Log::warning('Background download failed', ['video_id' => $video->id]);
            return;
        }

        $this->extractFullAudioAndSeparate($video);
    }

    private function extractFullAudioAndSeparate(Video $video): void
    {
        $videoPath = Storage::disk('local')->path($video->original_path);
        $audioRel = "audio/original/{$video->id}.wav";
        Storage::disk('local')->makeDirectory('audio/original');
        $audioPath = Storage::disk('local')->path($audioRel);

        if (!file_exists($audioPath)) {
            $result = Process::timeout(300)->run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-i', $videoPath,
                '-vn', '-ac', '2', '-ar', '48000', '-c:a', 'pcm_s16le',
                $audioPath,
            ]);

            if ($result->successful() && file_exists($audioPath)) {
                Log::info('Full audio extracted (background)', ['video_id' => $video->id]);
            } else {
                Log::warning('Audio extraction failed (background)', ['video_id' => $video->id]);
                return;
            }
        }

        SeparateStemsJob::dispatch($video->id)->onQueue('default');
        Log::info('Background download complete, stem separation dispatched', ['video_id' => $video->id]);
    }

    private function downloadVideo(Video $video): void
    {
        Storage::disk('local')->makeDirectory('videos/originals');
        $filename = Str::random(16) . '.mp4';
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

        // Fallback: try Invidious API if yt-dlp failed (YouTube only)
        if (!$downloaded) {
            $downloaded = $this->downloadWithInvidious($url, $absolutePath);
        }

        if ($downloaded) {
            $video->update(['original_path' => $relativePath]);
            Log::info('Background download complete', [
                'video_id' => $video->id,
                'size' => filesize($absolutePath),
            ]);
        } else {
            Log::warning('Background download failed', [
                'video_id' => $video->id,
                'error' => substr($lastError, -200),
            ]);
        }
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
                $response = Http::timeout(15)->get("{$instance}/api/v1/videos/{$videoId}");
                if (!$response->successful()) continue;

                $data = $response->json();
                $downloadUrl = null;

                foreach (($data['formatStreams'] ?? []) as $stream) {
                    if (!($stream['url'] ?? null) || !str_contains($stream['type'] ?? '', 'video/mp4')) continue;
                    $downloadUrl = $stream['url'];
                    if (str_contains($stream['qualityLabel'] ?? '', '720')) break;
                }

                if (!$downloadUrl) continue;

                Http::withOptions(['sink' => $outputPath, 'timeout' => 3600, 'connect_timeout' => 30])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get($downloadUrl);

                if (file_exists($outputPath) && filesize($outputPath) > 10000) {
                    return true;
                }
            } catch (\Throwable $e) {
                Log::warning('Invidious error (background)', ['instance' => $instance, 'error' => $e->getMessage()]);
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
