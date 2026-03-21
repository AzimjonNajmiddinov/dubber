<?php

namespace App\Services\RunPod;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RunPodClient
{
    private string $apiKey;
    private string $baseUrl = 'https://api.runpod.ai/v2';

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? config('services.runpod.api_key', '');
    }

    /**
     * Submit an async job to a RunPod serverless endpoint.
     *
     * @return string Job ID
     */
    public function submitJob(string $endpointId, array $input): string
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post("{$this->baseUrl}/{$endpointId}/run", [
                'input' => $input,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "RunPod submit failed: HTTP {$response->status()} — " . substr($response->body(), 0, 300)
            );
        }

        $jobId = $response->json('id');
        if (!$jobId) {
            throw new RuntimeException('RunPod submit: no job ID in response');
        }

        Log::info("[RunPod] Job submitted", ['endpoint' => $endpointId, 'job_id' => $jobId]);
        return $jobId;
    }

    /**
     * Check the status of a job.
     *
     * @return array{status: string, output: ?array}
     */
    public function getStatus(string $endpointId, string $jobId): array
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(15)
            ->get("{$this->baseUrl}/{$endpointId}/status/{$jobId}");

        if ($response->failed()) {
            throw new RuntimeException(
                "RunPod status check failed: HTTP {$response->status()}"
            );
        }

        return [
            'status' => $response->json('status', 'UNKNOWN'),
            'output' => $response->json('output'),
        ];
    }

    /**
     * Poll a job until it completes or times out.
     *
     * @return array Job output
     * @throws RuntimeException on timeout or failure
     */
    public function pollJob(string $endpointId, string $jobId, int $timeoutSeconds = 900, int $intervalSeconds = 5): array
    {
        $start = time();

        while (true) {
            $result = $this->getStatus($endpointId, $jobId);
            $status = $result['status'];

            if ($status === 'COMPLETED') {
                $output = $result['output'];
                if (isset($output['error'])) {
                    throw new RuntimeException("RunPod job error: {$output['error']}");
                }
                return $output;
            }

            if ($status === 'FAILED') {
                throw new RuntimeException("RunPod job failed: " . json_encode($result['output'] ?? 'unknown'));
            }

            if ($status === 'CANCELLED') {
                throw new RuntimeException('RunPod job was cancelled');
            }

            if ((time() - $start) >= $timeoutSeconds) {
                $this->cancelJob($endpointId, $jobId);
                throw new RuntimeException("RunPod job timed out after {$timeoutSeconds}s");
            }

            sleep($intervalSeconds);
        }
    }

    /**
     * Cancel a running job.
     */
    public function cancelJob(string $endpointId, string $jobId): void
    {
        try {
            Http::withToken($this->apiKey)
                ->timeout(10)
                ->post("{$this->baseUrl}/{$endpointId}/cancel/{$jobId}");
        } catch (\Throwable $e) {
            Log::warning("[RunPod] Cancel failed", ['job_id' => $jobId, 'error' => $e->getMessage()]);
        }
    }
}
