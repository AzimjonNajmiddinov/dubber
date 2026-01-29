<?php

namespace App\Services\Tts\Drivers;

use App\Contracts\TtsDriverInterface;
use App\Models\Speaker;
use App\Models\VideoSegment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ElevenLabsDriver implements TtsDriverInterface
{
    protected ?string $apiKey;
    protected string $baseUrl = 'https://api.elevenlabs.io/v1';

    public function __construct()
    {
        $this->apiKey = config('services.elevenlabs.key') ?? '';
    }

    public function name(): string
    {
        return 'elevenlabs';
    }

    public function supportsVoiceCloning(): bool
    {
        return true;
    }

    public function supportsEmotions(): bool
    {
        return true;
    }

    public function getVoices(string $language): array
    {
        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
        ])->get("{$this->baseUrl}/voices");

        if ($response->failed()) {
            Log::error('ElevenLabs get voices failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [];
        }

        $voices = [];
        foreach ($response->json('voices', []) as $voice) {
            $voices[$voice['voice_id']] = [
                'id' => $voice['voice_id'],
                'name' => $voice['name'],
                'gender' => $voice['labels']['gender'] ?? 'unknown',
                'accent' => $voice['labels']['accent'] ?? null,
                'age' => $voice['labels']['age'] ?? null,
                'description' => $voice['labels']['description'] ?? null,
                'preview_url' => $voice['preview_url'] ?? null,
                'category' => $voice['category'] ?? 'premade',
            ];
        }

        return $voices;
    }

    public function synthesize(
        string $text,
        Speaker $speaker,
        VideoSegment $segment,
        array $options = []
    ): string {
        $voiceId = $options['voice_id'] ?? $speaker->elevenlabs_voice_id ?? $this->getDefaultVoice($speaker);
        $emotion = $options['emotion'] ?? $segment->emotion ?? $speaker->emotion ?? 'neutral';

        $videoId = $segment->video_id;
        $segmentId = $segment->id;

        $outDir = Storage::disk('local')->path("audio/tts/{$videoId}");
        @mkdir($outDir, 0777, true);

        $outputMp3 = "{$outDir}/seg_{$segmentId}.mp3";
        $outputWav = "{$outDir}/seg_{$segmentId}.wav";

        @unlink($outputMp3);
        @unlink($outputWav);

        // Map emotion to ElevenLabs style
        $voiceSettings = $this->getVoiceSettings($emotion, $options);

        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'audio/mpeg',
        ])
            ->timeout(60)
            ->post("{$this->baseUrl}/text-to-speech/{$voiceId}/stream", [
                'text' => $text,
                'model_id' => $options['model_id'] ?? 'eleven_multilingual_v2',
                'voice_settings' => $voiceSettings,
            ]);

        if ($response->failed()) {
            Log::error('ElevenLabs synthesis failed', [
                'segment_id' => $segmentId,
                'voice_id' => $voiceId,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
            throw new RuntimeException("ElevenLabs synthesis failed for segment {$segmentId}");
        }

        file_put_contents($outputMp3, $response->body());

        if (!file_exists($outputMp3) || filesize($outputMp3) < 500) {
            throw new RuntimeException("ElevenLabs output file invalid for segment {$segmentId}");
        }

        // Convert to normalized WAV
        $this->convertToWav($outputMp3, $outputWav, $options);
        @unlink($outputMp3);

        Log::info('ElevenLabs synthesis complete', [
            'segment_id' => $segmentId,
            'voice_id' => $voiceId,
            'emotion' => $emotion,
            'text_length' => mb_strlen($text),
        ]);

        return $outputWav;
    }

    public function cloneVoice(string $audioPath, string $name, array $options = []): string
    {
        if (!file_exists($audioPath)) {
            throw new RuntimeException("Audio file not found: {$audioPath}");
        }

        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
        ])
            ->timeout(120)
            ->attach('files', file_get_contents($audioPath), basename($audioPath))
            ->post("{$this->baseUrl}/voices/add", [
                'name' => $name,
                'description' => $options['description'] ?? "Cloned voice for dubbing: {$name}",
                'labels' => json_encode($options['labels'] ?? []),
            ]);

        if ($response->failed()) {
            Log::error('ElevenLabs voice cloning failed', [
                'name' => $name,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException("Voice cloning failed: " . $response->body());
        }

        $voiceId = $response->json('voice_id');

        Log::info('ElevenLabs voice cloned', [
            'name' => $name,
            'voice_id' => $voiceId,
        ]);

        return $voiceId;
    }

    public function getCostPerCharacter(): float
    {
        // ElevenLabs pricing: ~$0.30 per 1000 characters (Creator plan)
        return 0.0003;
    }

    protected function getDefaultVoice(Speaker $speaker): string
    {
        // Default multilingual voices
        $gender = $speaker->gender ?? 'unknown';

        return match ($gender) {
            'male' => 'pNInz6obpgDQGcFmaJgB', // Adam
            'female' => 'EXAVITQu4vr4xnSDxMaL', // Bella
            default => 'EXAVITQu4vr4xnSDxMaL', // Bella as default
        };
    }

    protected function getVoiceSettings(string $emotion, array $options): array
    {
        // Base settings
        $stability = $options['stability'] ?? 0.5;
        $similarityBoost = $options['similarity_boost'] ?? 0.75;
        $style = $options['style'] ?? 0.0;
        $useSpeakerBoost = $options['use_speaker_boost'] ?? true;

        // Adjust based on emotion for more expressive output
        switch (strtolower($emotion)) {
            case 'happy':
            case 'excited':
                $stability = 0.35;
                $style = 0.7;
                break;

            case 'sad':
                $stability = 0.6;
                $style = 0.4;
                break;

            case 'angry':
            case 'frustration':
                $stability = 0.3;
                $style = 0.8;
                break;

            case 'fear':
            case 'surprise':
                $stability = 0.25;
                $style = 0.6;
                break;

            case 'neutral':
            default:
                $stability = 0.5;
                $style = 0.3;
                break;
        }

        return [
            'stability' => $stability,
            'similarity_boost' => $similarityBoost,
            'style' => $style,
            'use_speaker_boost' => $useSpeakerBoost,
        ];
    }

    protected function convertToWav(string $input, string $output, array $options): void
    {
        $gainDb = $options['gain_db'] ?? 0.0;

        $filter = sprintf(
            'aresample=48000,' .
            'aformat=sample_fmts=fltp:channel_layouts=stereo,' .
            'volume=%sdB,' .
            'loudnorm=I=-16:TP=-1.5:LRA=11,' .
            'aresample=48000',
            $gainDb >= 0 ? "+{$gainDb}" : $gainDb
        );

        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -vn -af %s -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($input),
            escapeshellarg($filter),
            escapeshellarg($output)
        );

        exec($cmd, $out, $code);

        if ($code !== 0 || !file_exists($output) || filesize($output) < 5000) {
            throw new RuntimeException("Audio conversion failed");
        }
    }
}
