<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoSegment;
use App\Models\Speaker;
use App\Services\TextNormalizer;
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

        // Get previous segment for context
        $previousContext = $this->getPreviousSegmentContext();

        $systemPrompt = <<<PROMPT
You are a professional movie dialogue translator specializing in voice dubbing. Translate to {$targetLanguage}.

CRITICAL RULES:
1. Output ONLY the translation - no explanations, no quotes, no meta text
2. Preserve the EXACT meaning - do not lose any information or nuance
3. Keep numbers as digits (e.g., "100" stays "100", "2,600" stays "2,600")
4. Preserve names, places, and proper nouns exactly
5. CAPTURE THE EMOTION: If the speaker is excited, angry, sad, motivational - reflect that in word choice
6. Use natural spoken {$targetLanguage} - how a native speaker would actually say it
7. Keep similar sentence length and rhythm for dubbing sync
8. Maintain continuity with previous context if provided
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Add context from previous segment if available
        if ($previousContext) {
            $messages[] = [
                'role' => 'user',
                'content' => "Previous segment (for context only, don't translate): \"{$previousContext}\""
            ];
            $messages[] = [
                'role' => 'assistant',
                'content' => "Understood. I'll maintain continuity with the previous context."
            ];
        }

        $messages[] = ['role' => 'user', 'content' => "Translate this: \"{$text}\""];

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'temperature' => 0.1,
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Translation failed');
        }

        return trim($response->json('choices.0.message.content') ?? '');
    }

    private function getPreviousSegmentContext(): ?string
    {
        if ($this->chunkIndex <= 0) {
            return null;
        }

        // Get previous chunk's segment
        $previousStartTime = ($this->chunkIndex - 1) * 12; // 12 seconds per chunk
        $previousSegment = VideoSegment::where('video_id', $this->videoId)
            ->where('start_time', $previousStartTime)
            ->first();

        if ($previousSegment && $previousSegment->text) {
            // Return last 100 chars of previous segment for context
            return mb_substr($previousSegment->text, -150);
        }

        return null;
    }

    private function generateTts(string $text, Video $video, string $outputDir): ?string
    {
        if (empty($text)) return null;

        // Skip if text looks like English (translation failed)
        if ($this->looksLikeEnglish($text) && $video->target_language !== 'en') {
            Log::warning('TTS skipped: text looks English', ['chunk' => $this->chunkIndex]);
            return null;
        }

        // Normalize text - convert numbers to words for proper TTS pronunciation
        $normalizedText = TextNormalizer::normalize($text, $video->target_language);

        // Detect emotion and create SSML with prosody
        $ssmlText = $this->createEmotionalSsml($normalizedText, $video->target_language);

        Log::info('TTS with emotion', [
            'chunk' => $this->chunkIndex,
            'text' => mb_substr($normalizedText, 0, 80),
        ]);

        $voice = $this->getVoiceForLanguage($video->target_language);
        $outputPath = "{$outputDir}/tts_{$this->chunkIndex}.wav";
        $rawPath = "{$outputDir}/tts_{$this->chunkIndex}.raw.mp3";

        // Write SSML to temp file
        $textFile = "/tmp/tts_chunk_{$this->videoId}_{$this->chunkIndex}.ssml";
        file_put_contents($textFile, $ssmlText);

        // Generate TTS with edge-tts using SSML
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

        // Check for background audio (no_vocals from Demucs)
        $backgroundPath = $this->getBackgroundAudioPath();

        if ($ttsPath && file_exists($ttsPath) && $backgroundPath) {
            // Mix TTS with background audio, then mux with video
            $mixedAudioPath = "{$outputDir}/mixed_{$this->chunkIndex}.wav";
            $this->mixTtsWithBackground($ttsPath, $backgroundPath, $mixedAudioPath, $start, $duration);

            if (file_exists($mixedAudioPath)) {
                $result = Process::timeout(120)->run([
                    'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                    '-ss', (string)$start,
                    '-i', $videoPath,
                    '-i', $mixedAudioPath,
                    '-t', (string)$duration,
                    '-map', '0:v:0',
                    '-map', '1:a:0',
                    '-c:v', 'copy',
                    '-c:a', 'aac', '-b:a', '192k',
                    '-shortest',
                    '-movflags', '+faststart',
                    $outputAbs,
                ]);
                @unlink($mixedAudioPath);
            } else {
                // Fallback: just use TTS
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
            }
        } elseif ($ttsPath && file_exists($ttsPath)) {
            // No background available - just use TTS
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
            // No TTS - just cut video segment with original audio
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

    /**
     * Get path to background audio (no_vocals) if available.
     */
    private function getBackgroundAudioPath(): ?string
    {
        $basePath = "audio/stems/{$this->videoId}";

        // Check for both .wav and .flac extensions (Demucs outputs either)
        foreach (['wav', 'flac'] as $ext) {
            $path = Storage::disk('local')->path("{$basePath}/no_vocals.{$ext}");
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Mix TTS audio with background audio.
     */
    private function mixTtsWithBackground(string $ttsPath, string $backgroundPath, string $outputPath, float $start, float $duration): void
    {
        // Extract background chunk at the same timestamp
        $bgChunkPath = dirname($outputPath) . "/bg_{$this->chunkIndex}.wav";

        // Extract background audio chunk
        $extractResult = Process::timeout(60)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-ss', (string)$start,
            '-i', $backgroundPath,
            '-t', (string)$duration,
            '-c:a', 'pcm_s16le',
            '-ar', '48000',
            '-ac', '2',
            $bgChunkPath,
        ]);

        if (!$extractResult->successful() || !file_exists($bgChunkPath)) {
            Log::warning('Failed to extract background chunk', [
                'chunk' => $this->chunkIndex,
                'error' => $extractResult->errorOutput(),
            ]);
            return;
        }

        // Mix TTS (louder) with background (quieter)
        // TTS at 0dB, background at -12dB (music/ambient lower than speech)
        $mixResult = Process::timeout(60)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $ttsPath,
            '-i', $bgChunkPath,
            '-filter_complex',
            '[0:a]volume=1.0,apad[tts];[1:a]volume=0.25[bg];[tts][bg]amix=inputs=2:duration=longest:dropout_transition=2,loudnorm=I=-16:TP=-1.5:LRA=11[out]',
            '-map', '[out]',
            '-c:a', 'pcm_s16le',
            '-ar', '48000',
            '-ac', '2',
            $outputPath,
        ]);

        @unlink($bgChunkPath);

        if (!$mixResult->successful()) {
            Log::warning('Failed to mix audio', [
                'chunk' => $this->chunkIndex,
                'error' => $mixResult->errorOutput(),
            ]);
        } else {
            Log::info('Mixed TTS with background audio', ['chunk' => $this->chunkIndex]);
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

    /**
     * Create SSML with emotional prosody based on text analysis.
     */
    private function createEmotionalSsml(string $text, string $language): string
    {
        // Analyze text for emotional cues
        $emotion = $this->detectEmotion($text);

        // Get prosody settings based on emotion
        $prosody = $this->getEmotionProsody($emotion);

        // Build SSML
        $ssml = '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="' . $this->getLanguageCode($language) . '">';

        // Split text into sentences for natural pauses
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($sentences as $i => $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) continue;

            // Detect sentence-level emotion
            $sentenceEmotion = $this->detectEmotion($sentence);
            $sentenceProsody = $this->getEmotionProsody($sentenceEmotion);

            // Add prosody tags
            $ssml .= sprintf(
                '<prosody rate="%s" pitch="%s" volume="%s">%s</prosody>',
                $sentenceProsody['rate'],
                $sentenceProsody['pitch'],
                $sentenceProsody['volume'],
                htmlspecialchars($sentence, ENT_XML1)
            );

            // Add natural pause between sentences
            if ($i < count($sentences) - 1) {
                $ssml .= '<break time="300ms"/>';
            }
        }

        $ssml .= '</speak>';

        return $ssml;
    }

    /**
     * Detect emotion from text.
     */
    private function detectEmotion(string $text): string
    {
        $text = mb_strtolower($text);

        // Excitement/motivation markers
        $excitedWords = ['!', 'wow', 'amazing', 'incredible', 'ajoyib', 'zo\'r', 'отлично', 'круто', 'yes', 'ha', 'да'];
        foreach ($excitedWords as $word) {
            if (mb_strpos($text, $word) !== false) {
                return 'excited';
            }
        }

        // Question markers
        if (mb_strpos($text, '?') !== false) {
            return 'questioning';
        }

        // Sad/serious markers
        $sadWords = ['unfortunately', 'sad', 'fail', 'muvaffaqiyatsiz', 'xafa', 'к сожалению', 'грустно', 'lost', 'died'];
        foreach ($sadWords as $word) {
            if (mb_strpos($text, $word) !== false) {
                return 'serious';
            }
        }

        // Emphatic/important markers
        $emphaticWords = ['remember', 'important', 'must', 'never', 'always', 'esla', 'muhim', 'помни', 'важно', 'kerak'];
        foreach ($emphaticWords as $word) {
            if (mb_strpos($text, $word) !== false) {
                return 'emphatic';
            }
        }

        // Motivational markers
        $motivationalWords = ['can', 'will', 'believe', 'succeed', 'dream', 'goal', 'orzu', 'maqsad', 'мечта', 'цель', 'forward'];
        foreach ($motivationalWords as $word) {
            if (mb_strpos($text, $word) !== false) {
                return 'motivational';
            }
        }

        return 'neutral';
    }

    /**
     * Get prosody settings for emotion.
     */
    private function getEmotionProsody(string $emotion): array
    {
        return match ($emotion) {
            'excited' => [
                'rate' => '+10%',
                'pitch' => '+5%',
                'volume' => '+10%',
            ],
            'questioning' => [
                'rate' => '+0%',
                'pitch' => '+10%',
                'volume' => '+0%',
            ],
            'serious' => [
                'rate' => '-5%',
                'pitch' => '-5%',
                'volume' => '-5%',
            ],
            'emphatic' => [
                'rate' => '-5%',
                'pitch' => '+0%',
                'volume' => '+15%',
            ],
            'motivational' => [
                'rate' => '+5%',
                'pitch' => '+3%',
                'volume' => '+5%',
            ],
            default => [
                'rate' => '+0%',
                'pitch' => '+0%',
                'volume' => '+0%',
            ],
        };
    }

    /**
     * Get language code for SSML.
     */
    private function getLanguageCode(string $language): string
    {
        return match (strtolower($language)) {
            'uz', 'uzbek' => 'uz-UZ',
            'ru', 'russian' => 'ru-RU',
            'en', 'english' => 'en-US',
            default => 'en-US',
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
