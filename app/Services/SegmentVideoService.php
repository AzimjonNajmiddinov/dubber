<?php

namespace App\Services;

use App\Models\VideoSegment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class SegmentVideoService
{
    private const LOCK_TIMEOUT = 120;
    private const PREFETCH_COUNT = 2;

    /**
     * Get storage path for a segment video clip.
     * Supports both chunk-based (new) and segment-based (old) paths.
     */
    public function getSegmentPath(VideoSegment $segment): string
    {
        // Check for chunk-based path first (new system)
        $chunkPath = $this->getChunkPath($segment);
        if (Storage::disk('local')->exists($chunkPath)) {
            return $chunkPath;
        }

        // Fall back to segment-based path (old system)
        return "videos/segments/{$segment->video_id}/seg_{$segment->id}.mp4";
    }

    /**
     * Get chunk-based path for a segment.
     */
    private function getChunkPath(VideoSegment $segment): string
    {
        // Find chunk index based on start_time (chunks are 12 seconds each)
        $chunkIndex = (int) floor($segment->start_time / 12);
        return "videos/chunks/{$segment->video_id}/seg_{$chunkIndex}.mp4";
    }

    /**
     * Get lock key for a segment.
     */
    private function getLockKey(VideoSegment $segment): string
    {
        return "segment_generation_{$segment->id}";
    }

    /**
     * Check if a segment video exists and is ready.
     */
    public function isSegmentReady(VideoSegment $segment): bool
    {
        // Check chunk path first
        $chunkPath = $this->getChunkPath($segment);
        if (Storage::disk('local')->exists($chunkPath)) {
            return true;
        }

        // Check segment path
        $segmentPath = "videos/segments/{$segment->video_id}/seg_{$segment->id}.mp4";
        return Storage::disk('local')->exists($segmentPath);
    }

    /**
     * Check if a segment is currently being generated.
     */
    public function isSegmentGenerating(VideoSegment $segment): bool
    {
        return Cache::has($this->getLockKey($segment));
    }

    /**
     * Get the absolute path to a segment video.
     */
    public function getAbsolutePath(VideoSegment $segment): ?string
    {
        $path = $this->getSegmentPath($segment);
        if (!Storage::disk('local')->exists($path)) {
            return null;
        }
        return Storage::disk('local')->path($path);
    }

    /**
     * Get or generate a segment video.
     * For chunk-based system, the video should already exist.
     * For segment-based system, generate on demand.
     */
    public function getOrGenerateSegment(VideoSegment $segment): ?string
    {
        // Check chunk path first (new system - already generated)
        $chunkPath = $this->getChunkPath($segment);
        if (Storage::disk('local')->exists($chunkPath)) {
            return Storage::disk('local')->path($chunkPath);
        }

        // Check segment path (old system)
        $segmentPath = "videos/segments/{$segment->video_id}/seg_{$segment->id}.mp4";
        if (Storage::disk('local')->exists($segmentPath)) {
            return Storage::disk('local')->path($segmentPath);
        }

        // Generate on demand (old system)
        $lock = Cache::lock($this->getLockKey($segment), self::LOCK_TIMEOUT);

        if ($lock->get()) {
            try {
                // Double-check after acquiring lock
                if (Storage::disk('local')->exists($segmentPath)) {
                    return Storage::disk('local')->path($segmentPath);
                }

                return $this->generateSegmentVideo($segment);
            } finally {
                $lock->release();
            }
        }

        // Lock not acquired, wait for generation
        $maxWait = self::LOCK_TIMEOUT;
        $waited = 0;
        $interval = 500000;

        while ($waited < $maxWait * 1000000) {
            if (Storage::disk('local')->exists($segmentPath)) {
                return Storage::disk('local')->path($segmentPath);
            }
            usleep($interval);
            $waited += $interval;
        }

        return null;
    }

    /**
     * Generate a segment video using FFmpeg (for old segment-based system).
     */
    public function generateSegmentVideo(VideoSegment $segment): ?string
    {
        $video = $segment->video;

        if (!$video || !$video->original_path) {
            Log::error('Segment generation failed: Video not found', ['segment_id' => $segment->id]);
            return null;
        }

        $originalPath = Storage::disk('local')->path($video->original_path);
        if (!file_exists($originalPath)) {
            Log::error('Segment generation failed: Original video file not found', [
                'segment_id' => $segment->id,
                'path' => $originalPath,
            ]);
            return null;
        }

        $ttsPath = null;
        if ($segment->tts_audio_path) {
            $ttsPath = Storage::disk('local')->path($segment->tts_audio_path);
            if (!file_exists($ttsPath)) {
                $ttsPath = null;
            }
        }

        $outputPath = "videos/segments/{$segment->video_id}/seg_{$segment->id}.mp4";
        $outputDir = dirname(Storage::disk('local')->path($outputPath));
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $absoluteOutputPath = Storage::disk('local')->path($outputPath);
        $startTime = $segment->start_time;
        $duration = $segment->end_time - $segment->start_time;

        if ($ttsPath) {
            $command = [
                'ffmpeg', '-y',
                '-ss', (string)$startTime,
                '-i', $originalPath,
                '-i', $ttsPath,
                '-t', (string)$duration,
                '-map', '0:v:0',
                '-map', '1:a:0',
                '-c:v', 'copy',
                '-c:a', 'aac',
                '-b:a', '128k',
                '-shortest',
                '-movflags', '+faststart',
                $absoluteOutputPath,
            ];
        } else {
            $command = [
                'ffmpeg', '-y',
                '-ss', (string)$startTime,
                '-i', $originalPath,
                '-t', (string)$duration,
                '-c:v', 'copy',
                '-c:a', 'aac',
                '-b:a', '128k',
                '-movflags', '+faststart',
                $absoluteOutputPath,
            ];
        }

        Log::info('Generating segment video', [
            'segment_id' => $segment->id,
            'command' => implode(' ', $command),
        ]);

        $process = new Process($command);
        $process->setTimeout(self::LOCK_TIMEOUT);

        try {
            $process->mustRun();

            Log::info('Segment video generated', [
                'segment_id' => $segment->id,
                'output' => $absoluteOutputPath,
            ]);

            return $absoluteOutputPath;
        } catch (\Exception $e) {
            Log::error('FFmpeg segment generation failed', [
                'segment_id' => $segment->id,
                'error' => $e->getMessage(),
                'stderr' => $process->getErrorOutput(),
            ]);

            if (file_exists($absoluteOutputPath)) {
                unlink($absoluteOutputPath);
            }

            return null;
        }
    }

    /**
     * Get segment IDs to prefetch based on current position.
     */
    public function getSegmentsToPrefetch(VideoSegment $currentSegment, ?int $count = null): array
    {
        $count = $count ?? self::PREFETCH_COUNT;
        $video = $currentSegment->video;

        if (!$video) {
            return [];
        }

        return $video->segments()
            ->where('start_time', '>', $currentSegment->start_time)
            ->orderBy('start_time')
            ->limit($count)
            ->get()
            ->filter(function ($segment) {
                return !$this->isSegmentReady($segment) && !$this->isSegmentGenerating($segment);
            })
            ->all();
    }

    /**
     * Delete all cached segment videos for a video.
     */
    public function clearVideoSegments(int $videoId): void
    {
        $paths = ["videos/segments/{$videoId}", "videos/chunks/{$videoId}"];
        foreach ($paths as $path) {
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->deleteDirectory($path);
            }
        }
    }
}
