<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * NaturalSpeechProcessor - Makes TTS output sound more human.
 *
 * Techniques:
 * 1. Insert breath sounds at natural phrase boundaries
 * 2. Add subtle pitch micro-variations (warmth)
 * 3. Add room ambience for consistency
 * 4. Apply emotion-specific filters (breathiness, tension)
 */
class NaturalSpeechProcessor
{
    protected string $breathSamplesPath;

    public function __construct()
    {
        $this->breathSamplesPath = storage_path('app/audio/breaths');
    }

    /**
     * Process TTS audio to sound more natural and human.
     *
     * @param string $audioPath Path to TTS audio file
     * @param array $direction Acting direction from ActingDirector
     * @param array $options Additional options
     * @return string Path to processed audio
     */
    public function process(string $audioPath, array $direction, array $options = []): string
    {
        if (!file_exists($audioPath)) {
            return $audioPath;
        }

        $outputPath = $audioPath . '.natural.wav';
        $filters = [];

        // 1. Add micro pitch variations for warmth (very subtle)
        // Human voices have slight frequency wobbles (1-3Hz, ±5 cents)
        $filters[] = 'vibrato=f=2:d=0.003';

        // 2. Apply emotion-specific processing
        $emotionFilters = $this->getEmotionFilters($direction);
        $filters = array_merge($filters, $emotionFilters);

        // 3. Apply vocal quality filters
        $qualityFilters = $this->getVocalQualityFilters($direction['vocal_quality'] ?? []);
        $filters = array_merge($filters, $qualityFilters);

        // 4. Add subtle warmth (very gentle low-mid boost)
        $filters[] = 'equalizer=f=200:t=q:w=1:g=1';

        // 5. Gentle high-frequency rolloff (natural voice characteristic)
        $filters[] = 'lowpass=f=14000';

        // Build filter chain
        $filterChain = implode(',', array_filter($filters));

        if (empty($filterChain)) {
            return $audioPath;
        }

        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -af %s -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($audioPath),
            escapeshellarg($filterChain),
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $code);

        if ($code === 0 && file_exists($outputPath) && filesize($outputPath) > 1000) {
            // Replace original with processed
            rename($outputPath, $audioPath);

            Log::debug('Natural speech processing applied', [
                'path' => basename($audioPath),
                'filters' => count($filters),
                'emotion' => $direction['emotion'] ?? 'neutral',
                'delivery' => $direction['delivery'] ?? 'normal',
            ]);
        } else {
            @unlink($outputPath);
            Log::warning('Natural speech processing failed', [
                'path' => basename($audioPath),
                'code' => $code,
            ]);
        }

        return $audioPath;
    }

    /**
     * Get FFmpeg filters for emotion.
     */
    protected function getEmotionFilters(array $direction): array
    {
        $filters = [];
        $emotion = $direction['emotion'] ?? 'neutral';
        $intensity = $direction['emotion_intensity'] ?? 0.5;
        $delivery = $direction['delivery'] ?? 'normal';

        // Breathy delivery (whisper, intimate)
        if (in_array($delivery, ['whisper', 'breathy', 'soft'])) {
            // Emphasize breath frequencies, reduce low bass
            $filters[] = 'highpass=f=120';
            $filters[] = 'equalizer=f=2500:t=q:w=2:g=' . round(3 * $intensity);
            // Add subtle noise for breathiness
            $noiseLevel = $delivery === 'whisper' ? 0.02 : 0.01;
            // Note: noise injection would require mixing with noise file
        }

        // Tense delivery (anger, stress)
        if (in_array($delivery, ['tense', 'strained', 'shout'])) {
            // Slight edge/harshness
            $filters[] = 'equalizer=f=3000:t=q:w=1:g=' . round(2 * $intensity);
            // Gentle compression for intensity
            $filters[] = 'acompressor=threshold=-20dB:ratio=2:attack=10:release=100';
        }

        // Trembling (fear, overwhelming emotion)
        if ($delivery === 'trembling' || in_array('trembling', $direction['vocal_quality'] ?? [])) {
            // Add tremolo effect (amplitude modulation)
            $tremoloDepth = 0.05 + (0.1 * $intensity);
            $filters[] = "tremolo=f=6:d={$tremoloDepth}";
        }

        // Sad/Tender - softer, warmer
        if (in_array($emotion, ['sad', 'tender']) && $intensity > 0.3) {
            $filters[] = 'lowpass=f=12000'; // Softer high end
            $filters[] = 'equalizer=f=300:t=q:w=1:g=2'; // Warmer low-mids
        }

        // Angry - more presence
        if ($emotion === 'angry' && $intensity > 0.4) {
            $filters[] = 'equalizer=f=2000:t=q:w=2:g=' . round(3 * $intensity);
        }

        return $filters;
    }

    /**
     * Get FFmpeg filters for vocal qualities.
     */
    protected function getVocalQualityFilters(array $qualities): array
    {
        $filters = [];

        foreach ($qualities as $quality) {
            switch ($quality) {
                case 'breathy':
                    $filters[] = 'highpass=f=100';
                    $filters[] = 'equalizer=f=2000:t=q:w=2:g=2';
                    break;

                case 'tense':
                    $filters[] = 'equalizer=f=3500:t=q:w=1:g=3';
                    break;

                case 'trembling':
                    // Handled in emotion filters
                    break;

                case 'creaky':
                    // Vocal fry effect - gentle low-frequency emphasis
                    $filters[] = 'equalizer=f=80:t=q:w=0.5:g=3';
                    break;

                case 'nasal':
                    // Boost nasal frequencies
                    $filters[] = 'equalizer=f=1000:t=q:w=2:g=3';
                    $filters[] = 'equalizer=f=2500:t=q:w=1:g=-2';
                    break;

                case 'strained':
                    $filters[] = 'acompressor=threshold=-15dB:ratio=3:attack=5:release=50';
                    $filters[] = 'equalizer=f=4000:t=q:w=1:g=2';
                    break;
            }
        }

        return $filters;
    }

    /**
     * Insert breath sound before the TTS audio.
     *
     * @param string $audioPath TTS audio path
     * @param string $breathType Type: 'light', 'deep', 'tense', 'shaky', 'quick'
     * @param string $emotion For selecting appropriate breath
     * @return string Path to audio with breath
     */
    public function insertBreath(string $audioPath, string $breathType = 'light', string $emotion = 'neutral'): string
    {
        $breathFile = $this->getBreathSample($breathType, $emotion);

        if (!$breathFile || !file_exists($breathFile)) {
            // Generate synthetic breath using pink noise burst
            return $this->insertSyntheticBreath($audioPath, $breathType);
        }

        $outputPath = $audioPath . '.with_breath.wav';

        // Concatenate breath + TTS with crossfade
        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error ' .
            '-i %s -i %s ' .
            '-filter_complex "[0:a]volume=0.3[breath];[breath][1:a]acrossfade=d=0.05:c1=tri:c2=tri[out]" ' .
            '-map "[out]" -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($breathFile),
            escapeshellarg($audioPath),
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $code);

        if ($code === 0 && file_exists($outputPath) && filesize($outputPath) > 1000) {
            rename($outputPath, $audioPath);
            return $audioPath;
        }

        @unlink($outputPath);
        return $audioPath;
    }

    /**
     * Insert synthetic breath (pink noise burst).
     */
    protected function insertSyntheticBreath(string $audioPath, string $breathType): string
    {
        // Breath characteristics
        $params = match($breathType) {
            'deep' => ['duration' => 0.4, 'volume' => 0.08],
            'tense' => ['duration' => 0.2, 'volume' => 0.06],
            'shaky' => ['duration' => 0.35, 'volume' => 0.05],
            'quick' => ['duration' => 0.15, 'volume' => 0.04],
            default => ['duration' => 0.25, 'volume' => 0.05], // light
        };

        $breathPath = "/tmp/breath_" . uniqid() . ".wav";
        $outputPath = $audioPath . '.with_breath.wav';

        // Generate breath-like sound using filtered noise with envelope
        // Pink noise filtered to breath frequencies (100-4000Hz) with fade in/out
        $breathCmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error ' .
            '-f lavfi -i "anoisesrc=d=%s:c=pink:a=%s" ' .
            '-af "highpass=f=100,lowpass=f=4000,afade=t=in:st=0:d=0.05,afade=t=out:st=%s:d=0.1" ' .
            '-ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            $params['duration'],
            $params['volume'],
            $params['duration'] - 0.1,
            escapeshellarg($breathPath)
        );

        exec($breathCmd, $output, $code);

        if ($code !== 0 || !file_exists($breathPath)) {
            return $audioPath;
        }

        // Concatenate breath + audio
        $concatCmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error ' .
            '-i %s -i %s ' .
            '-filter_complex "[0:a][1:a]concat=n=2:v=0:a=1[out]" ' .
            '-map "[out]" -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($breathPath),
            escapeshellarg($audioPath),
            escapeshellarg($outputPath)
        );

        exec($concatCmd, $output, $code);
        @unlink($breathPath);

        if ($code === 0 && file_exists($outputPath) && filesize($outputPath) > 1000) {
            rename($outputPath, $audioPath);

            Log::debug('Synthetic breath inserted', [
                'path' => basename($audioPath),
                'type' => $breathType,
                'duration' => $params['duration'],
            ]);

            return $audioPath;
        }

        @unlink($outputPath);
        return $audioPath;
    }

    /**
     * Get appropriate breath sample file.
     */
    protected function getBreathSample(string $type, string $emotion): ?string
    {
        // Map emotion to breath type
        $breathType = match($emotion) {
            'angry' => 'tense',
            'fear' => 'quick',
            'sad' => 'shaky',
            'excited' => 'quick',
            default => $type,
        };

        $dir = "{$this->breathSamplesPath}/{$breathType}";

        if (!is_dir($dir)) {
            return null;
        }

        $files = glob("{$dir}/*.wav");

        if (empty($files)) {
            return null;
        }

        // Random selection for variety
        return $files[array_rand($files)];
    }

    /**
     * Add pause/silence at a specific position.
     */
    public function addPause(string $audioPath, float $duration, string $position = 'start'): string
    {
        if ($duration <= 0) {
            return $audioPath;
        }

        $outputPath = $audioPath . '.with_pause.wav';
        $silencePath = "/tmp/silence_" . uniqid() . ".wav";

        // Generate silence
        $silenceCmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -f lavfi -i anullsrc=r=48000:cl=stereo -t %s -c:a pcm_s16le %s 2>&1',
            $duration,
            escapeshellarg($silencePath)
        );
        exec($silenceCmd);

        if (!file_exists($silencePath)) {
            return $audioPath;
        }

        // Concatenate based on position
        $inputs = $position === 'start'
            ? [$silencePath, $audioPath]
            : [$audioPath, $silencePath];

        $concatCmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error ' .
            '-i %s -i %s ' .
            '-filter_complex "[0:a][1:a]concat=n=2:v=0:a=1[out]" ' .
            '-map "[out]" -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($inputs[0]),
            escapeshellarg($inputs[1]),
            escapeshellarg($outputPath)
        );

        exec($concatCmd, $output, $code);
        @unlink($silencePath);

        if ($code === 0 && file_exists($outputPath) && filesize($outputPath) > 1000) {
            rename($outputPath, $audioPath);
            return $audioPath;
        }

        @unlink($outputPath);
        return $audioPath;
    }

    /**
     * Process text to add paralinguistic markers for compatible TTS.
     * Converts emotion cues to tags like [laugh], [sigh], etc.
     */
    public function addParalinguisticMarkers(string $text, array $paralinguistics): string
    {
        if (empty($paralinguistics)) {
            return $text;
        }

        $startMarkers = [];
        $endMarkers = [];

        foreach ($paralinguistics as $cue) {
            $marker = match($cue['type']) {
                'laugh' => '[laugh]',
                'sigh' => '[sigh]',
                'gasp' => '[gasp]',
                'sob' => '[sob]',
                'cough' => '[cough]',
                'breath' => '', // Handled separately
                'shaky_breath' => '',
                'quick_breath' => '',
                'heavy_breath' => '',
                default => '',
            };

            if (empty($marker)) continue;

            if ($cue['position'] === 'start') {
                $startMarkers[] = $marker;
            } elseif ($cue['position'] === 'end') {
                $endMarkers[] = $marker;
            } else {
                // Inline - keep in original position if found in text
            }
        }

        $result = $text;

        if (!empty($startMarkers)) {
            $result = implode(' ', $startMarkers) . ' ' . $result;
        }

        if (!empty($endMarkers)) {
            $result = $result . ' ' . implode(' ', $endMarkers);
        }

        return trim($result);
    }

    /**
     * Determine if breath should be inserted based on direction.
     */
    public function shouldInsertBreath(array $direction, float $textLength): bool
    {
        // Long text benefits from breath
        if ($textLength > 60) {
            return true;
        }

        // Check paralinguistics
        $paralinguistics = $direction['paralinguistics'] ?? [];
        foreach ($paralinguistics as $cue) {
            if (str_contains($cue['type'], 'breath')) {
                return true;
            }
        }

        // Certain emotions/deliveries benefit from breath
        $emotion = $direction['emotion'] ?? 'neutral';
        $delivery = $direction['delivery'] ?? 'normal';
        $intensity = $direction['emotion_intensity'] ?? 0.5;

        if ($intensity > 0.7 && in_array($emotion, ['sad', 'fear', 'angry'])) {
            return true;
        }

        if (in_array($delivery, ['whisper', 'breathy', 'trembling', 'strained'])) {
            return true;
        }

        return false;
    }

    /**
     * Get breath type based on emotion and delivery.
     */
    public function getBreathType(array $direction): string
    {
        $emotion = $direction['emotion'] ?? 'neutral';
        $delivery = $direction['delivery'] ?? 'normal';
        $intensity = $direction['emotion_intensity'] ?? 0.5;

        // Check explicit paralinguistics first
        foreach ($direction['paralinguistics'] ?? [] as $cue) {
            if ($cue['type'] === 'shaky_breath') return 'shaky';
            if ($cue['type'] === 'quick_breath') return 'quick';
            if ($cue['type'] === 'heavy_breath') return 'deep';
        }

        // Delivery-based
        if ($delivery === 'whisper') return 'light';
        if ($delivery === 'shout') return 'deep';
        if ($delivery === 'trembling') return 'shaky';
        if ($delivery === 'tense') return 'tense';

        // Emotion-based
        return match($emotion) {
            'angry' => $intensity > 0.6 ? 'deep' : 'tense',
            'fear' => 'quick',
            'sad' => $intensity > 0.6 ? 'shaky' : 'light',
            'excited' => 'quick',
            default => 'light',
        };
    }
}
