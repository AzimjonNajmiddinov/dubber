<?php

namespace App\Services\Tts\Drivers;

use App\Contracts\TtsDriverInterface;
use App\Models\Speaker;
use App\Models\VideoSegment;
use App\Services\EmotionDSPProcessor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Hybrid Uzbek TTS Driver — Edge TTS + Emotion DSP + OpenVoice Voice Conversion.
 *
 * Three-stage pipeline:
 * 1. Edge TTS generates speech with native Uzbek voices (correct pronunciation)
 * 2. EmotionDSPProcessor adds expressiveness (pitch shift, tempo, EQ, dynamics)
 * 3. OpenVoice v2 converts voice timbre to match original speaker (preserves emotion)
 *
 * Fallback: If OpenVoice fails, returns Edge TTS + emotion output (correct pronunciation, generic voice).
 */
class HybridUzbekDriver implements TtsDriverInterface
{
    protected EdgeTtsDriver $edgeTts;
    protected string $openVoiceUrl;
    protected float $currentTau = 0.3;

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
        // Set per-speaker OpenVoice tau (voice cloning strength)
        // Higher tau = more speaker resemblance, lower = more generic but cleaner
        // Based on voice sample quality: longer samples → higher confidence → higher tau
        $this->currentTau = $speaker->openvoice_tau
            ?? $this->calculateTau($speaker);

        // Stage 1: Generate speech with Edge TTS (correct Uzbek pronunciation)
        $edgeOutputPath = $this->edgeTts->synthesize($text, $speaker, $segment, $options);

        // Stage 1.5: Apply emotion DSP (pitch shift, tempo, EQ, dynamics)
        // This runs BEFORE OpenVoice because OpenVoice preserves prosody/emotion
        // while only changing voice identity (timbre). So emotion survives conversion.
        $actingDirection = $options['acting_direction'] ?? [];
        if (!empty($actingDirection)) {
            $emotion = $actingDirection['emotion'] ?? 'neutral';
            $delivery = $actingDirection['delivery'] ?? 'normal';
            if ($emotion !== 'neutral' || $delivery !== 'normal') {
                // Scale emotion intensity by speaker's expressiveness
                // Some speakers are more expressive (0.8-1.0), others are restrained (0.2-0.5)
                $speakerExpressiveness = $speaker->expressiveness ?? 0.6;
                $baseIntensity = $actingDirection['emotion_intensity'] ?? 0.5;
                $actingDirection['emotion_intensity'] = $baseIntensity * $speakerExpressiveness;

                $emotionDsp = app(EmotionDSPProcessor::class);
                $emotionDsp->apply($edgeOutputPath, $actingDirection);
            }
        }

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
                'tau' => $this->currentTau,
                'expressiveness' => $speaker->expressiveness ?? 'default',
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
     * Extract speaker voice sample from original audio.
     */
    public function extractVoiceSample(int $videoId, int $speakerId): string
    {
        $speaker = Speaker::findOrFail($speakerId);

        // Get segments for this speaker
        $segments = VideoSegment::where('video_id', $videoId)
            ->where('speaker_id', $speakerId)
            ->orderBy('end_time', 'desc')
            ->orderByRaw('(end_time - start_time) DESC')
            ->limit(5)
            ->get();

        if ($segments->isEmpty()) {
            throw new RuntimeException("No segments found for speaker {$speakerId}");
        }

        // Use the vocals track if available, otherwise original audio
        $audioPath = Storage::disk('local')->path("audio/stems/{$videoId}/vocals.wav");
        if (!file_exists($audioPath)) {
            $audioPath = Storage::disk('local')->path("audio/original/{$videoId}.wav");
        }

        if (!file_exists($audioPath)) {
            throw new RuntimeException("No audio source found for voice extraction");
        }

        // Extract segments and concatenate
        $samplePath = Storage::disk('local')->path("audio/voice_samples/{$videoId}/speaker_{$speakerId}.wav");
        @mkdir(dirname($samplePath), 0777, true);

        $filterParts = [];
        foreach ($segments as $i => $seg) {
            $start = max(0, $seg->start_time);
            $end = $seg->end_time;
            $filterParts[] = sprintf(
                "[0:a]atrim=start=%s:end=%s,asetpts=PTS-STARTPTS[s%d]",
                $start,
                $end,
                $i
            );
        }

        $concatInputs = implode('', array_map(fn($i) => "[s{$i}]", range(0, count($segments) - 1)));
        $filter = implode(';', $filterParts) . ";{$concatInputs}concat=n=" . count($segments) . ":v=0:a=1[out]";

        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -filter_complex %s -map "[out]" -c:a pcm_s16le -ar 22050 -ac 1 %s 2>&1',
            escapeshellarg($audioPath),
            escapeshellarg($filter),
            escapeshellarg($samplePath)
        );

        exec($cmd, $output, $code);

        if ($code !== 0 || !file_exists($samplePath) || filesize($samplePath) < 5000) {
            Log::error('Voice sample extraction failed', [
                'speaker_id' => $speakerId,
                'exit_code' => $code,
                'output' => implode("\n", array_slice($output, -20)),
            ]);
            throw new RuntimeException("Failed to extract voice sample for speaker {$speakerId}");
        }

        return $samplePath;
    }

    /**
     * Calculate OpenVoice tau based on speaker voice sample quality.
     *
     * tau controls how much the output sounds like the original speaker:
     *   0.0 = Edge TTS voice (no conversion)
     *   0.2 = Light tint of speaker's voice
     *   0.3 = Default — mild, natural blend
     *   0.5 = Strong resemblance
     *   0.7 = Very close to original speaker
     *   1.0 = Full conversion (can sound artificial)
     *
     * We scale tau by voice sample duration — longer samples = better embeddings = higher tau.
     */
    protected function calculateTau(Speaker $speaker): float
    {
        $sampleDuration = $speaker->voice_sample_duration ?? 0;

        if ($sampleDuration <= 0 || !$speaker->voice_cloned) {
            return 0.3; // Default
        }

        // Scale: 2s → 0.2, 5s → 0.3, 10s → 0.4, 20s+ → 0.5
        // We cap at 0.5 to keep output natural (full conversion can sound robotic)
        $tau = 0.15 + min(0.35, $sampleDuration / 60);

        return round($tau, 2);
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
                'tau' => $this->currentTau,
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
