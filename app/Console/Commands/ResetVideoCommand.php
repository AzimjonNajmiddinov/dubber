<?php

namespace App\Console\Commands;

use App\Models\Speaker;
use App\Models\Video;
use App\Models\VideoSegment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResetVideoCommand extends Command
{
    protected $signature = 'video:reset
                            {video? : Video ID to reset (omit for all)}
                            {--keep-original : Keep the original video file}
                            {--all : Reset all videos}';

    protected $description = 'Reset video data for re-testing. Clears segments, speakers, audio, and generated files.';

    public function handle(): int
    {
        $videoId = $this->argument('video');
        $keepOriginal = $this->option('keep-original');
        $all = $this->option('all');

        if (!$videoId && !$all) {
            $this->error('Please provide a video ID or use --all flag');
            return 1;
        }

        if ($all) {
            if (!$this->confirm('This will reset ALL videos. Are you sure?')) {
                return 0;
            }
            $videos = Video::all();
        } else {
            $video = Video::find($videoId);
            if (!$video) {
                $this->error("Video #{$videoId} not found");
                return 1;
            }
            $videos = collect([$video]);
        }

        foreach ($videos as $video) {
            $this->resetVideo($video, $keepOriginal);
        }

        // Clear all cache (includes ShouldBeUnique locks)
        Cache::flush();
        $this->info('Cache cleared');

        // Flush Redis completely to clear any stale unique job locks
        try {
            $redis = Cache::getStore()->getRedis();
            $redis->flushall();
            $this->info('Redis flushed (all unique job locks cleared)');
        } catch (\Throwable $e) {
            // Fallback if Redis not available directly
            $this->warn('Could not flush Redis directly: ' . $e->getMessage());
        }

        // Clear failed jobs
        DB::table('failed_jobs')->truncate();
        $this->info('Failed jobs cleared');

        // Clear pending jobs
        DB::table('jobs')->truncate();
        $this->info('Pending jobs cleared');

        $this->info('Done! Ready for re-testing.');

        return 0;
    }

    private function resetVideo(Video $video, bool $keepOriginal): void
    {
        $this->info("Resetting video #{$video->id}...");

        // Delete segments
        $segmentCount = VideoSegment::where('video_id', $video->id)->count();
        VideoSegment::where('video_id', $video->id)->delete();
        $this->line("  - Deleted {$segmentCount} segments");

        // Delete speakers
        $speakerCount = Speaker::where('video_id', $video->id)->count();
        Speaker::where('video_id', $video->id)->delete();
        $this->line("  - Deleted {$speakerCount} speakers");

        // Delete generated files
        $directories = [
            "audio/tts/{$video->id}",
            "audio/stems/{$video->id}",
            "audio/stt",
            "audio/original",
            "audio/final",
            "audio/voice_samples/{$video->id}",
            "videos/segments/{$video->id}",
            "videos/chunks/{$video->id}",
            "videos/dubbed",
        ];

        foreach ($directories as $dir) {
            if (Storage::disk('local')->exists($dir)) {
                // For directories with video ID, delete whole dir
                if (str_contains($dir, (string)$video->id)) {
                    Storage::disk('local')->deleteDirectory($dir);
                    $this->line("  - Deleted {$dir}/");
                } else {
                    // For shared directories, delete only files for this video
                    $this->deleteVideoFiles($dir, $video->id);
                }
            }
        }

        // Delete specific files
        $files = [
            $video->audio_path,
            $video->vocals_path,
            $video->music_path,
            $video->final_audio_path,
            $video->dubbed_path,
            $video->lipsynced_path,
        ];

        foreach (array_filter($files) as $file) {
            if (Storage::disk('local')->exists($file)) {
                Storage::disk('local')->delete($file);
                $this->line("  - Deleted {$file}");
            }
        }

        // Optionally delete original
        if (!$keepOriginal && $video->original_path) {
            if (Storage::disk('local')->exists($video->original_path)) {
                Storage::disk('local')->delete($video->original_path);
                $this->line("  - Deleted original: {$video->original_path}");
            }
        }

        // Reset video record
        $video->update([
            'status' => $keepOriginal && $video->original_path ? 'uploaded' : 'pending',
            'audio_path' => null,
            'vocals_path' => null,
            'music_path' => null,
            'final_audio_path' => null,
            'dubbed_path' => null,
            'lipsynced_path' => null,
        ]);

        $this->info("  Video #{$video->id} reset to '{$video->status}' status");
    }

    private function deleteVideoFiles(string $dir, int $videoId): void
    {
        $files = Storage::disk('local')->files($dir);
        foreach ($files as $file) {
            if (str_contains($file, (string)$videoId) || str_contains($file, "_{$videoId}.")) {
                Storage::disk('local')->delete($file);
                $this->line("  - Deleted {$file}");
            }
        }
    }
}
