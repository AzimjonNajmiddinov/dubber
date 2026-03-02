<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
            $voice = $this->getVoice();
            $slotDuration = $this->endTime - $this->startTime;

            // 1. Generate TTS MP3 via edge-tts
            $tmpDir = '/tmp/instant-dub-' . $this->sessionId;
            @mkdir($tmpDir, 0755, true);

            $rawMp3 = "{$tmpDir}/seg_{$this->index}.mp3";
            $tmpTxt = "{$tmpDir}/text_{$this->index}.txt";
            file_put_contents($tmpTxt, $this->text);

            $result = Process::timeout(30)->run([
                'edge-tts', '-f', $tmpTxt, '--voice', $voice, '--write-media', $rawMp3,
            ]);

            @unlink($tmpTxt);

            if (!$result->successful() || !file_exists($rawMp3) || filesize($rawMp3) < 500) {
                throw new \RuntimeException('Edge TTS failed: ' . Str::limit($result->errorOutput(), 200));
            }

            // 2. Check duration and speed up if needed
            $ttsDuration = $this->getAudioDuration($rawMp3);
            $finalMp3 = $rawMp3;

            if ($ttsDuration > $slotDuration && $slotDuration > 0.5) {
                $ratio = $ttsDuration / $slotDuration;
                // Cap at 1.5x speed to keep intelligibility
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
                'audio_base64' => $audioBase64,
                'audio_duration' => $ttsDuration,
            ]));

            // 4. Increment ready counter
            $this->incrementReady();

            Log::info('Instant dub segment ready', [
                'session' => $this->sessionId,
                'index' => $this->index,
                'text' => Str::limit($this->text, 60),
                'duration' => round($ttsDuration, 2),
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
                'audio_base64' => null,
                'audio_duration' => 0,
                'error' => $e->getMessage(),
            ]));

            $this->incrementReady();
        }
    }

    private function getVoice(): string
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
