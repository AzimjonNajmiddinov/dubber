<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SeparateStemsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1800;

    /**
     * Exponential backoff between retries (seconds).
     */
    public array $backoff = [30, 60, 120];

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    /**
     * Handle job failure - log warning (stem separation failure is recoverable).
     * Chunks will continue with segment-based separation or original audio.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('SeparateStemsJob failed - chunks will use segment separation', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function handle(): void
    {
        // Check if stems already exist (skip if already done)
        if ($this->stemsAlreadyExist()) {
            Log::info('Stems already exist, skipping separation', ['video_id' => $this->videoId]);
            return;
        }

        $lock = Cache::lock("video:{$this->videoId}:stems", 1800);
        if (!$lock->get()) {
            Log::debug('Could not acquire lock for stem separation', ['video_id' => $this->videoId]);
            return;
        }

        try {
            /** @var Video $video */
            $video = Video::query()->findOrFail($this->videoId);

            $inputRel = "audio/original/{$video->id}.wav";
            if (!Storage::disk('local')->exists($inputRel)) {
                throw new \RuntimeException("Original WAV not found: {$inputRel} (run ExtractAudioJob first)");
            }

            // Check file size to estimate processing time
            $fileSize = Storage::disk('local')->size($inputRel);
            $estimatedDuration = $fileSize / (48000 * 2 * 2); // Rough estimate for 48kHz stereo 16-bit

            Log::info('Starting full stem separation', [
                'video_id' => $video->id,
                'input_rel' => $inputRel,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                'estimated_duration_sec' => round($estimatedDuration, 0),
            ]);

            $url = "http://demucs:8000/separate";

            $res = Http::timeout(1800)
                ->retry(2, 2000)
                ->post($url, [
                    'video_id' => $video->id,
                    'input_rel' => $inputRel,
                    'model' => 'htdemucs',
                    'two_stems' => 'vocals',
                ]);

            $data = $res->json();

            if (!$res->successful() || !is_array($data) || !($data['ok'] ?? false)) {
                Log::error('Demucs failed', [
                    'video_id' => $video->id,
                    'http_status' => $res->status(),
                    'body' => mb_substr($res->body(), 0, 4000),
                    'json' => $data,
                    'cached' => $data['cached'] ?? false,
                ]);
                throw new \RuntimeException("Demucs separation failed for video {$video->id}");
            }

            $noVocalsRel = $data['no_vocals_rel'] ?? null;
            if (!is_string($noVocalsRel) || !Storage::disk('local')->exists($noVocalsRel)) {
                throw new \RuntimeException("Demucs ok=true but no_vocals missing for video {$video->id}");
            }

            Log::info('Full stem separation complete', [
                'video_id' => $video->id,
                'no_vocals' => $noVocalsRel,
                'cached' => $data['cached'] ?? false,
            ]);

        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Check if stems already exist for this video.
     */
    private function stemsAlreadyExist(): bool
    {
        $stemsDir = "audio/stems/{$this->videoId}";

        foreach (['no_vocals.wav', 'no_vocals.flac'] as $filename) {
            if (Storage::disk('local')->exists("{$stemsDir}/{$filename}")) {
                $size = Storage::disk('local')->size("{$stemsDir}/{$filename}");
                if ($size > 1000) {
                    return true;
                }
            }
        }

        return false;
    }
}
