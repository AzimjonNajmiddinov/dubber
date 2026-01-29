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

/**
 * Extracts audio for the streaming pipeline.
 * After extraction, dispatches transcription for streaming.
 */
class ExtractAudioForStreamingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1800;
    public array $backoff = [30, 60, 120];

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return 'streaming_extract_' . $this->videoId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExtractAudioForStreamingJob failed', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        Video::where('id', $this->videoId)->update(['status' => 'failed']);
    }

    public function handle(): void
    {
        $video = Video::findOrFail($this->videoId);

        $videoAbs = Storage::disk('local')->path($video->original_path);
        if (!file_exists($videoAbs)) {
            throw new \RuntimeException("Video not found: {$video->original_path}");
        }

        Log::info('Extracting audio for streaming', ['video_id' => $video->id]);

        // Extract STT audio (mono 16kHz for WhisperX)
        $sttRel = "audio/stt/{$video->id}.wav";
        Storage::disk('local')->makeDirectory('audio/stt');
        $sttAbs = Storage::disk('local')->path($sttRel);
        @unlink($sttAbs);

        $r1 = Process::timeout(1800)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $videoAbs,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le',
            $sttAbs,
        ]);

        if (!$r1->successful() || !file_exists($sttAbs) || filesize($sttAbs) < 2000) {
            throw new \RuntimeException('STT audio extraction failed');
        }

        // Extract original audio (stereo 48kHz for mixing)
        $origRel = "audio/original/{$video->id}.wav";
        Storage::disk('local')->makeDirectory('audio/original');
        $origAbs = Storage::disk('local')->path($origRel);
        @unlink($origAbs);

        $r2 = Process::timeout(1800)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $videoAbs,
            '-vn', '-ac', '2', '-ar', '48000', '-c:a', 'pcm_s16le',
            $origAbs,
        ]);

        if (!$r2->successful() || !file_exists($origAbs) || filesize($origAbs) < 5000) {
            throw new \RuntimeException('Original audio extraction failed');
        }

        $video->update([
            'audio_path' => $sttRel,
            'status' => 'audio_extracted',
        ]);

        Log::info('Audio extracted for streaming, dispatching transcription', [
            'video_id' => $video->id,
            'stt_path' => $sttRel,
        ]);

        // Dispatch transcription for streaming
        TranscribeForStreamingJob::dispatch($video->id);
    }
}
