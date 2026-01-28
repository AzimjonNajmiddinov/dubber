<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ExtractAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;

    /**
     * Exponential backoff between retries (seconds).
     */
    public array $backoff = [30, 60, 120];

    public function __construct(public int $videoId) {}

    public function handle(): void
    {
        /** @var Video $video */
        $video = Video::query()->findOrFail($this->videoId);

        $videoAbs = Storage::disk('local')->path($video->original_path);
        if (!file_exists($videoAbs)) {
            throw new \RuntimeException("Video not found: {$video->original_path}");
        }

        // ---------- 1) STT audio (mono 16k) ----------
        $sttRel = "audio/stt/{$video->id}.wav";
        Storage::disk('local')->makeDirectory('audio/stt');
        $sttAbs = Storage::disk('local')->path($sttRel);

        @unlink($sttAbs);

        $r1 = Process::timeout(1800)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $videoAbs,
            '-vn',
            '-ac', '1',
            '-ar', '16000',
            '-c:a', 'pcm_s16le',
            $sttAbs,
        ]);

        $sttSize = file_exists($sttAbs) ? (int) filesize($sttAbs) : 0;

        if (!$r1->successful() || $sttSize < 2000) {
            Log::error('FFmpeg STT extract failed', [
                'video_id' => $video->id,
                'exit_code' => $r1->exitCode(),
                'stt_abs' => $sttAbs,
                'stt_size' => $sttSize,
                'stdout' => mb_substr($r1->output(), 0, 2000),
                'stderr' => mb_substr($r1->errorOutput(), 0, 4000),
            ]);
            throw new \RuntimeException('STT audio extraction failed');
        }

        // ---------- 2) Original audio for mixing (stereo 48k) ----------
        $origRel = "audio/original/{$video->id}.wav";
        Storage::disk('local')->makeDirectory('audio/original');
        $origAbs = Storage::disk('local')->path($origRel);

        @unlink($origAbs);

        $r2 = Process::timeout(1800)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $videoAbs,
            '-vn',
            '-ac', '2',
            '-ar', '48000',
            '-c:a', 'pcm_s16le',
            $origAbs,
        ]);

        $origSize = file_exists($origAbs) ? (int) filesize($origAbs) : 0;

        if (!$r2->successful() || $origSize < 5000) {
            Log::error('FFmpeg original extract failed', [
                'video_id' => $video->id,
                'exit_code' => $r2->exitCode(),
                'orig_abs' => $origAbs,
                'orig_size' => $origSize,
                'stdout' => mb_substr($r2->output(), 0, 2000),
                'stderr' => mb_substr($r2->errorOutput(), 0, 4000),
            ]);
            throw new \RuntimeException('Original audio extraction failed');
        }

        // Persist for WhisperX (relative path)
        $video->update([
            'audio_path' => $sttRel,          // used by TranscribeWithWhisperXJob
            'status' => 'audio_extracted',
        ]);

        Log::info('Audio extracted; dispatching stems + transcribe', [
            'video_id' => $video->id,
            'stt_rel' => $sttRel,
            'stt_size' => $sttSize,
            'orig_rel' => $origRel,
            'orig_size' => $origSize,
        ]);

        // Run separation (realistic bed) + transcription in parallel.
        SeparateStemsJob::dispatch($video->id);
        TranscribeWithWhisperXJob::dispatch($video->id);
    }
}
