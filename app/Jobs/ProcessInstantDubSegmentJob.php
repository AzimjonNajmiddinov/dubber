<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ProcessInstantDubSegmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 2;

    public function __construct(
        public string $sessionId,
        public int    $index,
        public string $text,
        public float  $startTime,
        public float  $endTime,
        public string $language,
        public string $speaker = 'M1',
    ) {}

    public function handle(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";

        if (!Redis::exists($sessionKey)) {
            return;
        }

        $session = json_decode(Redis::get($sessionKey), true);
        if (($session['status'] ?? '') === 'stopped') {
            return;
        }

        try {
            $slotDuration = $this->endTime - $this->startTime;

            // 1. Generate TTS audio
            $tmpDir = '/tmp/instant-dub-' . $this->sessionId;
            @mkdir($tmpDir, 0755, true);

            $rawMp3 = "{$tmpDir}/seg_{$this->index}.mp3";

            // Try UzbekVoice API for Uzbek language
            $usedUzbekVoice = false;
            if ($this->language === 'uz') {
                $usedUzbekVoice = $this->generateWithUzbekVoice($rawMp3, $tmpDir);
            }

            // Fallback to edge-tts
            if (!$usedUzbekVoice) {
                $this->generateWithEdgeTts($rawMp3, $tmpDir);
            }

            // 2. Check duration and speed up if needed
            $ttsDuration = $this->getAudioDuration($rawMp3);
            $finalMp3 = $rawMp3;

            if ($ttsDuration > $slotDuration && $slotDuration > 0.5) {
                $ratio = $ttsDuration / $slotDuration;
                $tempo = min($ratio, 1.5);

                $speedMp3 = "{$tmpDir}/seg_{$this->index}_fast.mp3";
                $speedResult = Process::timeout(15)->run([
                    'ffmpeg', '-y', '-i', $rawMp3,
                    '-filter:a', "atempo={$tempo}",
                    '-codec:a', 'libmp3lame', '-b:a', '128k',
                    $speedMp3,
                ]);

                if ($speedResult->successful() && file_exists($speedMp3) && filesize($speedMp3) > 200) {
                    @unlink($rawMp3);
                    $finalMp3 = $speedMp3;
                    $ttsDuration = $this->getAudioDuration($finalMp3);
                }
            }

            // 3. Encode to base64 and store in Redis
            $audioBase64 = base64_encode(file_get_contents($finalMp3));
            @unlink($finalMp3);

            $chunkKey = "{$sessionKey}:chunk:{$this->index}";
            Redis::setex($chunkKey, 50400, json_encode([
                'index' => $this->index,
                'start_time' => $this->startTime,
                'end_time' => $this->endTime,
                'text' => $this->text,
                'speaker' => $this->speaker,
                'audio_base64' => $audioBase64,
                'audio_duration' => $ttsDuration,
            ]));

            // 4. Increment ready counter
            $this->incrementReady();

            Log::info('Instant dub segment ready', [
                'session' => $this->sessionId,
                'index' => $this->index,
                'speaker' => $this->speaker,
                'text' => Str::limit($this->text, 60),
                'duration' => round($ttsDuration, 2),
                'uzbekvoice' => $usedUzbekVoice,
            ]);

        } catch (\Throwable $e) {
            Log::error('Instant dub segment failed', [
                'session' => $this->sessionId,
                'index' => $this->index,
                'error' => $e->getMessage(),
            ]);

            // Store error chunk so polling doesn't stall
            $chunkKey = "{$sessionKey}:chunk:{$this->index}";
            Redis::setex($chunkKey, 50400, json_encode([
                'index' => $this->index,
                'start_time' => $this->startTime,
                'end_time' => $this->endTime,
                'text' => $this->text,
                'speaker' => $this->speaker,
                'audio_base64' => null,
                'audio_duration' => 0,
                'error' => $e->getMessage(),
            ]));

            $this->incrementReady();
        }
    }

    private function generateWithUzbekVoice(string $outputMp3, string $tmpDir): bool
    {
        $apiUrl = config('services.uzbekvoice.url', 'https://uzbekvoice.ai/api/v1');
        $apiKey = config('services.uzbekvoice.api_key', '');

        if (!$apiKey) {
            return false;
        }

        // Load voice map from Redis
        $voiceKey = "instant-dub:{$this->sessionId}:voices";
        $voiceMapJson = Redis::get($voiceKey);
        $voiceMap = $voiceMapJson ? json_decode($voiceMapJson, true) : [];
        $model = $voiceMap[$this->speaker] ?? 'davron';

        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::timeout(60)
                    ->withHeaders([
                        'Authorization' => "Bearer {$apiKey}",
                        'Accept' => 'application/json',
                    ])
                    ->post("{$apiUrl}/tts", [
                        'text' => $this->text,
                        'model' => $model,
                        'blocking' => 'true',
                    ]);

                // Rate limit or server error — backoff and retry
                if (in_array($response->status(), [400, 429, 500, 502, 503])) {
                    $body = $response->body();
                    $isRateLimit = str_contains($body, 'Too many') || str_contains($body, 'active requests');

                    if ($attempt < $maxRetries && ($isRateLimit || $response->status() >= 500)) {
                        $delay = $isRateLimit ? $attempt * 5 : $attempt * 3;
                        Log::info("InstantDub UzbekVoice: retry {$attempt}/{$maxRetries} in {$delay}s", [
                            'index' => $this->index,
                            'status' => $response->status(),
                        ]);
                        sleep($delay);
                        continue;
                    }
                    Log::warning('InstantDub UzbekVoice: API error, falling back', [
                        'index' => $this->index,
                        'status' => $response->status(),
                    ]);
                    return false;
                }

                if ($response->failed()) {
                    return false;
                }

                $data = $response->json();

                if (($data['status'] ?? '') !== 'SUCCESS' || empty($data['result']['url'])) {
                    if ($attempt < $maxRetries) {
                        sleep($attempt * 2);
                        continue;
                    }
                    return false;
                }

                // Download the WAV from CDN
                $audioResponse = Http::timeout(30)->get($data['result']['url']);
                if ($audioResponse->failed() || strlen($audioResponse->body()) < 1000) {
                    return false;
                }

                // Write WAV, convert to MP3
                $tmpWav = "{$tmpDir}/seg_{$this->index}_uv.wav";
                file_put_contents($tmpWav, $audioResponse->body());

                $convertResult = Process::timeout(15)->run([
                    'ffmpeg', '-y', '-i', $tmpWav,
                    '-codec:a', 'libmp3lame', '-b:a', '128k',
                    $outputMp3,
                ]);

                @unlink($tmpWav);

                if ($convertResult->successful() && file_exists($outputMp3) && filesize($outputMp3) > 200) {
                    Log::info('InstantDub UzbekVoice: success', [
                        'index' => $this->index,
                        'speaker' => $this->speaker,
                        'model' => $model,
                    ]);
                    return true;
                }

                return false;

            } catch (\Throwable $e) {
                Log::warning('InstantDub UzbekVoice: exception', [
                    'index' => $this->index,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                if ($attempt < $maxRetries) {
                    sleep($attempt * 2);
                    continue;
                }
                return false;
            }
        }

        return false;
    }

    private function generateWithEdgeTts(string $outputMp3, string $tmpDir): void
    {
        $voice = $this->getEdgeVoice();

        $tmpTxt = "{$tmpDir}/text_{$this->index}.txt";
        file_put_contents($tmpTxt, $this->text);

        $result = Process::timeout(30)->run([
            'edge-tts', '-f', $tmpTxt, '--voice', $voice, '--write-media', $outputMp3,
        ]);

        @unlink($tmpTxt);

        if (!$result->successful() || !file_exists($outputMp3) || filesize($outputMp3) < 500) {
            throw new \RuntimeException('Edge TTS failed: ' . Str::limit($result->errorOutput(), 200));
        }
    }

    private function getEdgeVoice(): string
    {
        $voices = [
            'uz' => 'uz-UZ-SardorNeural',
            'ru' => 'ru-RU-DmitryNeural',
            'en' => 'en-US-GuyNeural',
            'es' => 'es-ES-AlvaroNeural',
            'fr' => 'fr-FR-HenriNeural',
            'de' => 'de-DE-ConradNeural',
            'tr' => 'tr-TR-AhmetNeural',
            'ar' => 'ar-SA-HamedNeural',
            'zh' => 'zh-CN-YunxiNeural',
            'ja' => 'ja-JP-KeitaNeural',
            'ko' => 'ko-KR-InJoonNeural',
        ];

        return $voices[$this->language] ?? 'uz-UZ-SardorNeural';
    }

    private function getAudioDuration(string $path): float
    {
        $result = Process::timeout(10)->run([
            'ffprobe', '-hide_banner', '-loglevel', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1',
            $path,
        ]);

        return (float) trim($result->output());
    }

    private function incrementReady(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);

        if (!$sessionJson) {
            return;
        }

        $session = json_decode($sessionJson, true);
        $session['segments_ready'] = ($session['segments_ready'] ?? 0) + 1;

        if ($session['segments_ready'] >= ($session['total_segments'] ?? PHP_INT_MAX)) {
            $session['status'] = 'complete';
        }

        Redis::setex($sessionKey, 50400, json_encode($session));
    }
}
