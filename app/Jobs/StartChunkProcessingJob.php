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
 * Downloads video and dispatches chunk processing jobs.
 * Each chunk is processed independently for real-time playback.
 */
class StartChunkProcessingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 2;
    public int $uniqueFor = 3600;

    // Chunk duration in seconds
    private const CHUNK_DURATION = 12;

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

        // Step 2: Get video duration
        $videoPath = Storage::disk('local')->path($video->original_path);
        $duration = $this->getVideoDuration($videoPath);

        if ($duration <= 0) {
            throw new \RuntimeException('Could not determine video duration');
        }

        Log::info('Starting chunk processing', [
            'video_id' => $video->id,
            'duration' => $duration,
            'chunk_size' => self::CHUNK_DURATION,
        ]);

        // Step 3: Calculate chunks and dispatch jobs
        $chunks = $this->calculateChunks($duration);
        $video->update(['status' => 'processing_chunks']);

        foreach ($chunks as $index => $chunk) {
            ProcessVideoChunkJob::dispatch(
                $video->id,
                $index,
                $chunk['start'],
                $chunk['end']
            )->onQueue('chunks');

            Log::info('Dispatched chunk job', [
                'video_id' => $video->id,
                'chunk' => $index,
                'start' => $chunk['start'],
                'end' => $chunk['end'],
            ]);
        }

        Log::info('All chunk jobs dispatched', [
            'video_id' => $video->id,
            'total_chunks' => count($chunks),
        ]);
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

        // Try yt-dlp
        $clients = ['web', 'android', 'ios'];
        $downloaded = false;

        foreach ($clients as $client) {
            @unlink($absolutePath);

            $result = Process::timeout(1800)->run([
                'yt-dlp',
                '-f', 'bestvideo[ext=mp4][height<=720]+bestaudio[ext=m4a]/best[ext=mp4][height<=720]/best',
                '--merge-output-format', 'mp4',
                '-o', $absolutePath,
                '--no-playlist',
                '--extractor-args', "youtube:player_client={$client}",
                $video->source_url,
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
        }

        if (!$downloaded) {
            $video->update(['status' => 'download_failed']);
            throw new \RuntimeException('Video download failed');
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

        if (!$result->successful()) {
            return 0;
        }

        return (float) trim($result->output());
    }

    private function calculateChunks(float $duration): array
    {
        $chunks = [];
        $start = 0;
        $index = 0;

        while ($start < $duration) {
            $end = min($start + self::CHUNK_DURATION, $duration);
            $chunks[] = [
                'start' => $start,
                'end' => $end,
            ];
            $start = $end;
            $index++;
        }

        return $chunks;
    }
}
