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
 * Downloads video and dispatches chunk processing jobs IMMEDIATELY.
 * Demucs stem separation runs in background - chunks work without it if not ready.
 *
 * Optimized for fast processing of small segments from big movies:
 * - Configurable chunk duration (smaller = faster per-chunk, larger = fewer jobs)
 * - Prioritized first chunk dispatch
 * - Background stem separation that chunks can leverage when ready
 */
class StartChunkProcessingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 2;
    public int $uniqueFor = 3600;

    // Chunk duration in seconds - configurable via environment
    // Smaller = faster processing per chunk, but more overhead
    // Larger = slower per chunk, but less overhead
    // Recommended: 8-15 seconds for optimal balance
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

        // Step 1: Download if needed
        if (!$video->original_path || !Storage::disk('local')->exists($video->original_path)) {
            $this->downloadVideo($video);
            $video->refresh();
        }

        if (!$video->original_path) {
            throw new \RuntimeException('Video download failed');
        }

        // Step 2: Get video duration IMMEDIATELY
        $videoPath = Storage::disk('local')->path($video->original_path);
        $duration = $this->getVideoDuration($videoPath);

        if ($duration <= 0) {
            throw new \RuntimeException('Could not determine video duration');
        }

        // Step 3: Calculate chunks
        $chunks = $this->calculateChunks($duration);
        $video->update(['status' => 'processing_chunks']);

        Log::info('Starting chunk processing - dispatching immediately', [
            'video_id' => $video->id,
            'duration' => $duration,
            'total_chunks' => count($chunks),
        ]);

        // Step 4: Dispatch FIRST chunk with HIGH priority (no delay)
        if (!empty($chunks)) {
            ProcessVideoChunkJob::dispatch(
                $video->id,
                0,
                $chunks[0]['start'],
                $chunks[0]['end']
            )->onQueue('chunks');

            Log::info('First chunk dispatched immediately', ['video_id' => $video->id]);
        }

        // Step 5: Start Demucs in background (non-blocking)
        $this->startBackgroundStemSeparation($video);

        // Step 6: Dispatch remaining chunks (they'll process in parallel)
        foreach ($chunks as $index => $chunk) {
            if ($index === 0) continue; // Already dispatched

            // Small delay for chunks 2+ to let first chunk get ahead
            $delay = $index <= 3 ? $index * 2 : 5; // First few chunks staggered, rest same delay

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

    /**
     * Start stem separation in background - doesn't block chunk processing.
     */
    private function startBackgroundStemSeparation(Video $video): void
    {
        // Extract audio first (quick)
        $this->extractFullAudio($video);

        // Dispatch Demucs as separate background job
        SeparateStemsJob::dispatch($video->id)->onQueue('default');

        Log::info('Stem separation dispatched to background', ['video_id' => $video->id]);
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

    private function downloadVideo(Video $video): void
    {
        if (!$video->source_url) {
            throw new \RuntimeException('No source URL');
        }

        $video->update(['status' => 'downloading']);

        Storage::disk('local')->makeDirectory('videos/originals');
        $filename = Str::random(16) . '.mp4';
        $relativePath = "videos/originals/{$filename}";
        $absolutePath = Storage::disk('local')->path($relativePath);

        $url = $video->source_url;
        $isYouTube = str_contains($url, 'youtube.com') || str_contains($url, 'youtu.be');
        $isHLS = str_contains($url, '.m3u8');

        $downloaded = false;
        $lastError = '';

        if ($isYouTube) {
            // YouTube: try different clients
            $clients = ['android_vr', 'android', 'ios', 'web'];
            foreach ($clients as $client) {
                @unlink($absolutePath);

                Log::info('Trying YouTube download', [
                    'video_id' => $video->id,
                    'client' => $client,
                ]);

                $result = Process::timeout(1800)->run([
                    'yt-dlp',
                    '-f', 'bestvideo[height<=720][ext=mp4]+bestaudio[ext=m4a]/best[height<=720][ext=mp4]/best',
                    '--merge-output-format', 'mp4',
                    '-o', $absolutePath,
                    '--no-playlist',
                    '--extractor-args', "youtube:player_client={$client}",
                    $url,
                ]);

                if ($result->successful() && file_exists($absolutePath) && filesize($absolutePath) > 10000) {
                    $downloaded = true;
                    Log::info('Video downloaded', [
                        'video_id' => $video->id,
                        'client' => $client,
                        'size' => filesize($absolutePath),
                    ]);
                    break;
                }

                $lastError = $result->errorOutput() ?: $result->output();
            }
        } elseif ($isHLS) {
            // HLS stream: download best video + best audio and merge
            Log::info('Downloading HLS stream', ['video_id' => $video->id]);

            $result = Process::timeout(1800)->run([
                'yt-dlp',
                '-f', 'bestvideo+bestaudio/best',
                '--merge-output-format', 'mp4',
                '-o', $absolutePath,
                $url,
            ]);

            if ($result->successful() && file_exists($absolutePath) && filesize($absolutePath) > 10000) {
                $downloaded = true;
                Log::info('HLS stream downloaded', [
                    'video_id' => $video->id,
                    'size' => filesize($absolutePath),
                ]);
            } else {
                $lastError = $result->errorOutput() ?: $result->output();
            }
        } else {
            // Generic URL: try simple download
            Log::info('Downloading generic URL', ['video_id' => $video->id]);

            $result = Process::timeout(1800)->run([
                'yt-dlp',
                '-f', 'bestvideo[height<=720]+bestaudio/best[height<=720]/best',
                '--merge-output-format', 'mp4',
                '-o', $absolutePath,
                $url,
            ]);

            if ($result->successful() && file_exists($absolutePath) && filesize($absolutePath) > 10000) {
                $downloaded = true;
                Log::info('Video downloaded', [
                    'video_id' => $video->id,
                    'size' => filesize($absolutePath),
                ]);
            } else {
                $lastError = $result->errorOutput() ?: $result->output();
            }
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

    private function getVideoDuration(string $path): float
    {
        $result = Process::timeout(30)->run([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
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

    /**
     * Get optimal chunk duration based on video length and configuration.
     * Shorter videos use smaller chunks for faster completion.
     * Longer videos use slightly larger chunks to reduce job overhead.
     */
    private function getChunkDuration(float $totalDuration): float
    {
        // Environment override takes priority
        $envDuration = env('DUBBER_CHUNK_DURATION');
        if ($envDuration !== null && is_numeric($envDuration)) {
            return max(5, min(30, (float)$envDuration));
        }

        // Adaptive chunk sizing based on video length
        if ($totalDuration <= 60) {
            // Short videos (< 1 min): 8 second chunks for fast processing
            return 8;
        } elseif ($totalDuration <= 300) {
            // Medium videos (1-5 min): 10 second chunks
            return 10;
        } elseif ($totalDuration <= 1800) {
            // Long videos (5-30 min): 12 second chunks
            return 12;
        } else {
            // Very long videos (> 30 min): 15 second chunks to reduce overhead
            return 15;
        }
    }
}
