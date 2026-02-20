<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * EmotionDSPProcessor — Adds emotional expressiveness to flat TTS audio.
 *
 * Uses FFmpeg audio filters (rubberband, tremolo, vibrato, EQ, compression)
 * to transform neutral speech into emotionally expressive speech.
 *
 * Designed to run BETWEEN Edge TTS and OpenVoice in the hybrid pipeline:
 *   1. Edge TTS → correct Uzbek pronunciation (flat/neutral)
 *   2. EmotionDSP → pitch shift, tempo, EQ, dynamics per emotion
 *   3. OpenVoice → voice identity swap (preserves emotion from step 2)
 *
 * Based on psychoacoustic research on acoustic correlates of emotion:
 * - Happy/Excited: higher pitch, wider pitch range, faster, brighter
 * - Sad/Tender: lower pitch, narrower range, slower, darker
 * - Angry: higher pitch, very wide range, faster, harsh, compressed
 * - Fear: higher pitch, erratic, faster, trembling
 */
class EmotionDSPProcessor
{
    /**
     * Emotion DSP recipes.
     *
     * pitch_semitones: pitch shift via rubberband (positive = higher)
     * tempo: speed multiplier (>1 = faster)
     * volume_db: volume adjustment
     * eq: array of EQ filters [frequency, Q-width, gain_db]
     * tremolo: [frequency_hz, depth] for amplitude modulation
     * vibrato: [frequency_hz, depth] for pitch modulation
     * compress: [threshold_db, ratio] for dynamic compression
     * lowpass: cutoff frequency (0 = disabled)
     */
    protected array $emotionRecipes = [
        'happy' => [
            'pitch_semitones' => 1.5,
            'tempo' => 1.08,
            'volume_db' => 2.0,
            'eq' => [[3000, 2.0, 3.0]], // brighter presence
            'tremolo' => null,
            'vibrato' => null,
            'compress' => null,
            'lowpass' => 0,
        ],
        'excited' => [
            'pitch_semitones' => 2.0,
            'tempo' => 1.12,
            'volume_db' => 3.0,
            'eq' => [[3000, 2.0, 4.0], [800, 1.0, 1.5]],
            'tremolo' => null,
            'vibrato' => null,
            'compress' => null,
            'lowpass' => 0,
        ],
        'sad' => [
            'pitch_semitones' => -1.0,
            'tempo' => 0.88,
            'volume_db' => -3.0,
            'eq' => [[300, 1.0, 2.0]], // warmer low-mids
            'tremolo' => null,
            'vibrato' => [3.0, 0.003], // subtle warmth wobble
            'compress' => null,
            'lowpass' => 10000, // softer highs
        ],
        'angry' => [
            'pitch_semitones' => 2.0,
            'tempo' => 1.10,
            'volume_db' => 4.0,
            'eq' => [[3000, 1.5, 5.0], [800, 1.0, 2.0]], // harsh presence
            'tremolo' => null,
            'vibrato' => null,
            'compress' => [-15, 4], // heavy compression for intensity
            'lowpass' => 0,
        ],
        'fear' => [
            'pitch_semitones' => 2.0,
            'tempo' => 1.12,
            'volume_db' => -1.0,
            'eq' => [[4000, 2.0, 2.0]], // slight edge
            'tremolo' => [7.0, 0.12], // trembling amplitude
            'vibrato' => [8.0, 0.015], // pitch wobble
            'compress' => null,
            'lowpass' => 0,
        ],
        'surprise' => [
            'pitch_semitones' => 3.0,
            'tempo' => 1.05,
            'volume_db' => 3.0,
            'eq' => [[3500, 2.0, 3.0]], // bright, open
            'tremolo' => null,
            'vibrato' => null,
            'compress' => null,
            'lowpass' => 0,
        ],
        'tender' => [
            'pitch_semitones' => -0.5,
            'tempo' => 0.92,
            'volume_db' => -4.0,
            'eq' => [[200, 1.0, 2.0]], // warm
            'tremolo' => null,
            'vibrato' => [4.0, 0.004], // gentle warmth
            'compress' => null,
            'lowpass' => 12000, // gentle rolloff
        ],
        'contempt' => [
            'pitch_semitones' => -0.5,
            'tempo' => 0.95,
            'volume_db' => 0.0,
            'eq' => [[1000, 2.0, 2.0]], // nasal
            'tremolo' => null,
            'vibrato' => null,
            'compress' => null,
            'lowpass' => 0,
        ],
        'neutral' => [
            'pitch_semitones' => 0.0,
            'tempo' => 1.0,
            'volume_db' => 0.0,
            'eq' => [],
            'tremolo' => null,
            'vibrato' => null,
            'compress' => null,
            'lowpass' => 0,
        ],
    ];

    /**
     * Delivery-style DSP overrides applied on top of emotion.
     */
    protected array $deliveryModifiers = [
        'whisper' => [
            'volume_db' => -8.0,
            'eq' => [[2500, 2.0, 4.0]], // emphasize breath frequencies
            'highpass' => 120,
        ],
        'soft' => [
            'volume_db' => -4.0,
            'tempo_mult' => 0.95,
        ],
        'shout' => [
            'volume_db' => 5.0,
            'compress' => [-12, 5],
            'eq' => [[2500, 1.5, 5.0]],
        ],
        'trembling' => [
            'tremolo' => [6.0, 0.10],
            'vibrato' => [7.0, 0.012],
        ],
        'tense' => [
            'eq' => [[3500, 1.0, 3.0]],
            'compress' => [-20, 2],
        ],
        'strained' => [
            'compress' => [-15, 3],
            'eq' => [[4000, 1.0, 3.0]],
            'volume_db' => 2.0,
        ],
        'breathy' => [
            'volume_db' => -5.0,
            'eq' => [[2000, 2.0, 3.0]],
            'highpass' => 100,
        ],
        'pleading' => [
            'pitch_semitones' => 1.0,
            'tempo_mult' => 1.05,
            'vibrato' => [5.0, 0.008],
        ],
    ];

    /**
     * Apply emotion DSP to an audio file in-place.
     *
     * @param string $audioPath Path to WAV file (modified in-place)
     * @param array $actingDirection Acting direction from the pipeline
     * @return bool Whether processing was applied
     */
    public function apply(string $audioPath, array $actingDirection): bool
    {
        if (!file_exists($audioPath) || filesize($audioPath) < 1000) {
            return false;
        }

        $emotion = strtolower($actingDirection['emotion'] ?? 'neutral');
        $intensity = (float) ($actingDirection['emotion_intensity'] ?? 0.5);
        $delivery = strtolower($actingDirection['delivery'] ?? 'normal');

        // Skip processing for neutral emotion with normal delivery
        if ($emotion === 'neutral' && $delivery === 'normal') {
            return false;
        }

        // Get base recipe for emotion
        $recipe = $this->emotionRecipes[$emotion] ?? $this->emotionRecipes['neutral'];

        // Scale by intensity (0.0–1.0)
        $recipe = $this->scaleByIntensity($recipe, $intensity);

        // Apply delivery modifiers on top
        $recipe = $this->applyDeliveryModifiers($recipe, $delivery);

        // Build ffmpeg filter chain
        $filters = $this->buildFilterChain($recipe);

        if (empty($filters)) {
            return false;
        }

        // Pitch shifting requires rubberband (separate from other filters)
        $pitchSemitones = $recipe['pitch_semitones'] ?? 0.0;
        $needsPitchShift = abs($pitchSemitones) > 0.1;

        $tmpPath = $audioPath . '.emotion.wav';
        $success = false;

        if ($needsPitchShift) {
            // Apply pitch shift + other filters in one pass
            // rubberband pitch ratio: 2^(semitones/12)
            $pitchRatio = pow(2, $pitchSemitones / 12);
            $rubberbandFilter = sprintf('rubberband=pitch=%.4f', $pitchRatio);

            $allFilters = $rubberbandFilter . ',' . implode(',', $filters);
            $success = $this->runFfmpeg($audioPath, $tmpPath, $allFilters);
        } else {
            // No pitch shift needed
            $filterChain = implode(',', $filters);
            $success = $this->runFfmpeg($audioPath, $tmpPath, $filterChain);
        }

        if ($success && file_exists($tmpPath) && filesize($tmpPath) > 1000) {
            rename($tmpPath, $audioPath);

            Log::info('Emotion DSP applied', [
                'path' => basename($audioPath),
                'emotion' => $emotion,
                'intensity' => round($intensity, 2),
                'delivery' => $delivery,
                'pitch_semitones' => round($pitchSemitones, 1),
                'tempo' => $recipe['tempo'] ?? 1.0,
            ]);

            return true;
        }

        @unlink($tmpPath);
        Log::warning('Emotion DSP failed, keeping original', [
            'path' => basename($audioPath),
            'emotion' => $emotion,
        ]);

        return false;
    }

    /**
     * Scale recipe values by emotion intensity (0.0–1.0).
     */
    protected function scaleByIntensity(array $recipe, float $intensity): array
    {
        // Clamp intensity
        $intensity = max(0.1, min(1.0, $intensity));

        // Scale pitch shift
        if (isset($recipe['pitch_semitones'])) {
            $recipe['pitch_semitones'] *= $intensity;
        }

        // Scale tempo deviation from 1.0
        if (isset($recipe['tempo'])) {
            $deviation = $recipe['tempo'] - 1.0;
            $recipe['tempo'] = 1.0 + ($deviation * $intensity);
        }

        // Scale volume
        if (isset($recipe['volume_db'])) {
            $recipe['volume_db'] *= $intensity;
        }

        // Scale EQ gains
        if (!empty($recipe['eq'])) {
            foreach ($recipe['eq'] as &$eq) {
                $eq[2] *= $intensity; // gain
            }
        }

        // Scale tremolo depth
        if ($recipe['tremolo'] ?? null) {
            $recipe['tremolo'][1] *= $intensity;
        }

        // Scale vibrato depth
        if ($recipe['vibrato'] ?? null) {
            $recipe['vibrato'][1] *= $intensity;
        }

        return $recipe;
    }

    /**
     * Merge delivery-style modifiers on top of the emotion recipe.
     */
    protected function applyDeliveryModifiers(array $recipe, string $delivery): array
    {
        $mods = $this->deliveryModifiers[$delivery] ?? null;
        if (!$mods) {
            return $recipe;
        }

        // Additive pitch shift
        if (isset($mods['pitch_semitones'])) {
            $recipe['pitch_semitones'] = ($recipe['pitch_semitones'] ?? 0) + $mods['pitch_semitones'];
        }

        // Multiplicative tempo
        if (isset($mods['tempo_mult'])) {
            $recipe['tempo'] = ($recipe['tempo'] ?? 1.0) * $mods['tempo_mult'];
        }

        // Additive volume
        if (isset($mods['volume_db'])) {
            $recipe['volume_db'] = ($recipe['volume_db'] ?? 0) + $mods['volume_db'];
        }

        // Override tremolo/vibrato if delivery specifies
        if (isset($mods['tremolo'])) {
            $recipe['tremolo'] = $mods['tremolo'];
        }
        if (isset($mods['vibrato'])) {
            $recipe['vibrato'] = $mods['vibrato'];
        }

        // Override compression
        if (isset($mods['compress'])) {
            $recipe['compress'] = $mods['compress'];
        }

        // Merge EQ (additive)
        if (!empty($mods['eq'])) {
            $recipe['eq'] = array_merge($recipe['eq'] ?? [], $mods['eq']);
        }

        // Highpass from delivery
        if (isset($mods['highpass'])) {
            $recipe['highpass'] = $mods['highpass'];
        }

        return $recipe;
    }

    /**
     * Build FFmpeg filter chain from recipe (excluding rubberband pitch shift).
     */
    protected function buildFilterChain(array $recipe): array
    {
        $filters = [];

        // Tempo adjustment
        $tempo = $recipe['tempo'] ?? 1.0;
        if (abs($tempo - 1.0) > 0.01) {
            // Clamp to safe range
            $tempo = max(0.75, min(1.5, $tempo));
            $filters[] = sprintf('atempo=%.4f', $tempo);
        }

        // Highpass (whisper/breathy)
        if (($recipe['highpass'] ?? 0) > 0) {
            $filters[] = sprintf('highpass=f=%d', $recipe['highpass']);
        }

        // EQ bands
        foreach ($recipe['eq'] ?? [] as $eq) {
            if (count($eq) >= 3 && abs($eq[2]) > 0.1) {
                $filters[] = sprintf(
                    'equalizer=f=%d:t=q:w=%.1f:g=%.1f',
                    $eq[0], $eq[1], $eq[2]
                );
            }
        }

        // Lowpass (sad/tender)
        if (($recipe['lowpass'] ?? 0) > 0) {
            $filters[] = sprintf('lowpass=f=%d', $recipe['lowpass']);
        }

        // Compression (angry/shout)
        if ($recipe['compress'] ?? null) {
            $filters[] = sprintf(
                'acompressor=threshold=%ddB:ratio=%d:attack=5:release=50',
                $recipe['compress'][0],
                $recipe['compress'][1]
            );
        }

        // Tremolo — amplitude modulation (fear/trembling)
        if ($recipe['tremolo'] ?? null) {
            $freq = $recipe['tremolo'][0];
            $depth = max(0.01, min(0.5, $recipe['tremolo'][1]));
            $filters[] = sprintf('tremolo=f=%.1f:d=%.3f', $freq, $depth);
        }

        // Vibrato — pitch modulation (warmth/fear)
        if ($recipe['vibrato'] ?? null) {
            $freq = $recipe['vibrato'][0];
            $depth = max(0.001, min(0.05, $recipe['vibrato'][1]));
            $filters[] = sprintf('vibrato=f=%.1f:d=%.4f', $freq, $depth);
        }

        // Volume adjustment (last in chain)
        $volumeDb = $recipe['volume_db'] ?? 0.0;
        if (abs($volumeDb) > 0.1) {
            $sign = $volumeDb >= 0 ? '+' : '';
            $filters[] = sprintf('volume=%s%.1fdB', $sign, $volumeDb);
        }

        return $filters;
    }

    /**
     * Run FFmpeg with the given filter chain.
     */
    protected function runFfmpeg(string $input, string $output, string $filterChain): bool
    {
        $result = Process::timeout(30)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $input,
            '-af', $filterChain,
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
            $output,
        ]);

        if (!$result->successful()) {
            Log::warning('Emotion DSP ffmpeg failed', [
                'error' => $result->errorOutput(),
                'filters' => $filterChain,
            ]);
            return false;
        }

        return true;
    }
}
