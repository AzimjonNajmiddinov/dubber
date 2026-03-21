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

class PremiumDubSeparateStemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min for large movies
    public int $tries = 2;

    public function __construct(
        public string $dubId,
    ) {}

    public function handle(): void
    {
        $session = $this->getSession();
        $audioPath = $session['audio_path'] ?? null;

        if (!$audioPath || !file_exists($audioPath)) {
            $this->updateStatus('error', 'Audio file not found for stem separation');
            return;
        }

        $this->updateStatus('separating_stems', 'Separating vocals from background...');

        $storage = new RunPodStorage();
        $client = new RunPodClient();
        $endpointId = config('services.runpod.demucs_endpoint_id');

        $workDir = storage_path("app/premium-dub/{$this->dubId}");

        try {
            // Upload audio to S3
            $s3Path = "premium-dub/{$this->dubId}/audio.wav";
            $audioUrl = $storage->upload($audioPath, $s3Path);

            // Submit to RunPod Demucs
            $jobId = $client->submitJob($endpointId, [
                'audio_url' => $audioUrl,
                'model' => 'htdemucs',
            ]);

            // Poll until complete (up to 25 min)
            $result = $client->pollJob($endpointId, $jobId, 1500, 10);

            // Download results
            $vocalsPath = "{$workDir}/vocals.wav";
            $noVocalsPath = "{$workDir}/no_vocals.wav";

            if (!empty($result['vocals_url'])) {
                $storage->download($result['vocals_url'], $vocalsPath);
            }
            if (!empty($result['no_vocals_url'])) {
                $storage->download($result['no_vocals_url'], $noVocalsPath);
            }

            // Cleanup S3
            $storage->delete($s3Path);

            if (!file_exists($noVocalsPath)) {
                $this->updateStatus('error', 'Stem separation produced no results');
                return;
            }

            $this->updateSession([
                'vocals_path' => $vocalsPath,
                'no_vocals_path' => $noVocalsPath,
                'stems_ready' => true,
            ]);

            Log::info("[PREMIUM] [{$this->dubId}] Stems separated ({$result['elapsed_seconds']}s)");

            // Check if transcription is also done → dispatch next step
            $this->checkAndDispatchNext();

        } catch (\Throwable $e) {
            $this->updateStatus('error', 'Stem separation failed: ' . Str::limit($e->getMessage(), 100));
            Log::error("[PREMIUM] [{$this->dubId}] Demucs failed: " . $e->getMessage());
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
        $this->updateStatus('error', 'Stem separation failed');
        Log::error("[PREMIUM] [{$this->dubId}] PremiumDubSeparateStemsJob failed: " . $e->getMessage());
    }
}
