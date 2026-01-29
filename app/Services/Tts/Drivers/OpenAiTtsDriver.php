<?php

namespace App\Services\Tts\Drivers;

use App\Contracts\TtsDriverInterface;
use App\Models\Speaker;
use App\Models\VideoSegment;
use App\Services\TextToSpeech\NaturalSpeechProcessor;
use App\Services\TextToSpeech\ProfessionalAudioProcessor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OpenAI TTS Driver - Professional quality text-to-speech.
 *
 * Uses OpenAI's TTS API with HD model for natural-sounding voices.
 * Optimized for movie dubbing with emotion and character support.
 */
class OpenAiTtsDriver implements TtsDriverInterface
{
    protected ?string $apiKey;
    protected string $baseUrl = 'https://api.openai.com/v1';

    /**
     * OpenAI TTS voices with their characteristics.
     *
     * Optimized assignments for dubbing:
     * - fable: Most expressive, great for lead characters
     * - nova: Warm female, good for protagonists
     * - shimmer: Clear female, good for supporting roles
     * - onyx: Deep male, good for authority figures
     * - echo: Warm male, good for friendly characters
     * - alloy: Neutral, good for narration
     */
    protected array $voices = [
        'fable' => ['gender' => 'neutral', 'style' => 'expressive', 'best_for' => 'lead_characters'],
        'nova' => ['gender' => 'female', 'style' => 'warm', 'best_for' => 'female_lead'],
        'shimmer' => ['gender' => 'female', 'style' => 'clear', 'best_for' => 'female_supporting'],
        'onyx' => ['gender' => 'male', 'style' => 'deep', 'best_for' => 'male_authority'],
        'echo' => ['gender' => 'male', 'style' => 'warm', 'best_for' => 'male_friendly'],
        'alloy' => ['gender' => 'neutral', 'style' => 'balanced', 'best_for' => 'narration'],
    ];

    protected NaturalSpeechProcessor $speechProcessor;
    protected ProfessionalAudioProcessor $audioProcessor;

    public function __construct()
    {
        $this->apiKey = config('services.openai.key') ?? '';
        $this->speechProcessor = new NaturalSpeechProcessor();
        $this->audioProcessor = new ProfessionalAudioProcessor();
    }

    public function name(): string
    {
        return 'openai';
    }

    public function supportsVoiceCloning(): bool
    {
        return false;
    }

    public function supportsEmotions(): bool
    {
        return true;
    }

    public function getVoices(string $language): array
    {
        $result = [];

        foreach ($this->voices as $id => $meta) {
            $result[$id] = [
                'id' => $id,
                'name' => ucfirst($id),
                'gender' => $meta['gender'],
                'style' => $meta['style'],
                'best_for' => $meta['best_for'],
            ];
        }

        return $result;
    }

    public function synthesize(
        string $text,
        Speaker $speaker,
        VideoSegment $segment,
        array $options = []
    ): string {
        $emotion = $options['emotion'] ?? $segment->emotion ?? $speaker->emotion ?? 'neutral';
        $voice = $options['voice'] ?? $this->selectVoiceForCharacter($speaker, $emotion);
        $speed = $options['speed'] ?? $this->calculateSpeed($emotion);

        $videoId = $segment->video_id;
        $segmentId = $segment->id;

        $outDir = Storage::disk('local')->path("audio/tts/{$videoId}");
        @mkdir($outDir, 0777, true);

        $rawMp3 = "{$outDir}/seg_{$segmentId}.raw.mp3";
        $outputWav = "{$outDir}/seg_{$segmentId}.wav";

        @unlink($rawMp3);
        @unlink($outputWav);

        // Process text for natural delivery
        $processedText = $this->speechProcessor->process($text, $emotion);

        Log::info('OpenAI TTS synthesizing', [
            'segment_id' => $segmentId,
            'voice' => $voice,
            'emotion' => $emotion,
            'speed' => $speed,
            'original_text' => mb_substr($text, 0, 100),
            'processed_text' => mb_substr($processedText, 0, 100),
        ]);

        // Call OpenAI TTS API
        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("{$this->baseUrl}/audio/speech", [
                'model' => 'tts-1-hd', // HD model for better quality
                'input' => $processedText,
                'voice' => $voice,
                'response_format' => 'mp3',
                'speed' => $speed,
            ]);

        if ($response->failed()) {
            Log::error('OpenAI TTS API failed', [
                'segment_id' => $segmentId,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
            throw new RuntimeException("OpenAI TTS failed for segment {$segmentId}");
        }

        // Save raw MP3
        file_put_contents($rawMp3, $response->body());

        if (!file_exists($rawMp3) || filesize($rawMp3) < 500) {
            throw new RuntimeException("OpenAI TTS output invalid for segment {$segmentId}");
        }

        // Professional audio processing
        $slotDuration = (float) $segment->end_time - (float) $segment->start_time;

        $processed = $this->audioProcessor->process($rawMp3, $outputWav, [
            'emotion' => $emotion,
            'gender' => $speaker->gender ?? 'unknown',
            'slot_duration' => $slotDuration,
            'gain_db' => (float) ($options['gain_db'] ?? $speaker->tts_gain_db ?? 0),
        ]);

        @unlink($rawMp3);

        if (!$processed) {
            throw new RuntimeException("Audio processing failed for segment {$segmentId}");
        }

        Log::info('OpenAI TTS complete', [
            'segment_id' => $segmentId,
            'voice' => $voice,
            'output_size' => filesize($outputWav),
        ]);

        return $outputWav;
    }

    public function cloneVoice(string $audioPath, string $name, array $options = []): string
    {
        throw new RuntimeException('OpenAI TTS does not support voice cloning');
    }

    public function getCostPerCharacter(): float
    {
        // OpenAI TTS HD: $30 per 1M characters
        return 0.00003;
    }

    /**
     * Select the best voice for a character based on their attributes.
     */
    protected function selectVoiceForCharacter(Speaker $speaker, string $emotion): string
    {
        $gender = strtolower($speaker->gender ?? 'unknown');
        $ageGroup = strtolower($speaker->age_group ?? 'adult');

        // Check if speaker has a manually assigned voice
        if (!empty($speaker->tts_voice) && isset($this->voices[$speaker->tts_voice])) {
            return $speaker->tts_voice;
        }

        // Smart voice selection based on character attributes
        if ($gender === 'female') {
            // Female voices
            if (in_array($emotion, ['excited', 'happy', 'angry'])) {
                return 'nova'; // More expressive
            }
            return 'shimmer'; // Clear and professional
        }

        if ($gender === 'male') {
            // Male voices
            if ($ageGroup === 'senior' || in_array($emotion, ['angry', 'serious'])) {
                return 'onyx'; // Deep, authoritative
            }
            return 'echo'; // Warm, friendly
        }

        // Unknown/neutral gender - use most expressive voice
        return 'fable';
    }

    /**
     * Calculate speech speed based on emotion.
     */
    protected function calculateSpeed(string $emotion): float
    {
        // OpenAI speed range: 0.25 to 4.0
        // We use subtle variations to sound natural
        return match (strtolower($emotion)) {
            'excited', 'happy' => 1.08,      // Slightly faster
            'angry', 'frustrated' => 1.05,   // Slightly faster, intense
            'sad', 'melancholy' => 0.92,     // Slower, thoughtful
            'fear', 'anxious' => 1.12,       // Faster, urgent
            'whispering' => 0.88,            // Slower, intimate
            'serious' => 0.95,               // Measured
            default => 1.0,                  // Normal
        };
    }
}
