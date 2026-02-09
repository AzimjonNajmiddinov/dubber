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

            $inputAbs = Storage::disk('local')->path($inputRel);
            $fileSize = filesize($inputAbs);
            $estimatedDuration = $fileSize / (48000 * 2 * 2); // Rough estimate for 48kHz stereo 16-bit

            Log::info('Starting full stem separation', [
                'video_id' => $video->id,
                'input_rel' => $inputRel,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                'estimated_duration_sec' => round($estimatedDuration, 0),
            ]);

            $demucsUrl = config('services.demucs.url', 'http://demucs:8000');
            $isRemote = $this->isRemoteService($demucsUrl);

            if ($isRemote) {
                $this->separateViaRemote($video, $inputAbs, $demucsUrl);
            } else {
                $this->separateViaLocal($video, $inputRel, $demucsUrl);
            }

        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Check if demucs URL is a remote service (RunPod) vs local Docker.
     */
    private function isRemoteService(string $url): bool
    {
        return str_contains($url, 'runpod.net') || str_contains($url, 'https://');
    }

    /**
     * Separate stems via remote RunPod service (file upload/download).
     */
    private function separateViaRemote(Video $video, string $inputAbs, string $demucsUrl): void
    {
        Log::info('Using remote Demucs (RunPod)', ['video_id' => $video->id, 'url' => $demucsUrl]);

        // Upload audio file to RunPod
        $res = Http::timeout(600)
            ->attach('audio', file_get_contents($inputAbs), "{$video->id}.wav")
            ->post("{$demucsUrl}/separate", [
                'model' => 'htdemucs',
                'two_stems' => 'vocals',
            ]);

        $data = $res->json();

        if (!$res->successful() || !is_array($data) || !($data['ok'] ?? false)) {
            Log::error('Remote Demucs failed', [
                'video_id' => $video->id,
                'http_status' => $res->status(),
                'body' => mb_substr($res->body(), 0, 4000),
            ]);
            throw new \RuntimeException("Remote Demucs separation failed for video {$video->id}");
        }

        // Download no_vocals.wav
        $noVocalsUrl = $data['no_vocals_url'] ?? null;
        if (!$noVocalsUrl) {
            throw new \RuntimeException("Remote Demucs ok=true but no download URL for video {$video->id}");
        }

        $downloadUrl = rtrim($demucsUrl, '/') . $noVocalsUrl;
        $noVocalsRes = Http::timeout(300)->get($downloadUrl);

        if (!$noVocalsRes->successful()) {
            throw new \RuntimeException("Failed to download no_vocals from RunPod for video {$video->id}");
        }

        // Save to local storage
        $stemsDir = "audio/stems/{$video->id}";
        Storage::disk('local')->makeDirectory($stemsDir);
        $noVocalsRel = "{$stemsDir}/no_vocals.wav";
        Storage::disk('local')->put($noVocalsRel, $noVocalsRes->body());

        // Optionally download vocals too
        if ($vocalsUrl = $data['vocals_url'] ?? null) {
            $vocalsDownloadUrl = rtrim($demucsUrl, '/') . $vocalsUrl;
            $vocalsRes = Http::timeout(300)->get($vocalsDownloadUrl);
            if ($vocalsRes->successful()) {
                Storage::disk('local')->put("{$stemsDir}/vocals.wav", $vocalsRes->body());
            }
        }

        Log::info('Remote stem separation complete', [
            'video_id' => $video->id,
            'no_vocals' => $noVocalsRel,
            'elapsed' => $data['elapsed_seconds'] ?? null,
            'cached' => $data['cached'] ?? false,
        ]);
    }

    /**
     * Separate stems via local Docker service (shared storage).
     */
    private function separateViaLocal(Video $video, string $inputRel, string $demucsUrl): void
    {
        Log::info('Using local Demucs (Docker)', ['video_id' => $video->id]);

        $res = Http::timeout(1800)
            ->retry(2, 2000)
            ->post("{$demucsUrl}/separate", [
                'video_id' => $video->id,
                'input_rel' => $inputRel,
                'model' => 'htdemucs',
                'two_stems' => 'vocals',
            ]);

        $data = $res->json();

        if (!$res->successful() || !is_array($data) || !($data['ok'] ?? false)) {
            Log::error('Local Demucs failed', [
                'video_id' => $video->id,
                'http_status' => $res->status(),
                'body' => mb_substr($res->body(), 0, 4000),
                'json' => $data,
            ]);
            throw new \RuntimeException("Local Demucs separation failed for video {$video->id}");
        }

        $noVocalsRel = $data['no_vocals_rel'] ?? null;
        if (!is_string($noVocalsRel) || !Storage::disk('local')->exists($noVocalsRel)) {
            throw new \RuntimeException("Demucs ok=true but no_vocals missing for video {$video->id}");
        }

        Log::info('Local stem separation complete', [
            'video_id' => $video->id,
            'no_vocals' => $noVocalsRel,
            'cached' => $data['cached'] ?? false,
        ]);
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
