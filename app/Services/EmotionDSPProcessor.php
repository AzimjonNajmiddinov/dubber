<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * EmotionDSPProcessor — Adds emotional expressiveness to flat TTS audio.
 *
 * Uses FFmpeg audio filters (tempo, EQ, tremolo, vibrato, compression)
 * to transform neutral speech into emotionally expressive speech.
 *
 * Designed to run BETWEEN Edge TTS and OpenVoice in the hybrid pipeline:
 *   1. Edge TTS → correct Uzbek pronunciation (neutral)
 *   2. EmotionDSP → tempo, EQ, dynamics per emotion
 *   3. OpenVoice → voice identity swap (preserves emotion from step 2)
 *
 * CRITICAL DESIGN RULE — PITCH = IDENTITY, NOT EMOTION:
 *   A speaker's pitch is what makes them recognizable across segments.
 *   Shifting pitch for emotions makes the SAME speaker sound like
 *   DIFFERENT people (angry = high voice, sad = low voice).
 *   Real humans express emotions through volume, pace, voice quality,
 *   and timbre — NOT by changing their fundamental frequency.
 *   Pitch is set once per speaker in EdgeTtsDriver (voice profile)
 *   and stays constant regardless of emotional state.
 *
 * Emotions are expressed via:
 * - Tempo (faster = excited/angry, slower = sad/thoughtful)
 * - Volume (louder = confident/angry, softer = sad/intimate)
 * - EQ/timbre (brighter = happy, warmer = sad, harsh = angry)
 * - Compression (tighter = angry/tense, open = calm)
 * - Tremolo/vibrato (trembling = fear, warmth = tender)
 */
class EmotionDSPProcessor
{
    /**
     * Emotion DSP recipes.
     *
     * NO PITCH SHIFTING — pitch defines speaker identity, not emotion.
     * Emotions are expressed via tempo, volume, EQ, compression, tremolo.
     *
     * tempo: speed multiplier (>1 = faster)
     * volume_db: volume adjustment
     * eq: array of EQ filters [frequency, Q-width, gain_db]
     * tremolo: [frequency_hz, depth] for amplitude modulation
     * vibrato: [frequency_hz, depth] for micro pitch modulation (warmth, not identity change)
     * compress: [threshold_db, ratio] for dynamic compression
     * lowpass: cutoff frequency (0 = disabled)
     */
    protected array $emotionRecipes = [
        'happy' => [
            'pitch_semitones' => 0.0,
            'tempo' => 1.08,
            'volume_db' => 2.0,
            'eq' => [[3000, 2.0, 4.0], [5000, 1.5, 2.0]], // brighter, more air
            'tremolo' => null,
            'vibrato' => null,
            'compress' => [-20, 2], // slight compression for energy
            'lowpass' => 0,
        ],
        'excited' => [
            'pitch_semitones' => 0.0,
            'tempo' => 1.12,
            'volume_db' => 3.5,
            'eq' => [[3000, 2.0, 5.0], [800, 1.0, 2.0], [6000, 1.5, 2.0]], // very bright, full
            'tremolo' => null,
            'vibrato' => null,
            'compress' => [-18, 3], // energetic compression
            'lowpass' => 0,
        ],
        'sad' => [
            'pitch_semitones' => 0.0,
            'tempo' => 0.88,
            'volume_db' => -4.0,
            'eq' => [[300, 1.0, 3.0], [3000, 1.5, -2.0]], // warm lows, reduced brightness
            'tremolo' => null,
            'vibrato' => [3.0, 0.003], // subtle warmth wobble
            'compress' => null,
            'lowpass' => 9000, // muffled, soft highs
        ],
        'angry' => [
            'pitch_semitones' => 0.0,
            'tempo' => 1.10,
            'volume_db' => 5.0,
            'eq' => [[2500, 1.5, 6.0], [800, 1.0, 3.0], [5000, 1.0, 2.0]], // harsh, aggressive presence
            'tremolo' => null,
            'vibrato' => null,
            'compress' => [-12, 5], // heavy compression — intense, pushed
            'lowpass' => 0,
        ],
        'fear' => [
            'pitch_semitones' => 0.0,
            'tempo' => 1.12,
            'volume_db' => -2.0,
            'eq' => [[4000, 2.0, 3.0], [2000, 1.0, 1.5]], // edge, tension
            'tremolo' => [7.0, 0.12], // trembling amplitude
            'vibrato' => [8.0, 0.012], // pitch wobble (micro, not identity-changing)
            'compress' => [-18, 3], // tight, constricted
            'lowpass' => 0,
        ],
        'surprise' => [
            'pitch_semitones' => 0.0,
            'tempo' => 1.06,
            'volume_db' => 4.0,
            'eq' => [[3500, 2.0, 4.0], [6000, 1.5, 2.5]], // very bright, open
            'tremolo' => null,
            'vibrato' => null,
            'compress' => [-20, 2], // slight punch
            'lowpass' => 0,
        ],
        'tender' => [
            'pitch_semitones' => 0.0,
            'tempo' => 0.92,
            'volume_db' => -5.0,
            'eq' => [[200, 1.0, 3.0], [3000, 1.5, -2.0]], // warm, intimate
            'tremolo' => null,
            'vibrato' => [4.0, 0.004], // gentle warmth wobble
            'compress' => null,
            'lowpass' => 10000, // gentle rolloff
        ],
        'contempt' => [
            'pitch_semitones' => 0.0,
            'tempo' => 0.95,
            'volume_db' => 0.0,
            'eq' => [[1000, 2.0, 3.0], [3000, 1.5, -1.0]], // nasal, slightly muted
            'tremolo' => null,
            'vibrato' => null,
            'compress' => [-22, 2], // controlled, flat
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
            'tempo_mult' => 1.05,
            'volume_db' => -2.0,
            'vibrato' => [5.0, 0.006], // desperation wobble
            'compress' => [-18, 3], // tight, urgent
        ],
    ];

    /**
     * Audio source filters — simulate how voice sounds through different media.
     *
     * Applied AFTER emotion/delivery DSP. These simulate the acoustic
     * characteristics of hearing someone through a phone, TV, etc.
     */
    protected array $audioSourceFilters = [
        'phone' => [
            // Telephone bandpass: 300-3400Hz (ITU-T G.712 standard)
            'highpass' => 300,
            'lowpass' => 3400,
            'eq' => [[1000, 1.0, 3.0], [2500, 1.5, 2.0]], // nasal telephone presence
            'compress' => [-20, 4], // phone compresses dynamics heavily
            'volume_db' => -3.0, // slightly quieter (other end of call)
        ],
        'tv' => [
            // TV/radio: narrower than direct but wider than phone
            'highpass' => 120,
            'lowpass' => 8000,
            'eq' => [[2000, 1.0, 2.0]], // slight mid presence (speaker coloration)
            'compress' => [-22, 2], // mild broadcast compression
            'volume_db' => -2.0,
        ],
        'voiceover' => [
            // Clean narration: slight intimacy (proximity effect)
            'eq' => [[200, 1.0, 2.0], [4000, 1.5, 1.5]], // warm bass + clarity
            'compress' => [-24, 2], // gentle broadcast compression
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
        $audioSource = strtolower($actingDirection['audio_source'] ?? 'direct');

        // Skip processing for neutral emotion with normal delivery and direct source
        if ($emotion === 'neutral' && $delivery === 'normal' && $audioSource === 'direct') {
            return false;
        }

        // Get base recipe for emotion
        $recipe = $this->emotionRecipes[$emotion] ?? $this->emotionRecipes['neutral'];

        // Scale by intensity (0.0–1.0)
        $recipe = $this->scaleByIntensity($recipe, $intensity);

        // Apply delivery modifiers on top
        $recipe = $this->applyDeliveryModifiers($recipe, $delivery);

        // Apply audio source filter (phone, tv, voiceover)
        $recipe = $this->applyAudioSourceFilter($recipe, $audioSource);

        // Build ffmpeg filter chain
        $filters = $this->buildFilterChain($recipe);

        if (empty($filters)) {
            return false;
        }

        // No pitch shifting — pitch = speaker identity, stays constant
        // All emotion expression through tempo, volume, EQ, compression, tremolo
        $tmpPath = $audioPath . '.emotion.wav';
        $filterChain = implode(',', $filters);
        $success = $this->runFfmpeg($audioPath, $tmpPath, $filterChain);

        if ($success && file_exists($tmpPath) && filesize($tmpPath) > 1000) {
            rename($tmpPath, $audioPath);

            Log::info('Emotion DSP applied', [
                'path' => basename($audioPath),
                'emotion' => $emotion,
                'intensity' => round($intensity, 2),
                'delivery' => $delivery,
                'audio_source' => $audioSource,
                'tempo' => $recipe['tempo'] ?? 1.0,
                'volume_db' => round($recipe['volume_db'] ?? 0, 1),
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
     * Apply audio source filter (phone, tv, voiceover) on top of emotion+delivery.
     */
    protected function applyAudioSourceFilter(array $recipe, string $audioSource): array
    {
        $filter = $this->audioSourceFilters[$audioSource] ?? null;
        if (!$filter) {
            return $recipe;
        }

        // Highpass — take the higher of emotion vs source
        if (isset($filter['highpass'])) {
            $recipe['highpass'] = max($recipe['highpass'] ?? 0, $filter['highpass']);
        }

        // Lowpass — take the lower of emotion vs source (more restrictive)
        if (isset($filter['lowpass'])) {
            $existing = $recipe['lowpass'] ?? 0;
            $recipe['lowpass'] = $existing > 0
                ? min($existing, $filter['lowpass'])
                : $filter['lowpass'];
        }

        // Additive volume
        if (isset($filter['volume_db'])) {
            $recipe['volume_db'] = ($recipe['volume_db'] ?? 0) + $filter['volume_db'];
        }

        // Override compression (source compression is more important than emotion)
        if (isset($filter['compress'])) {
            $recipe['compress'] = $filter['compress'];
        }

        // Merge EQ (additive)
        if (!empty($filter['eq'])) {
            $recipe['eq'] = array_merge($recipe['eq'] ?? [], $filter['eq']);
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
