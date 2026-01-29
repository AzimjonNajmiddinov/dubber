<?php

namespace App\Services;

use App\Models\VideoSegment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class SegmentVideoService
{
    private const LOCK_TIMEOUT = 120; // 2 minutes max for generation
    private const PREFETCH_COUNT = 2;

    /**
     * Get storage path for a segment video clip.
     */
    public function getSegmentPath(VideoSegment $segment): string
    {
        return "videos/segments/{$segment->video_id}/seg_{$segment->id}.mp4";
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
        $path = $this->getSegmentPath($segment);
        return Storage::disk('local')->exists($path);
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
     * Returns the absolute path to the segment video, or null if generation fails.
     */
    public function getOrGenerateSegment(VideoSegment $segment): ?string
    {
        $path = $this->getSegmentPath($segment);

        // Check if already exists
        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->path($path);
        }

        // Try to acquire lock and generate
        $lock = Cache::lock($this->getLockKey($segment), self::LOCK_TIMEOUT);

        if ($lock->get()) {
            try {
                // Double-check after acquiring lock
                if (Storage::disk('local')->exists($path)) {
                    return Storage::disk('local')->path($path);
                }

                return $this->generateSegmentVideo($segment);
            } finally {
                $lock->release();
            }
        }

        // Lock not acquired, wait for generation
        $maxWait = self::LOCK_TIMEOUT;
        $waited = 0;
        $interval = 500000; // 0.5 seconds in microseconds

        while ($waited < $maxWait * 1000000) {
            if (Storage::disk('local')->exists($path)) {
                return Storage::disk('local')->path($path);
            }
            usleep($interval);
            $waited += $interval;
        }

        return null;
    }

    /**
     * Generate a segment video using FFmpeg.
     * Cuts the original video at segment timestamps and muxes with TTS audio.
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

        // TTS audio is optional (segment might not have been dubbed yet)
        $ttsPath = null;
        if ($segment->tts_audio_path) {
            $ttsPath = Storage::disk('local')->path($segment->tts_audio_path);
            if (!file_exists($ttsPath)) {
                Log::warning('TTS audio not found, using original audio', [
                    'segment_id' => $segment->id,
                    'tts_path' => $segment->tts_audio_path,
                ]);
                $ttsPath = null;
            }
        }

        // Ensure output directory exists
        $outputPath = $this->getSegmentPath($segment);
        $outputDir = dirname(Storage::disk('local')->path($outputPath));
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $absoluteOutputPath = Storage::disk('local')->path($outputPath);
        $startTime = $segment->start_time;
        $duration = $segment->end_time - $segment->start_time;

        // Build FFmpeg command
        if ($ttsPath) {
            // With TTS audio: cut video, replace audio
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
            // Without TTS: just cut video segment with original audio
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

            // Clean up partial file
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
                // Only prefetch segments that aren't ready and aren't being generated
                return !$this->isSegmentReady($segment) && !$this->isSegmentGenerating($segment);
            })
            ->all();
    }

    /**
     * Delete all cached segment videos for a video.
     */
    public function clearVideoSegments(int $videoId): void
    {
        $path = "videos/segments/{$videoId}";
        if (Storage::disk('local')->exists($path)) {
            Storage::disk('local')->deleteDirectory($path);
        }
    }
}
