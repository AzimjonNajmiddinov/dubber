<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SeparateStemsJob implements ShouldQueue
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
        $lock = Cache::lock("video:{$this->videoId}:stems", 1800);
        if (! $lock->get()) return;

        try {
            /** @var Video $video */
            $video = Video::query()->findOrFail($this->videoId);

            $inputRel = "audio/original/{$video->id}.wav";
            if (!Storage::disk('local')->exists($inputRel)) {
                throw new \RuntimeException("Original WAV not found: {$inputRel} (run ExtractAudioJob first)");
            }

            $url = "http://demucs:8000/separate";

            Log::info('Demucs separation request', [
                'video_id' => $video->id,
                'input_rel' => $inputRel,
                'url' => $url,
            ]);

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
                ]);
                throw new \RuntimeException("Demucs separation failed for video {$video->id}");
            }

            $noVocalsRel = $data['no_vocals_rel'] ?? null;
            if (!is_string($noVocalsRel) || !Storage::disk('local')->exists($noVocalsRel)) {
                throw new \RuntimeException("Demucs ok=true but no_vocals missing for video {$video->id}");
            }

            Log::info('Demucs separation done', [
                'video_id' => $video->id,
                'no_vocals' => $noVocalsRel,
            ]);

        } finally {
            optional($lock)->release();
        }
    }
}
