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

        // Infer gender and age from pitch when unknown (lite mode skips gender/age ML)
        // Child voice: typically >300 Hz median pitch
        // Female voice: typically 165-300 Hz
        // Male voice: typically <150 Hz
        if ($speaker->pitch_median_hz) {
            $pitch_hz = (float) $speaker->pitch_median_hz;

            if ($pitch_hz > 300 && $age === 'unknown') {
                $age = 'child';
                $speaker->age_group = 'child';
            }

            if ($gender === 'unknown') {
                if ($pitch_hz >= 165) {
                    $gender = 'female';
                    $speaker->gender = 'female';
                } elseif ($pitch_hz <= 150) {
                    $gender = 'male';
                    $speaker->gender = 'male';
                }
            }
        }

        // Get speaker index for this video to assign different voices
        $speakerIndex = $this->getSpeakerIndex($video, $speaker, $gender);

        // Voice pools by language and gender - different speakers get different voices
        $voicePools = [
            'uz' => [
                'male'   => ['uz-UZ-SardorNeural'],
                'female' => ['uz-UZ-MadinaNeural'],
            ],
            'ru' => [
                'male'   => ['ru-RU-DmitryNeural'],
                'female' => ['ru-RU-SvetlanaNeural'],
            ],
            'en' => [
                'male'   => ['en-US-GuyNeural'],
                'female' => ['en-US-JennyNeural'],
            ],
        ];

        // Determine language pool
        $lang = $this->isUzbek($target) ? 'uz' : ($this->isRussian($target) ? 'ru' : 'en');
        $genderKey = ($gender === 'female') ? 'female' : 'male';

        $voices = $voicePools[$lang][$genderKey] ?? $voicePools['en'][$genderKey];

        // Assign voice by cycling through available voices for variety
        $speaker->tts_voice = $voices[$speakerIndex % count($voices)];

        /**
         * Baseline "cinema" cadence:
         * - Slightly slower by default for clarity
         * - Per-speaker pitch offsets to clearly differentiate same-gender speakers
         */
        $rate  = '-2%';
        $pitch = '+0Hz';
        $gain  = 0.0;

        // Per-speaker pitch offsets — wider spread since all speakers share one base voice
        $pitchOffsets = [0, -20, +20, -35, +35, -10, +10, -45];
        $speakerPitchOffset = $pitchOffsets[$speakerIndex % count($pitchOffsets)];

        // Gender-based base pitch + speaker differentiation
        if ($gender === 'female') {
            $pitch = $this->formatPitchHz(3 + $speakerPitchOffset);
            $gain  = 0.3;
        } elseif ($gender === 'male') {
            $pitch = $this->formatPitchHz(-3 + $speakerPitchOffset);
            $gain  = 0.0;
        } else {
            $pitch = $this->formatPitchHz($speakerPitchOffset);
        }

        // Age adjustments (mild values to keep it natural, avoid ultrasound-like high pitches)
        if (in_array($age, ['child', 'kid'], true)) {
            $rate  = '+6%';
            $pitch = '+20Hz';  // Higher pitch for more noticeable child voice
            $gain += 0.2;
        } elseif (in_array($age, ['young_adult', 'young'], true)) {
            $rate  = '+1%';
            $pitch = ($gender === 'female') ? '+5Hz' : '+2Hz';  // Reduced female pitch
        } elseif (in_array($age, ['senior', 'old'], true)) {
            $rate  = '-3%';
            $pitch = ($gender === 'female') ? '+3Hz' : '-6Hz';  // Less extreme
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
                $pitch = $this->mergePitchHz($pitch, -3);  // Reduced from -5
                $gain += 0.6;
                break;

            case 'fear':
                $rate = $this->mergeRate($rate, '+2%');
                $pitch = $this->mergePitchHz($pitch, +4);  // Reduced from +6
                $gain += 0.2;
                break;

            case 'happy':
                $rate = $this->mergeRate($rate, '+2%');
                $pitch = $this->mergePitchHz($pitch, +5);  // Reduced from +10
                $gain += 0.3;
                break;

            case 'excited':
                $rate = $this->mergeRate($rate, '+4%');
                $pitch = $this->mergePitchHz($pitch, +8);  // Reduced from +14
                $gain += 0.6;
                break;

            case 'sad':
                $rate = $this->mergeRate($rate, '-2%');
                $pitch = $this->mergePitchHz($pitch, -5);  // Reduced from -8
                $gain -= 0.4;
                break;

            default:
                // neutral/unknown: keep baseline
                break;
        }

        // Clamp to safe ranges (avoid ultrasound-like high pitches)
        $rate = $this->clampRate($rate, -10, +12);      // percent
        $pitch = $this->clampPitchHz($pitch, -55, +50); // Hz - wide range for single-voice speaker differentiation
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

    private function isRussian(string $t): bool
    {
        return str_contains($t, 'ru') || str_contains($t, 'russian') || str_contains($t, 'рус');
    }

    /**
     * Get the index of this speaker among same-gender speakers in the video.
     * This allows cycling through different voices for variety.
     */
    private function getSpeakerIndex(Video $video, Speaker $speaker, string $gender): int
    {
        $sameGenderSpeakers = $video->speakers()
            ->where('gender', $gender)
            ->orderBy('id')
            ->pluck('id')
            ->toArray();

        $index = array_search($speaker->id, $sameGenderSpeakers);

        return $index !== false ? $index : 0;
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

    private function formatPitchHz(int $hz): string
    {
        return ($hz >= 0 ? '+' : '') . $hz . 'Hz';
    }
}
