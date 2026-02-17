<?php

namespace App\Services\Tts\Drivers;

use App\Contracts\TtsDriverInterface;
use App\Models\Speaker;
use App\Models\VideoSegment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Hybrid Uzbek TTS Driver — Edge TTS + OpenVoice Voice Conversion.
 *
 * Two-stage pipeline:
 * 1. Edge TTS generates speech with native Uzbek voices (correct pronunciation)
 * 2. OpenVoice v2 converts the voice tone to match the original speaker
 *
 * Fallback: If OpenVoice fails, returns Edge TTS output (correct pronunciation, generic voice).
 */
class HybridUzbekDriver implements TtsDriverInterface
{
    protected EdgeTtsDriver $edgeTts;
    protected string $openVoiceUrl;

    public function __construct()
    {
        $this->edgeTts = new EdgeTtsDriver();
        $this->openVoiceUrl = config('services.openvoice.url') ?? 'http://localhost:8005';
    }

    public function name(): string
    {
        return 'hybrid_uzbek';
    }

    public function supportsVoiceCloning(): bool
    {
        return true;
    }

    public function supportsEmotions(): bool
    {
        return $this->edgeTts->supportsEmotions();
    }

    public function getVoices(string $language): array
    {
        return $this->edgeTts->getVoices($language);
    }

    public function getCostPerCharacter(): float
    {
        return 0.0; // Both Edge TTS and OpenVoice are free (self-hosted)
    }

    public function synthesize(
        string $text,
        Speaker $speaker,
        VideoSegment $segment,
        array $options = []
    ): string {
        // Stage 1: Generate speech with Edge TTS (correct Uzbek pronunciation)
        $edgeOutputPath = $this->edgeTts->synthesize($text, $speaker, $segment, $options);

        // Stage 2: Convert voice tone via OpenVoice (if speaker has embedding)
        $speakerKey = $options['voice_id'] ?? $speaker->openvoice_speaker_key;

        if (!$speakerKey) {
            Log::info('HybridUzbek: No speaker embedding, using Edge TTS output', [
                'segment_id' => $segment->id,
                'speaker_id' => $speaker->id,
            ]);
            return $edgeOutputPath;
        }

        try {
            $convertedPath = $this->convertVoice($edgeOutputPath, $speakerKey, $segment->id);

            Log::info('HybridUzbek: Voice conversion complete', [
                'segment_id' => $segment->id,
                'speaker_key' => $speakerKey,
            ]);

            return $convertedPath;

        } catch (\Throwable $e) {
            Log::warning('HybridUzbek: OpenVoice conversion failed, using Edge TTS fallback', [
                'segment_id' => $segment->id,
                'speaker_key' => $speakerKey,
                'error' => $e->getMessage(),
            ]);

            // Fallback: return Edge TTS output (correct pronunciation, generic voice)
            return $edgeOutputPath;
        }
    }

    public function cloneVoice(string $audioPath, string $name, array $options = []): string
    {
        if (!file_exists($audioPath)) {
            throw new RuntimeException("Audio file not found: {$audioPath}");
        }

        $speakerKey = $options['speaker_key'] ?? md5($name . '_' . time());

        $response = Http::timeout(120)
            ->attach('audio', file_get_contents($audioPath), 'sample.wav')
            ->post("{$this->openVoiceUrl}/extract-se", [
                'speaker_key' => $speakerKey,
            ]);

        if ($response->failed()) {
            Log::error('HybridUzbek: Speaker embedding extraction failed', [
                'name' => $name,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException("OpenVoice speaker embedding extraction failed: " . $response->body());
        }

        Log::info('HybridUzbek: Speaker embedding extracted', [
            'name' => $name,
            'speaker_key' => $speakerKey,
        ]);

        return $speakerKey;
    }

    /**
     * Send Edge TTS WAV to OpenVoice for voice conversion.
     */
    protected function convertVoice(string $edgeWavPath, string $speakerKey, int $segmentId): string
    {
        $convertedPath = $edgeWavPath . '.converted.wav';

        $response = Http::timeout(120)
            ->attach('audio', file_get_contents($edgeWavPath), 'edge_tts.wav')
            ->withOptions(['sink' => $convertedPath])
            ->post("{$this->openVoiceUrl}/convert", [
                'speaker_key' => $speakerKey,
                'tau' => 0.3,
            ]);

        if ($response->failed()) {
            @unlink($convertedPath);
            throw new RuntimeException(
                "OpenVoice conversion failed for segment {$segmentId}: HTTP {$response->status()}"
            );
        }

        if (!file_exists($convertedPath) || filesize($convertedPath) < 1000) {
            @unlink($convertedPath);
            throw new RuntimeException(
                "OpenVoice conversion produced invalid output for segment {$segmentId}"
            );
        }

        // Replace original Edge TTS file with converted version
        rename($convertedPath, $edgeWavPath);

        return $edgeWavPath;
    }
}
