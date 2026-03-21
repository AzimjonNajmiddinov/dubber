<?php

namespace App\Jobs\PremiumDub;

use App\Services\RunPod\RunPodClient;
use App\Services\RunPod\RunPodStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PremiumDubTranscribeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 2;

    public function __construct(
        public string $dubId,
    ) {}

    public function handle(): void
    {
        $session = $this->getSession();
        $audioPath = $session['audio_path'] ?? null;

        if (!$audioPath || !file_exists($audioPath)) {
            $this->updateStatus('error', 'Audio file not found for transcription');
            return;
        }

        $this->updateStatus('transcribing', 'Transcribing audio with WhisperX...');

        $storage = new RunPodStorage();
        $client = new RunPodClient();
        $endpointId = config('services.runpod.whisperx_endpoint_id');

        try {
            // Upload audio to S3
            $s3Path = "premium-dub/{$this->dubId}/audio_whisperx.wav";
            $audioUrl = $storage->upload($audioPath, $s3Path);

            // Submit to RunPod WhisperX
            $jobId = $client->submitJob($endpointId, [
                'audio_url' => $audioUrl,
            ]);

            // Poll until complete (up to 20 min)
            $result = $client->pollJob($endpointId, $jobId, 1200, 8);

            // Cleanup S3
            $storage->delete($s3Path);

            $segments = $result['segments'] ?? [];
            $speakers = $result['speakers'] ?? [];
            $language = $result['language'] ?? 'unknown';

            if (empty($segments)) {
                $this->updateStatus('error', 'WhisperX returned no segments');
                return;
            }

            // Store results in Redis
            $this->updateSession([
                'transcription_ready' => true,
                'detected_language' => $language,
                'segments' => $segments,
                'speakers_info' => $speakers,
                'total_segments' => count($segments),
            ]);

            Log::info("[PREMIUM] [{$this->dubId}] Transcription complete: {$language}, " . count($segments) . " segments, " . count($speakers) . " speakers");

            // Check if stems are also done → dispatch next step
            $this->checkAndDispatchNext();

        } catch (\Throwable $e) {
            $this->updateStatus('error', 'Transcription failed: ' . Str::limit($e->getMessage(), 100));
            Log::error("[PREMIUM] [{$this->dubId}] WhisperX failed: " . $e->getMessage());
        }
    }

    private function checkAndDispatchNext(): void
    {
        $session = $this->getSession();
        if (!empty($session['stems_ready']) && !empty($session['transcription_ready'])) {
            PremiumDubTranslateJob::dispatch($this->dubId)->onQueue('default');
        }
    }

    private function getSession(): array
    {
        $json = Redis::get("premium-dub:{$this->dubId}");
        return $json ? json_decode($json, true) : [];
    }

    private function updateStatus(string $status, string $progress = ''): void
    {
        $key = "premium-dub:{$this->dubId}";
        $json = Redis::get($key);
        $session = $json ? json_decode($json, true) : [];
        $session['status'] = $status;
        if ($progress) $session['progress'] = $progress;
        Redis::setex($key, 86400, json_encode($session));
    }

    private function updateSession(array $data): void
    {
        $key = "premium-dub:{$this->dubId}";
        $json = Redis::get($key);
        $session = $json ? json_decode($json, true) : [];
        Redis::setex($key, 86400, json_encode(array_merge($session, $data)));
    }

    public function failed(\Throwable $e): void
    {
        $this->updateStatus('error', 'Transcription failed');
        Log::error("[PREMIUM] [{$this->dubId}] PremiumDubTranscribeJob failed: " . $e->getMessage());
    }
}
