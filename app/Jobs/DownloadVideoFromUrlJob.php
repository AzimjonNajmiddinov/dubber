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

        // Try yt-dlp first (handles YouTube, Vimeo, and many other sites)
        if ($this->tryYtDlp($url, $absolutePath)) {
            $this->finalizeDownload($video, $relativePath, $absolutePath);
            return;
        }

        // Fallback to direct HTTP download
        if ($this->tryDirectDownload($url, $absolutePath)) {
            $this->finalizeDownload($video, $relativePath, $absolutePath);
            return;
        }

        throw new \RuntimeException("Failed to download video from URL: {$url}");
    }

    private function tryYtDlp(string $url, string $outputPath): bool
    {
        Log::info('Attempting yt-dlp download', ['url' => $url]);

        // Use yt-dlp with best quality mp4
        $result = Process::timeout(3600)->run([
            'yt-dlp',
            '-f', 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best',
            '--merge-output-format', 'mp4',
            '-o', $outputPath,
            '--no-playlist',
            '--no-warnings',
            $url,
        ]);

        if ($result->successful() && file_exists($outputPath) && filesize($outputPath) > 10000) {
            Log::info('yt-dlp download successful', [
                'url' => $url,
                'size' => filesize($outputPath),
            ]);
            return true;
        }

        Log::warning('yt-dlp download failed', [
            'url' => $url,
            'exit_code' => $result->exitCode(),
            'stderr' => mb_substr($result->errorOutput(), 0, 2000),
        ]);

        return false;
    }

    private function tryDirectDownload(string $url, string $outputPath): bool
    {
        Log::info('Attempting direct HTTP download', ['url' => $url]);

        try {
            // Stream download to file
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
        // Verify it's a valid video file using ffprobe
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

        // Dispatch the regular pipeline
        ExtractAudioJob::dispatch($video->id);
    }
}
