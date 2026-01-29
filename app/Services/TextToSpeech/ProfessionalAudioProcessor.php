<?php

namespace App\Services\TextToSpeech;

use Illuminate\Support\Facades\Log;

/**
 * Professional audio processing for movie dubbing.
 *
 * Key principles:
 * - Less is more - avoid over-processing
 * - Preserve natural dynamics
 * - Add subtle room ambiance
 * - Match original audio character
 */
class ProfessionalAudioProcessor
{
    /**
     * Process TTS audio for professional dubbing quality.
     */
    public function process(string $inputPath, string $outputPath, array $options = []): bool
    {
        $emotion = $options['emotion'] ?? 'neutral';
        $speakerGender = $options['gender'] ?? 'unknown';
        $slotDuration = $options['slot_duration'] ?? 0;
        $gainDb = $options['gain_db'] ?? 0;

        // Get input duration for time-stretching calculation
        $inputDuration = $this->getAudioDuration($inputPath);

        // Build filter chain
        $filters = $this->buildFilterChain($emotion, $speakerGender, $gainDb, $inputDuration, $slotDuration);

        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -af %s -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($inputPath),
            escapeshellarg($filters),
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $code);

        if ($code !== 0 || !file_exists($outputPath) || filesize($outputPath) < 5000) {
            Log::error('Professional audio processing failed', [
                'input' => $inputPath,
                'output' => $outputPath,
                'exit_code' => $code,
                'stderr' => implode("\n", array_slice($output, -20)),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Build the FFmpeg filter chain for natural sound.
     */
    protected function buildFilterChain(
        string $emotion,
        string $gender,
        float $gainDb,
        float $inputDuration,
        float $slotDuration
    ): string {
        $filters = [];

        // 1. Resample to 48kHz stereo
        $filters[] = 'aresample=48000';
        $filters[] = 'aformat=sample_fmts=fltp:channel_layouts=stereo';

        // 2. Time-stretch if needed (BEFORE other processing for quality)
        if ($slotDuration > 0 && $inputDuration > 0 && $inputDuration > $slotDuration * 1.05) {
            $ratio = $inputDuration / $slotDuration;
            $ratio = min($ratio, 1.4); // Cap at 1.4x to preserve quality
            $filters[] = $this->buildAtempoChain($ratio);
        }

        // 3. Gentle cleanup - preserve natural character
        $filters[] = 'highpass=f=60:poles=2';  // Gentle rumble removal
        $filters[] = 'lowpass=f=14000:poles=2'; // Gentle high cut, preserve air

        // 4. De-essing (reduce harsh sibilance) - very gentle
        $filters[] = 'equalizer=f=6500:t=q:w=2.0:g=-2';

        // 5. Emotion-specific subtle EQ (VERY gentle - just coloring)
        $filters[] = $this->getEmotionEQ($emotion, $gender);

        // 6. GENTLE compression - preserve dynamics, just control peaks
        $filters[] = $this->getGentleCompression($emotion);

        // 7. Apply speaker gain
        if (abs($gainDb) > 0.1) {
            $sign = $gainDb >= 0 ? '+' : '';
            $filters[] = "volume={$sign}{$gainDb}dB";
        }

        // 8. Add subtle room ambiance (makes it sound less "in your face")
        $filters[] = $this->getRoomAmbiance();

        // 9. Final loudness normalization - broadcast standard but GENTLE
        // Using higher LRA (loudness range) to preserve dynamics
        $filters[] = 'loudnorm=I=-16:TP=-1.5:LRA=13:print_format=none';

        // 10. Final subtle limiter (safety only)
        $filters[] = 'alimiter=limit=0.98:attack=5:release=50';

        return implode(',', array_filter($filters));
    }

    /**
     * Get emotion-specific EQ - VERY subtle coloring only.
     */
    protected function getEmotionEQ(string $emotion, string $gender): string
    {
        // Very subtle adjustments - just enough to color the voice
        $eq = match (strtolower($emotion)) {
            'happy', 'excited' =>
                // Slight brightness
                'equalizer=f=3000:t=q:w=1.5:g=+1.5,equalizer=f=8000:t=q:w=2:g=+1',

            'sad', 'melancholy' =>
                // Slight warmth, reduce brightness
                'equalizer=f=200:t=q:w=1:g=+1,equalizer=f=5000:t=q:w=2:g=-1',

            'angry', 'frustrated' =>
                // Slight mid presence
                'equalizer=f=2000:t=q:w=1.5:g=+2,equalizer=f=4000:t=q:w=2:g=+1',

            'fear', 'anxious' =>
                // Slight upper-mid presence (tension)
                'equalizer=f=3500:t=q:w=2:g=+1.5',

            'whispering', 'secretive' =>
                // Boost intimacy frequencies
                'equalizer=f=150:t=q:w=1:g=+2,equalizer=f=2500:t=q:w=2:g=+1',

            default =>
                // Neutral - very subtle presence boost
                'equalizer=f=2800:t=q:w=2:g=+1'
        };

        // Gender-specific subtle adjustment
        if ($gender === 'female') {
            $eq .= ',equalizer=f=4000:t=q:w=2:g=+0.5'; // Slight air
        } elseif ($gender === 'male') {
            $eq .= ',equalizer=f=120:t=q:w=1:g=+0.5'; // Slight chest
        }

        return $eq;
    }

    /**
     * Get gentle compression settings - preserve natural dynamics.
     */
    protected function getGentleCompression(string $emotion): string
    {
        // Much gentler compression than before
        // Goal: control peaks, not squash dynamics
        return match (strtolower($emotion)) {
            'angry', 'excited' =>
                // Slightly more compression for intense emotions
                'acompressor=threshold=-18dB:ratio=2.5:attack=20:release=150:makeup=1dB:knee=6dB',

            'whispering', 'sad' =>
                // Very gentle for quiet emotions
                'acompressor=threshold=-24dB:ratio=1.5:attack=30:release=200:makeup=0.5dB:knee=8dB',

            default =>
                // Normal gentle compression
                'acompressor=threshold=-20dB:ratio=2:attack=25:release=180:makeup=1dB:knee=6dB'
        };
    }

    /**
     * Add subtle room ambiance for natural sound.
     */
    protected function getRoomAmbiance(): string
    {
        // Very subtle reverb - just enough to not sound "dry" and artificial
        // Small room, short decay, mostly early reflections
        return 'aecho=0.8:0.7:20|30:0.1|0.07';
    }

    /**
     * Build atempo filter chain for time-stretching.
     */
    protected function buildAtempoChain(float $ratio): string
    {
        if ($ratio <= 1.0) {
            return '';
        }

        $filters = [];
        $remaining = $ratio;

        while ($remaining > 1.0) {
            $step = min($remaining, 2.0);
            $filters[] = 'atempo=' . number_format($step, 4, '.', '');
            $remaining = $remaining / $step;

            if (count($filters) >= 3) break;
        }

        return implode(',', $filters);
    }

    /**
     * Get audio duration using ffprobe.
     */
    protected function getAudioDuration(string $path): float
    {
        if (!file_exists($path)) {
            return 0;
        }

        $cmd = sprintf(
            'ffprobe -hide_banner -loglevel error -show_entries format=duration -of default=nw=1:nk=1 %s',
            escapeshellarg($path)
        );

        $output = shell_exec($cmd);
        return (float) trim($output ?: '0');
    }
}
