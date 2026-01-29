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
use Illuminate\Support\Str;

/**
 * Downloads video from URL for the streaming pipeline.
 * After download, dispatches the streaming extraction job.
 */
class DownloadVideoForStreamingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 3;
    public int $uniqueFor = 3600;
    public array $backoff = [60, 120, 300];

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return 'streaming_download_' . $this->videoId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DownloadVideoForStreamingJob failed', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        try {
            Video::where('id', $this->videoId)->update(['status' => 'download_failed']);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function handle(): void
    {
        $video = Video::findOrFail($this->videoId);

        if (!$video->source_url) {
            throw new \RuntimeException("No source URL for video {$video->id}");
        }

        Log::info('Starting streaming video download', [
            'video_id' => $video->id,
            'url' => $video->source_url,
        ]);

        $video->update(['status' => 'downloading']);

        Storage::disk('local')->makeDirectory('videos/originals');

        $filename = Str::random(16) . '.mp4';
        $relativePath = "videos/originals/{$filename}";
        $absolutePath = Storage::disk('local')->path($relativePath);

        // Try yt-dlp download
        if (!$this->downloadWithYtDlp($video->source_url, $absolutePath)) {
            // Try direct download as fallback
            if (!$this->downloadDirect($video->source_url, $absolutePath)) {
                throw new \RuntimeException("Failed to download video");
            }
        }

        // Verify it's a valid video
        $this->verifyVideo($absolutePath);

        $video->update([
            'original_path' => $relativePath,
            'status' => 'uploaded',
        ]);

        Log::info('Video downloaded, starting streaming pipeline', [
            'video_id' => $video->id,
            'path' => $relativePath,
        ]);

        // Dispatch streaming pipeline (extract -> transcribe -> process segments)
        ExtractAudioForStreamingJob::dispatch($video->id);
    }

    private function downloadWithYtDlp(string $url, string $outputPath): bool
    {
        $clients = ['web', 'android', 'ios', 'tv_embedded'];

        foreach ($clients as $client) {
            @unlink($outputPath);

            $result = Process::timeout(3600)->run([
                'yt-dlp',
                '-f', 'bestvideo[ext=mp4][height<=1080]+bestaudio[ext=m4a]/best[ext=mp4][height<=1080]/best',
                '--merge-output-format', 'mp4',
                '-o', $outputPath,
                '--no-playlist',
                '--no-warnings',
                '--extractor-args', "youtube:player_client={$client}",
                '--user-agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                '--force-ipv4',
                '--retries', '3',
                $url,
            ]);

            if ($result->successful() && file_exists($outputPath) && filesize($outputPath) > 10000) {
                Log::info('yt-dlp download successful', [
                    'url' => $url,
                    'client' => $client,
                    'size' => filesize($outputPath),
                ]);
                return true;
            }
        }

        return false;
    }

    private function downloadDirect(string $url, string $outputPath): bool
    {
        $skipDomains = ['youtube.com', 'youtu.be', 'vimeo.com', 'dailymotion.com', 'tiktok.com'];
        foreach ($skipDomains as $domain) {
            if (str_contains($url, $domain)) {
                return false;
            }
        }

        try {
            $ch = curl_init($url);
            $fp = fopen($outputPath, 'w');
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            return $httpCode === 200 && file_exists($outputPath) && filesize($outputPath) > 10000;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function verifyVideo(string $path): void
    {
        $result = Process::timeout(60)->run([
            'ffprobe', '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height',
            '-of', 'json',
            $path,
        ]);

        if (!$result->successful()) {
            @unlink($path);
            throw new \RuntimeException('Downloaded file is not a valid video');
        }

        $data = json_decode($result->output(), true);
        if (empty($data['streams'])) {
            @unlink($path);
            throw new \RuntimeException('Downloaded file has no video stream');
        }
    }
}
