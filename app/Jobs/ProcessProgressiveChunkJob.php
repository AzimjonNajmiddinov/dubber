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

class ProcessProgressiveChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(
        public string $sessionId,
        public int    $index,
        public string $wavPath,
        public float  $startTime,
        public float  $duration,
        public string $targetLanguage,
        public string $ttsDriver,
    ) {}

    public function handle(): void
    {
        $sessionKey = "progressive:{$this->sessionId}";

        if (!Redis::exists($sessionKey)) {
            $this->cleanup();
            return;
        }

        $session = json_decode(Redis::get($sessionKey), true);
        if (($session['status'] ?? '') === 'stopped') {
            $this->cleanup();
            return;
        }

        try {
            // 1. Transcribe via WhisperX
            $transcription = $this->transcribe();

            $originalText = $transcription['text'] ?? '';
            $speaker = $transcription['speaker'] ?? null;
            $hasSpeech = !empty($originalText);

            if (!$hasSpeech) {
                $this->storeChunkResult([
                    'index' => $this->index,
                    'start_time' => $this->startTime,
                    'end_time' => $this->startTime + $this->duration,
                    'has_speech' => false,
                    'original_text' => '',
                    'translated_text' => '',
                    'speaker' => null,
                    'audio_base64' => null,
                    'audio_duration' => 0,
                ]);
                return;
            }

            // 2. Translate via GPT-4o-mini
            $translatedText = $this->translate($originalText);

            // 3. Generate TTS via edge-tts
            $ttsWavPath = $this->generateTts($translatedText);

            // 4. Convert to MP3 for smaller transport
            $mp3Path = str_replace('.wav', '.mp3', $ttsWavPath);
            Process::timeout(15)->run([
                'ffmpeg', '-y', '-i', $ttsWavPath,
                '-codec:a', 'libmp3lame', '-b:a', '128k',
                $mp3Path,
            ]);

            @unlink($ttsWavPath);

            if (!file_exists($mp3Path)) {
                throw new \RuntimeException('MP3 conversion failed');
            }

            $audioBase64 = base64_encode(file_get_contents($mp3Path));
            $audioDuration = $this->getAudioDuration($mp3Path);

            @unlink($mp3Path);

            // 5. Store result in Redis
            $this->storeChunkResult([
                'index' => $this->index,
                'start_time' => $this->startTime,
                'end_time' => $this->startTime + $this->duration,
                'has_speech' => true,
                'original_text' => $originalText,
                'translated_text' => $translatedText,
                'speaker' => $speaker,
                'audio_base64' => $audioBase64,
                'audio_duration' => $audioDuration,
            ]);

            Log::info('Progressive chunk processed', [
                'session_id' => $this->sessionId,
                'chunk' => $this->index,
                'original' => Str::limit($originalText, 80),
                'translated' => Str::limit($translatedText, 80),
            ]);

        } catch (\Throwable $e) {
            Log::error('Progressive chunk processing failed', [
                'session_id' => $this->sessionId,
                'chunk' => $this->index,
                'error' => $e->getMessage(),
            ]);

            // Store as empty chunk so polling doesn't stall
            $this->storeChunkResult([
                'index' => $this->index,
                'start_time' => $this->startTime,
                'end_time' => $this->startTime + $this->duration,
                'has_speech' => false,
                'original_text' => '',
                'translated_text' => '',
                'speaker' => null,
                'audio_base64' => null,
                'audio_duration' => 0,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->cleanup();
        }
    }

    private function transcribe(): array
    {
        $whisperxUrl = config('services.whisperx.url', 'http://whisperx:8000');

        $response = Http::timeout(90)
            ->connectTimeout(5)
            ->attach('audio', file_get_contents($this->wavPath), 'chunk.wav')
            ->post("{$whisperxUrl}/analyze-upload", [
                'lite' => 1,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("WhisperX failed: HTTP {$response->status()}");
        }

        $data = $response->json();

        if (!is_array($data) || isset($data['error'])) {
            throw new \RuntimeException('WhisperX error: ' . ($data['error'] ?? 'invalid response'));
        }

        $segments = $data['segments'] ?? [];
        $text = collect($segments)->pluck('text')->filter()->join(' ');
        $speaker = $segments[0]['speaker'] ?? null;

        return ['text' => trim($text), 'speaker' => $speaker];
    }

    private function translate(string $text): string
    {
        $apiKey = config('services.openai.key');

        if (!$apiKey || empty($text)) {
            return $text;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "Translate to {$this->targetLanguage}. Output ONLY the translation, nothing else. Keep it natural for spoken dialogue.",
                        ],
                        ['role' => 'user', 'content' => $text],
                    ],
                ]);

            if ($response->failed()) {
                return $text;
            }

            return trim($response->json('choices.0.message.content') ?? $text);
        } catch (\Throwable $e) {
            Log::warning('Translation failed, using original', ['error' => $e->getMessage()]);
            return $text;
        }
    }

    private function generateTts(string $text): string
    {
        $tempDir = storage_path("app/temp/progressive/{$this->sessionId}");
        @mkdir($tempDir, 0755, true);

        $outputPath = "{$tempDir}/tts_{$this->index}.wav";
        $tmpTxt = "{$tempDir}/tts_text_{$this->index}.txt";
        file_put_contents($tmpTxt, $text);

        // Map language to Edge TTS voice
        $voice = $this->getEdgeVoice();

        $result = Process::timeout(30)->run([
            'edge-tts',
            '-f', $tmpTxt,
            '--voice', $voice,
            '--write-media', $outputPath,
        ]);

        @unlink($tmpTxt);

        if (!$result->successful() || !file_exists($outputPath)) {
            throw new \RuntimeException('Edge TTS failed: ' . mb_substr($result->errorOutput(), 0, 200));
        }

        return $outputPath;
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
            'hi' => 'hi-IN-MadhurNeural',
            'pt' => 'pt-BR-AntonioNeural',
        ];

        return $voices[$this->targetLanguage] ?? 'uz-UZ-SardorNeural';
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

    private function storeChunkResult(array $data): void
    {
        $sessionKey = "progressive:{$this->sessionId}";
        $chunkKey = "{$sessionKey}:chunk:{$this->index}";

        Redis::setex($chunkKey, 14400, json_encode($data));

        // Increment ready counter in session
        $sessionJson = Redis::get($sessionKey);
        if ($sessionJson) {
            $session = json_decode($sessionJson, true);
            $session['chunks_ready'] = ($session['chunks_ready'] ?? 0) + 1;

            // Check if all chunks are done
            $totalChunks = $session['total_chunks'] ?? null;
            if ($totalChunks !== null && $session['chunks_ready'] >= $totalChunks) {
                $session['status'] = 'complete';
            }

            Redis::setex($sessionKey, 14400, json_encode($session));
        }
    }

    private function cleanup(): void
    {
        if (file_exists($this->wavPath)) {
            @unlink($this->wavPath);
        }
    }
}
