<?php

namespace App\Jobs\PremiumDub;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
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
        // Use Demucs vocals (clean speech, no background music) for better diarization
        $audioPath = $session['vocals_path'] ?? $session['audio_path'] ?? null;

        if (!$audioPath || !file_exists($audioPath)) {
            $this->updateStatus('error', 'Audio file not found for transcription');
            return;
        }

        $this->updateStatus('transcribing', 'Transcribing audio with WhisperX...');

        $serviceUrl = rtrim(config('services.whisperx.url'), '/');

        try {
            // Ensure gender/emotion models are loaded (lazy by default)
            Http::timeout(60)->get("{$serviceUrl}/ready");

            $session = $this->getSession();
            $speakers = $session['speakers'] ?? null;

            // Upload audio to WhisperX service
            $formData = [];
            if ($speakers !== null) {
                $formData['min_speakers'] = (int) $speakers;
                $formData['max_speakers'] = (int) $speakers;
            }

            $response = Http::timeout(1800)
                ->attach('audio', file_get_contents($audioPath), 'audio.wav')
                ->post("{$serviceUrl}/analyze-upload", $formData);

            if (!$response->successful()) {
                throw new \RuntimeException("WhisperX failed: " . $response->body());
            }

            $result = $response->json();

            if (isset($result['error'])) {
                throw new \RuntimeException("WhisperX error: " . $result['error']);
            }

            $segments = $result['segments'] ?? [];
            $language = $result['language'] ?? 'unknown';

            // Hub worker puts speaker in each segment, extract unique speakers
            $speakers = [];
            foreach ($segments as &$seg) {
                $spk = $seg['speaker'] ?? 'SPEAKER_00';
                if (!isset($speakers[$spk])) {
                    $speakers[$spk] = ['gender' => 'unknown', 'age_group' => 'unknown'];
                }
                // Normalize field names for downstream compatibility
                if (!isset($seg['speaker'])) {
                    $seg['speaker'] = $spk;
                }
            }
            unset($seg);

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

            $speakerSummary = collect($speakers)->map(fn($s, $k) => "{$k}:{$s['gender']}/{$s['age_group']}")->implode(', ');
            Log::info("[PREMIUM] [{$this->dubId}] Transcription complete: {$language}, " . count($segments) . " segments, " . count($speakers) . " speakers — {$speakerSummary}");

            // Check if stems are also done → dispatch next step
            $this->checkAndDispatchNext();

        } catch (\Throwable $e) {
            $this->updateStatus('error', 'Transcription failed: ' . Str::limit($e->getMessage(), 100));
            Log::error("[PREMIUM] [{$this->dubId}] WhisperX failed: " . $e->getMessage());
        }
    }

    private function checkAndDispatchNext(): void
    {
        PremiumDubTranslateJob::dispatch($this->dubId)->onQueue('default');
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
