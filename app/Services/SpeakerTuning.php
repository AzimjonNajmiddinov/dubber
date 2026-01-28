<?php

namespace App\Services;

use App\Models\Speaker;
use App\Models\Video;

class SpeakerTuning
{
    /**
     * Production defaults tuned for "movie dub" with Edge-TTS:
     * - Avoid extreme pitch/rate (sounds robotic fast)
     * - Keep dialogue slightly slower and clearer
     * - Mild gain differences only (final loudness is handled by loudnorm later)
     */
    public function applyDefaults(Video $video, Speaker $speaker): void
    {
        $target = strtolower(trim((string) ($video->target_language ?? 'uz')));
        if (! $this->isUzbek($target)) {
            $target = 'uz';
        }

        $gender  = strtolower((string) ($speaker->gender ?? 'unknown'));
        $emotion = strtolower((string) ($speaker->emotion ?? 'neutral'));
        $age     = strtolower((string) ($speaker->age_group ?? 'unknown'));

        // Uzbek Edge voices (keep these stable; swapping voices mid-movie feels wrong)
        $maleVoice   = 'uz-UZ-SardorNeural';
        $femaleVoice = 'uz-UZ-MadinaNeural';

        // Voice selection
        $speaker->tts_voice = ($gender === 'female') ? $femaleVoice : $maleVoice;

        /**
         * Baseline "cinema" cadence:
         * - Slightly slower by default for clarity
         * - Small pitch offsets only (Edge pitch in Hz is sensitive)
         */
        $rate  = '-2%';
        $pitch = '+0Hz';
        $gain  = 0.0;

        // Gender-based micro tuning (very mild)
        if ($gender === 'female') {
            $pitch = '+10Hz';
            $gain  = 0.3;
        } elseif ($gender === 'male') {
            $pitch = '-5Hz';
            $gain  = 0.0;
        }

        // Age adjustments (still mild; big deltas sound fake)
        // You can adapt labels to your whisperx meta if needed.
        if (in_array($age, ['child', 'kid'], true)) {
            $rate  = '+6%';
            $pitch = '+35Hz';
            $gain += 0.2;
        } elseif (in_array($age, ['young_adult', 'young'], true)) {
            $rate  = '+1%';
            $pitch = ($gender === 'female') ? '+12Hz' : '+2Hz';
        } elseif (in_array($age, ['senior', 'old'], true)) {
            $rate  = '-3%';
            $pitch = ($gender === 'female') ? '+5Hz' : '-10Hz';
            $gain -= 0.2;
        }

        /**
         * Emotion tuning for MOVIE dubbing:
         * Keep it subtle. Edge-TTS "acting" is limited; too much = cartoonish.
         */
        switch ($emotion) {
            case 'angry':
            case 'frustration':
                $rate = $this->mergeRate($rate, '+3%');
                $pitch = $this->mergePitchHz($pitch, -5);
                $gain += 0.6;
                break;

            case 'fear':
                $rate = $this->mergeRate($rate, '+2%');
                $pitch = $this->mergePitchHz($pitch, +6);
                $gain += 0.2;
                break;

            case 'happy':
                $rate = $this->mergeRate($rate, '+2%');
                $pitch = $this->mergePitchHz($pitch, +10);
                $gain += 0.3;
                break;

            case 'excited':
                $rate = $this->mergeRate($rate, '+4%');
                $pitch = $this->mergePitchHz($pitch, +14);
                $gain += 0.6;
                break;

            case 'sad':
                $rate = $this->mergeRate($rate, '-2%');
                $pitch = $this->mergePitchHz($pitch, -8);
                $gain -= 0.4;
                break;

            default:
                // neutral/unknown: keep baseline
                break;
        }

        // Clamp to safe ranges (Edge-TTS can get weird outside these)
        $rate = $this->clampRate($rate, -10, +12);      // percent
        $pitch = $this->clampPitchHz($pitch, -40, +60); // Hz
        $gain = $this->clampFloat($gain, -3.0, +3.0);   // dB

        $speaker->tts_rate = $rate;
        $speaker->tts_pitch = $pitch;
        $speaker->tts_gain_db = $gain;
    }

    private function isUzbek(string $t): bool
    {
        if ($t === '') return true;
        return str_contains($t, 'uz') || str_contains($t, 'uzbek') || str_contains($t, 'uz-uz') || str_contains($t, 'ўз') || str_contains($t, 'уз');
    }

    private function mergeRate(string $base, string $adj): string
    {
        $b = $this->parseSignedNumber($base, '%');
        $a = $this->parseSignedNumber($adj, '%');
        $sum = (int) round($b + $a);
        return ($sum >= 0 ? '+' : '') . $sum . '%';
    }

    private function mergePitchHz(string $base, int $adjHz): string
    {
        $b = (int) round($this->parseSignedNumber($base, 'hz'));
        $sum = $b + $adjHz;
        return ($sum >= 0 ? '+' : '') . $sum . 'Hz';
    }

    private function parseSignedNumber(string $s, string $suffix): float
    {
        $s = trim(strtolower($s));
        $suffix = strtolower($suffix);

        $s = str_replace($suffix, '', $s);
        $s = str_replace('%', '', $s);
        $s = str_replace('hz', '', $s);

        $s = preg_replace('/[^0-9\.\-\+]/', '', $s);
        if ($s === '' || $s === '+' || $s === '-') return 0.0;

        return (float) $s;
    }

    private function clampRate(string $rate, int $minPct, int $maxPct): string
    {
        $v = (int) round($this->parseSignedNumber($rate, '%'));
        $v = max($minPct, min($maxPct, $v));
        return ($v >= 0 ? '+' : '') . $v . '%';
    }

    private function clampPitchHz(string $pitch, int $minHz, int $maxHz): string
    {
        $v = (int) round($this->parseSignedNumber($pitch, 'hz'));
        $v = max($minHz, min($maxHz, $v));
        return ($v >= 0 ? '+' : '') . $v . 'Hz';
    }

    private function clampFloat(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }
}
