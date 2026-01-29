<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoSegment;
use App\Models\Speaker;
use App\Traits\DetectsEnglish;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Processes a single video chunk through the complete pipeline:
 * 1. Extract audio from chunk
 * 2. Transcribe with Whisper (local or API)
 * 3. Translate text
 * 4. Generate TTS
 * 5. Mux video chunk with dubbed audio
 *
 * This enables true real-time dubbing - each chunk becomes playable independently.
 */
class ProcessVideoChunkJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use DetectsEnglish;

    public int $timeout = 300;
    public int $tries = 2;
    public int $uniqueFor = 300;

    public function __construct(
        public int $videoId,
        public int $chunkIndex,
        public float $startTime,
        public float $endTime
    ) {}

    public function uniqueId(): string
    {
        return "chunk_{$this->videoId}_{$this->chunkIndex}";
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessVideoChunkJob failed', [
            'video_id' => $this->videoId,
            'chunk' => $this->chunkIndex,
            'error' => $exception->getMessage(),
        ]);
    }

    public function handle(): void
    {
        $video = Video::find($this->videoId);
        if (!$video || !$video->original_path) {
            Log::error('Chunk job: Video not found', ['video_id' => $this->videoId]);
            return;
        }

        $videoPath = Storage::disk('local')->path($video->original_path);
        if (!file_exists($videoPath)) {
            Log::error('Chunk job: Video file not found', ['path' => $videoPath]);
            return;
        }

        $duration = $this->endTime - $this->startTime;

        Log::info('Processing video chunk', [
            'video_id' => $this->videoId,
            'chunk' => $this->chunkIndex,
            'start' => $this->startTime,
            'end' => $this->endTime,
            'duration' => $duration,
        ]);

        // Create output directories
        $chunkDir = "videos/chunks/{$this->videoId}";
        Storage::disk('local')->makeDirectory($chunkDir);
        $chunkDirAbs = Storage::disk('local')->path($chunkDir);

        // Step 1: Extract audio chunk for transcription
        $audioChunkPath = "{$chunkDirAbs}/chunk_{$this->chunkIndex}.wav";
        $this->extractAudioChunk($videoPath, $audioChunkPath, $this->startTime, $duration);

        // Step 2: Transcribe the chunk
        $text = $this->transcribeChunk($audioChunkPath);
        if (empty($text)) {
            Log::info('Chunk has no speech, skipping', [
                'video_id' => $this->videoId,
                'chunk' => $this->chunkIndex,
            ]);
            // Create empty segment to mark as processed
            $this->createSegment($video, '', '', null);
            return;
        }

        // Step 3: Translate
        $translatedText = $this->translateText($text, $video->target_language);

        // Step 4: Generate TTS
        $ttsPath = $this->generateTts($translatedText, $video, $chunkDirAbs);

        // Step 5: Create video chunk with dubbed audio
        $outputPath = "{$chunkDir}/seg_{$this->chunkIndex}.mp4";
        $this->createDubbedChunk($videoPath, $ttsPath, $outputPath, $this->startTime, $duration);

        // Step 6: Create segment record
        $this->createSegment($video, $text, $translatedText, $ttsPath ? "{$chunkDir}/tts_{$this->chunkIndex}.wav" : null);

        // Cleanup temp audio
        @unlink($audioChunkPath);

        Log::info('Chunk processing complete', [
            'video_id' => $this->videoId,
            'chunk' => $this->chunkIndex,
            'output' => $outputPath,
        ]);
    }

    private function extractAudioChunk(string $videoPath, string $outputPath, float $start, float $duration): void
    {
        $result = Process::timeout(120)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-ss', (string)$start,
            '-i', $videoPath,
            '-t', (string)$duration,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le',
            $outputPath,
        ]);

        if (!$result->successful() || !file_exists($outputPath)) {
            throw new \RuntimeException("Failed to extract audio chunk: " . $result->errorOutput());
        }
    }

    private function transcribeChunk(string $audioPath): string
    {
        // Try OpenAI Whisper API first (faster, more reliable)
        $apiKey = config('services.openai.key');
        if ($apiKey) {
            try {
                $response = Http::withToken($apiKey)
                    ->timeout(60)
                    ->attach('file', file_get_contents($audioPath), 'audio.wav')
                    ->post('https://api.openai.com/v1/audio/transcriptions', [
                        'model' => 'whisper-1',
                        'response_format' => 'text',
                    ]);

                if ($response->successful()) {
                    $text = trim($response->body());
                    Log::info('Transcribed chunk with OpenAI Whisper', [
                        'chunk' => $this->chunkIndex,
                        'text_length' => strlen($text),
                    ]);
                    return $text;
                }
            } catch (\Exception $e) {
                Log::warning('OpenAI Whisper failed, trying local', ['error' => $e->getMessage()]);
            }
        }

        // Fallback to local WhisperX
        try {
            $audioRel = str_replace(Storage::disk('local')->path(''), '', $audioPath);
            $response = Http::timeout(120)
                ->post('http://whisperx:8000/analyze', [
                    'audio_path' => $audioRel,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $segments = $data['segments'] ?? [];
                $text = collect($segments)->pluck('text')->implode(' ');
                return trim($text);
            }
        } catch (\Exception $e) {
            Log::warning('Local WhisperX failed', ['error' => $e->getMessage()]);
        }

        return '';
    }

    private function translateText(string $text, string $targetLanguage): string
    {
        if (empty($text)) return '';

        $targetLanguage = $this->normalizeTargetLanguage($targetLanguage);

        $apiKey = config('services.openai.key');
        if (!$apiKey) {
            throw new \RuntimeException('OpenAI API key required for translation');
        }

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'temperature' => 0.2,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "Translate to {$targetLanguage}. Output ONLY the translation, nothing else. Keep it natural for voice dubbing."
                    ],
                    ['role' => 'user', 'content' => $text],
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Translation failed');
        }

        return trim($response->json('choices.0.message.content') ?? '');
    }

    private function generateTts(string $text, Video $video, string $outputDir): ?string
    {
        if (empty($text)) return null;

        // Skip if text looks like English (translation failed)
        if ($this->looksLikeEnglish($text) && $video->target_language !== 'en') {
            Log::warning('TTS skipped: text looks English', ['chunk' => $this->chunkIndex]);
            return null;
        }

        $voice = $this->getVoiceForLanguage($video->target_language);
        $outputPath = "{$outputDir}/tts_{$this->chunkIndex}.wav";
        $rawPath = "{$outputDir}/tts_{$this->chunkIndex}.raw.mp3";

        // Write text to temp file
        $textFile = "/tmp/tts_chunk_{$this->videoId}_{$this->chunkIndex}.txt";
        file_put_contents($textFile, $text);

        // Generate TTS with edge-tts
        $cmd = sprintf(
            'edge-tts -f %s --voice %s --write-media %s 2>&1',
            escapeshellarg($textFile),
            escapeshellarg($voice),
            escapeshellarg($rawPath)
        );

        exec($cmd, $output, $code);
        @unlink($textFile);

        if ($code !== 0 || !file_exists($rawPath) || filesize($rawPath) < 500) {
            Log::error('TTS generation failed', [
                'chunk' => $this->chunkIndex,
                'code' => $code,
                'output' => implode("\n", array_slice($output, -10)),
            ]);
            return null;
        }

        // Normalize audio
        $result = Process::timeout(60)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $rawPath,
            '-af', 'highpass=f=80,lowpass=f=10000,loudnorm=I=-16:TP=-1.5:LRA=11',
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
            $outputPath,
        ]);

        @unlink($rawPath);

        if (!$result->successful() || !file_exists($outputPath)) {
            return null;
        }

        return $outputPath;
    }

    private function createDubbedChunk(string $videoPath, ?string $ttsPath, string $outputPath, float $start, float $duration): void
    {
        $outputAbs = Storage::disk('local')->path($outputPath);
        $outputDir = dirname($outputAbs);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        if ($ttsPath && file_exists($ttsPath)) {
            // Mux video with TTS audio
            $result = Process::timeout(120)->run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-ss', (string)$start,
                '-i', $videoPath,
                '-i', $ttsPath,
                '-t', (string)$duration,
                '-map', '0:v:0',
                '-map', '1:a:0',
                '-c:v', 'copy',
                '-c:a', 'aac', '-b:a', '128k',
                '-shortest',
                '-movflags', '+faststart',
                $outputAbs,
            ]);
        } else {
            // Just cut video segment with original audio
            $result = Process::timeout(120)->run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-ss', (string)$start,
                '-i', $videoPath,
                '-t', (string)$duration,
                '-c:v', 'copy',
                '-c:a', 'aac', '-b:a', '128k',
                '-movflags', '+faststart',
                $outputAbs,
            ]);
        }

        if (!$result->successful()) {
            throw new \RuntimeException("Failed to create dubbed chunk: " . $result->errorOutput());
        }
    }

    private function createSegment(Video $video, string $text, string $translatedText, ?string $ttsPath): void
    {
        // Get or create default speaker
        $speaker = $video->speakers()->first();
        if (!$speaker) {
            $speaker = Speaker::create([
                'video_id' => $video->id,
                'external_key' => 'SPEAKER_0',
                'label' => 'Speaker',
                'gender' => 'unknown',
                'tts_voice' => $this->getVoiceForLanguage($video->target_language),
                'tts_rate' => '+0%',
                'tts_pitch' => '+0Hz',
            ]);
        }

        VideoSegment::updateOrCreate(
            [
                'video_id' => $video->id,
                'start_time' => $this->startTime,
            ],
            [
                'speaker_id' => $speaker->id,
                'end_time' => $this->endTime,
                'text' => $text,
                'translated_text' => $translatedText,
                'tts_audio_path' => $ttsPath,
            ]
        );
    }

    private function normalizeTargetLanguage(string $lang): string
    {
        return match (strtolower($lang)) {
            'uz', 'uzbek' => 'Uzbek',
            'ru', 'russian' => 'Russian',
            'en', 'english' => 'English',
            default => $lang,
        };
    }

    private function getVoiceForLanguage(string $lang): string
    {
        return match (strtolower($lang)) {
            'uz', 'uzbek' => 'uz-UZ-SardorNeural',
            'ru', 'russian' => 'ru-RU-DmitryNeural',
            'en', 'english' => 'en-US-GuyNeural',
            default => 'uz-UZ-SardorNeural',
        };
    }
}
