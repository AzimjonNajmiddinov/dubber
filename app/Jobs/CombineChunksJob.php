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

class CombineChunksJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 2;
    public int $uniqueFor = 600;

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return 'combine_chunks_' . $this->videoId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CombineChunksJob failed', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function handle(): void
    {
        $video = Video::find($this->videoId);
        if (!$video) {
            return;
        }

        $video->update(['status' => 'combining_chunks']);

        Log::info('Combining chunks into final video', ['video_id' => $this->videoId]);

        $chunkDir = "videos/chunks/{$this->videoId}";
        $chunkDirAbs = Storage::disk('local')->path($chunkDir);

        if (!is_dir($chunkDirAbs)) {
            Log::error('Chunk directory not found', ['video_id' => $this->videoId]);
            return;
        }

        // Collect chunk files in order
        $chunks = [];
        $index = 0;
        while (true) {
            $chunkFile = "{$chunkDirAbs}/seg_{$index}.mp4";
            if (!file_exists($chunkFile)) break;
            $chunks[] = $chunkFile;
            $index++;
        }

        if (empty($chunks)) {
            Log::error('No chunk files found', ['video_id' => $this->videoId]);
            return;
        }

        Log::info('Found chunks to combine', [
            'video_id' => $this->videoId,
            'count' => count($chunks),
        ]);

        // Create concat file
        $concatFile = "{$chunkDirAbs}/concat.txt";
        $content = implode("\n", array_map(fn($f) => "file '" . basename($f) . "'", $chunks));
        file_put_contents($concatFile, $content);

        // Concatenate with ffmpeg
        Storage::disk('local')->makeDirectory('videos/dubbed');
        $outputPath = "videos/dubbed/{$this->videoId}_dubbed.mp4";
        $outputAbs = Storage::disk('local')->path($outputPath);

        $result = Process::timeout(600)->path($chunkDirAbs)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'concat', '-safe', '0',
            '-i', 'concat.txt',
            '-c', 'copy',
            '-movflags', '+faststart',
            $outputAbs,
        ]);

        @unlink($concatFile);

        if (!$result->successful() || !file_exists($outputAbs)) {
            Log::error('FFmpeg concat failed', [
                'video_id' => $this->videoId,
                'error' => $result->errorOutput(),
            ]);
            $video->update(['status' => 'processing_chunks']);
            return;
        }

        // Update video with dubbed path
        $video->update([
            'dubbed_path' => $outputPath,
            'status' => 'dubbed_complete',
        ]);

        Log::info('Video combined successfully', [
            'video_id' => $this->videoId,
            'output' => $outputPath,
            'size' => filesize($outputAbs),
        ]);

        // Cleanup temp files
        $this->cleanupTempFiles($chunkDirAbs);
    }

    private function cleanupTempFiles(string $chunkDirAbs): void
    {
        $patterns = [
            'audio_*.wav',
            'audio_hq_*.wav',
            'bg_music_*.wav',
            'bg_track_*.wav',
            'vocals_*.wav',
            'final_*.wav',
            'tts_*.wav',
            'tts_*.mp3',
            'tts_raw_*.mp3',
            'tts_adj_*.wav',
            'xtts_*.wav',
        ];

        $deleted = 0;
        foreach ($patterns as $pattern) {
            foreach (glob("{$chunkDirAbs}/{$pattern}") as $file) {
                @unlink($file);
                $deleted++;
            }
        }

        // Clean TTS directory
        $ttsDir = Storage::disk('local')->path("audio/tts/{$this->videoId}");
        if (is_dir($ttsDir)) {
            foreach (glob("{$ttsDir}/*.wav") as $file) {
                @unlink($file);
                $deleted++;
            }
            foreach (glob("{$ttsDir}/*.mp3") as $file) {
                @unlink($file);
                $deleted++;
            }
            @rmdir($ttsDir);
        }

        // Clean voice samples directory
        $samplesDir = Storage::disk('local')->path("audio/voice_samples/{$this->videoId}");
        if (is_dir($samplesDir)) {
            foreach (glob("{$samplesDir}/*") as $file) {
                @unlink($file);
                $deleted++;
            }
            @rmdir($samplesDir);
        }

        Log::info('Temp files cleaned up', [
            'video_id' => $this->videoId,
            'deleted' => $deleted,
        ]);
    }
}
