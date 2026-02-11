<?php

namespace App\Services\Tts\Drivers;

use App\Contracts\TtsDriverInterface;
use App\Models\Speaker;
use App\Models\VideoSegment;
use App\Services\TextNormalizer;
use App\Services\Tts\SsmlBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class EdgeTtsDriver implements TtsDriverInterface
{
    /**
     * Available voices per language with gender info.
     */
    protected array $voiceMap = [
        'uz' => [
            'male' => ['uz-UZ-SardorNeural'],
            'female' => ['uz-UZ-MadinaNeural'],
        ],
        'ru' => [
            'male' => ['ru-RU-DmitryNeural'],
            'female' => ['ru-RU-SvetlanaNeural', 'ru-RU-DariyaNeural'],
        ],
        'en' => [
            'male' => ['en-US-GuyNeural', 'en-US-ChristopherNeural', 'en-GB-RyanNeural'],
            'female' => ['en-US-JennyNeural', 'en-US-AriaNeural', 'en-GB-SoniaNeural'],
        ],
        'tr' => [
            'male' => ['tr-TR-AhmetNeural'],
            'female' => ['tr-TR-EmelNeural'],
        ],
    ];

    /**
     * Voice profiles for same-gender speaker differentiation.
     * Pitch only - rate is calculated from slot duration.
     */
    protected array $voiceProfiles = [
        ['pitch_offset' => 0, 'name' => 'default'],
        ['pitch_offset' => -12, 'name' => 'deep'],
        ['pitch_offset' => 10, 'name' => 'bright'],
        ['pitch_offset' => -20, 'name' => 'bass'],
        ['pitch_offset' => 15, 'name' => 'thin'],
        ['pitch_offset' => -6, 'name' => 'warm'],
    ];

    /**
     * Emotion to prosody mapping for expressive speech.
     *
     * CRITICAL FOR VOICE CONSISTENCY:
     * - Pitch defines WHO is speaking (speaker identity) - DO NOT change for emotions!
     * - Rate and Volume define HOW they feel (emotion) - these CAN change
     *
     * Emotions are expressed through:
     * 1. Speaking rate (faster = excited/angry, slower = sad/thoughtful)
     * 2. Volume (louder = confident/angry, softer = sad/intimate)
     * 3. Pauses/breaks (handled in SsmlBuilder)
     * 4. Emphasis patterns (handled in SsmlBuilder)
     *
     * NO PITCH CHANGES - same speaker must sound like same person regardless of emotion!
     */
    protected array $emotionProsody = [
        'happy'    => ['rate' => '+5%',  'pitch' => '+0Hz', 'volume' => '+5%'],
        'excited'  => ['rate' => '+8%',  'pitch' => '+0Hz', 'volume' => '+8%'],
        'sad'      => ['rate' => '-8%',  'pitch' => '+0Hz', 'volume' => '-5%'],
        'angry'    => ['rate' => '+3%',  'pitch' => '+0Hz', 'volume' => '+10%'],
        'fear'     => ['rate' => '+5%',  'pitch' => '+0Hz', 'volume' => '-3%'],
        'surprise' => ['rate' => '+5%',  'pitch' => '+0Hz', 'volume' => '+5%'],
        'neutral'  => ['rate' => '+0%',  'pitch' => '+0Hz', 'volume' => '+0%'],
    ];

    /**
     * Direction to prosody mapping for acting delivery styles.
     *
     * Direction = physical delivery style, NOT emotional state
     * - whisper/soft: physical volume reduction
     * - loud/shout: physical volume increase
     * - sarcastic/playful/cold/warm: subtle rate changes only
     *
     * Again, NO significant pitch changes to maintain speaker identity!
     */
    protected array $directionProsody = [
        'whisper'  => ['rate' => '-10%', 'pitch' => '+0Hz', 'volume' => '-35%'],
        'soft'     => ['rate' => '-5%',  'pitch' => '+0Hz', 'volume' => '-15%'],
        'normal'   => ['rate' => '+0%',  'pitch' => '+0Hz', 'volume' => '+0%'],
        'loud'     => ['rate' => '+3%',  'pitch' => '+0Hz', 'volume' => '+15%'],
        'shout'    => ['rate' => '+5%',  'pitch' => '+0Hz', 'volume' => '+25%'],
        'sarcastic'=> ['rate' => '-3%',  'pitch' => '+0Hz', 'volume' => '+0%'],
        'playful'  => ['rate' => '+5%',  'pitch' => '+0Hz', 'volume' => '+3%'],
        'cold'     => ['rate' => '-5%',  'pitch' => '+0Hz', 'volume' => '-3%'],
        'warm'     => ['rate' => '-3%',  'pitch' => '+0Hz', 'volume' => '+3%'],
    ];

    public function name(): string
    {
        return 'edge';
    }

    public function supportsVoiceCloning(): bool
    {
        return false;
    }

    public function supportsEmotions(): bool
    {
        return true; // We simulate emotions via prosody
    }

    public function getVoices(string $language): array
    {
        $voices = config('dubber.voices', []);

        return $voices[$language] ?? $voices['en'] ?? [];
    }

    public function synthesize(
        string $text,
        Speaker $speaker,
        VideoSegment $segment,
        array $options = []
    ): string {
        $language = $options['language'] ?? 'uz';
        $emotion = strtolower($options['emotion'] ?? 'neutral');
        $direction = strtolower($options['direction'] ?? $segment->direction ?? 'normal');
        $speedMultiplier = (float) ($options['speed'] ?? 1.0);

        // Select voice based on speaker gender
        $voice = $this->selectVoice($speaker, $language);

        // Get voice profile for this speaker (creates distinct voices for same-gender speakers)
        $profile = $this->getVoiceProfile($speaker);

        // Get emotion-based prosody
        $emotionProsody = $this->emotionProsody[$emotion] ?? $this->emotionProsody['neutral'];

        // Get direction-based prosody
        $directionProsody = $this->directionProsody[$direction] ?? $this->directionProsody['normal'];

        // Calculate slot-aware speaking rate
        // Uzbek Edge TTS ACTUAL speaking rate is ~10-11 chars/sec at normal speed
        // (not 12 as previously assumed - Uzbek words are phonetically longer)
        $slotDuration = ((float) $segment->end_time) - ((float) $segment->start_time);
        $textLength = mb_strlen($text);

        // Use conservative estimate: 10 chars/sec for Uzbek, 11 for others
        $normalCharsPerSec = ($language === 'uz' || $language === 'uzbek') ? 10.0 : 11.0;

        // IMPORTANT: Use NARROW rate range for CONSISTENT natural sound
        // Large rate variations sound robotic and unnatural
        // Better to let post-processing handle overflow with gentle atempo
        $requiredRate = 0; // Default: normal speed
        if ($slotDuration > 0.3 && $textLength > 0) {
            $requiredCharsPerSec = $textLength / $slotDuration;
            $speedRatio = $requiredCharsPerSec / $normalCharsPerSec;

            // Only adjust rate if significantly different from normal
            // Keep adjustments subtle for natural sound
            // If translation followed 9 chars/sec budget, most segments won't need speedup
            if ($speedRatio > 1.10) {
                // Need to speed up - but keep it gentle (max +15%)
                $requiredRate = (int) round(min(($speedRatio - 1.0) * 100, 15));
            } elseif ($speedRatio < 0.85) {
                // Can slow down slightly for very short text (max -10%)
                $requiredRate = (int) round(max(($speedRatio - 1.0) * 100, -10));
            }

            Log::debug('TTS rate calculation', [
                'segment_id' => $segment->id,
                'text_length' => $textLength,
                'slot' => round($slotDuration, 2),
                'expected_chars_per_sec' => $normalCharsPerSec,
                'actual_chars_per_sec' => round($requiredCharsPerSec, 1),
                'speed_ratio' => round($speedRatio, 2),
                'rate_percent' => $requiredRate,
            ]);
        }

        // Get emotion and direction rate modifiers (keep these small too)
        $emotionRate = (int) round($this->parsePercentage($emotionProsody['rate']) * 0.5);
        $directionRate = (int) round($this->parsePercentage($directionProsody['rate']) * 0.5);

        // Final rate: NARROW range for consistent, natural speech
        // -10% to +15% - very conservative to sound natural
        // With 9 chars/sec translation budget, we rarely need speedup
        // Post-processing atempo will handle any remaining adjustment (max 1.5x)
        $finalRate = min(15, max(-10, $requiredRate + $emotionRate + $directionRate));
        $rate = $finalRate >= 0 ? "+{$finalRate}%" : "{$finalRate}%";

        // PITCH = SPEAKER IDENTITY ONLY
        // Pitch comes ONLY from speaker profile - this is what makes each speaker unique
        // Emotions/directions do NOT change pitch - they use rate/volume instead
        // This ensures the same speaker sounds consistent across all emotional states
        $profilePitch = $profile['pitch_offset'];
        $pitch = $profilePitch >= 0 ? "+{$profilePitch}Hz" : "{$profilePitch}Hz";

        // Calculate combined volume adjustment from emotion + direction
        // Emotion volume: expresses feelings (louder when angry, softer when sad)
        // Direction volume: physical delivery (whisper, shout, etc.)
        $emotionVolume = $this->parsePercentage($emotionProsody['volume']);
        $directionVolume = $this->parsePercentage($directionProsody['volume']);
        $totalVolumePercent = $emotionVolume + $directionVolume;

        // Build volume string for SSML (clamped to reasonable range)
        $volumePercent = max(-50, min(50, $totalVolumePercent));
        $volume = $volumePercent >= 0 ? "+{$volumePercent}%" : "{$volumePercent}%";

        $videoId = $segment->video_id;
        $segmentId = $segment->id;

        $outDir = Storage::disk('local')->path("audio/tts/{$videoId}");
        @mkdir($outDir, 0777, true);

        $rawMp3 = "{$outDir}/seg_{$segmentId}.raw.mp3";
        $outputWav = "{$outDir}/seg_{$segmentId}.wav";

        @unlink($rawMp3);
        @unlink($outputWav);

        // Normalize text for TTS (converts numbers to words, normalizes apostrophes, etc.)
        $text = TextNormalizer::normalize($text, $language);

        $useSsml = config('dubber.tts.edge_ssml', true);
        $tmpFile = "/tmp/tts_{$videoId}_{$segmentId}_" . Str::random(8);

        if ($useSsml) {
            // SSML mode: per-sentence prosody control with breaks and intonation
            // Volume is included for emotional expression (pitch stays constant per speaker)
            $locale = SsmlBuilder::languageToLocale($language);
            $ssml = SsmlBuilder::fromTextWithEmotion(
                $text, $voice, $emotion,
                ['rate' => $rate, 'pitch' => $pitch, 'volume' => $volume],
                $locale
            );
            $tmpFile .= '.xml';
            file_put_contents($tmpFile, $ssml);

            // edge-tts auto-detects SSML from <speak> tag in input file
            $cmd = sprintf(
                'edge-tts -f %s --write-media %s 2>&1',
                escapeshellarg($tmpFile),
                escapeshellarg($rawMp3)
            );
        } else {
            // Flag-based mode: global rate/pitch via CLI arguments
            $tmpFile .= '.txt';
            file_put_contents($tmpFile, $text);

            $cmd = sprintf(
                'edge-tts -f %s --voice %s --rate=%s --pitch=%s --write-media %s 2>&1',
                escapeshellarg($tmpFile),
                escapeshellarg($voice),
                escapeshellarg($rate),
                escapeshellarg($pitch),
                escapeshellarg($rawMp3)
            );
        }

        Log::info('Edge TTS synthesis', [
            'segment_id' => $segmentId,
            'speaker_id' => $speaker->id,
            'voice' => $voice,
            'profile' => $profile['name'],
            'emotion' => $emotion,
            'direction' => $direction,
            'rate' => $rate,
            'pitch' => $pitch,
            'ssml' => $useSsml,
        ]);

        exec($cmd, $output, $code);
        @unlink($tmpFile);

        if ($code !== 0 || !file_exists($rawMp3) || filesize($rawMp3) < 500) {
            Log::error('Edge TTS synthesis failed', [
                'segment_id' => $segmentId,
                'exit_code' => $code,
                'output' => implode("\n", array_slice($output, -20)),
            ]);
            throw new RuntimeException("Edge TTS synthesis failed for segment {$segmentId}");
        }

        // Convert to normalized WAV
        // Note: volume is already applied via SSML prosody, so no additional volume adjustment needed
        $slotDuration = (float) $segment->end_time - (float) $segment->start_time;
        $normalizeOptions = $options;
        $normalizeOptions['direction_volume_percent'] = 0; // Volume already in SSML
        $this->normalizeAudio($rawMp3, $outputWav, $normalizeOptions, $slotDuration);
        @unlink($rawMp3);

        return $outputWav;
    }

    /**
     * Select appropriate voice based on speaker gender and language.
     * Avoids assigning the same voice to multiple same-gender speakers in a video.
     */
    protected function selectVoice(Speaker $speaker, string $language): string
    {
        // Use speaker's configured voice if set
        if (!empty($speaker->tts_voice)) {
            return $speaker->tts_voice;
        }

        // Determine gender (default to male if unknown)
        $gender = strtolower($speaker->gender ?? 'male');
        if (!in_array($gender, ['male', 'female'])) {
            $label = strtolower($speaker->label ?? '');
            $gender = preg_match('/female|woman|girl|lady/i', $label) ? 'female' : 'male';
        }

        $langVoices = $this->voiceMap[$language] ?? $this->voiceMap['uz'];
        $genderVoices = $langVoices[$gender] ?? $langVoices['male'];

        // Avoid duplicate voices: check what same-gender speakers already use
        if ($speaker->video_id) {
            $usedVoices = Speaker::where('video_id', $speaker->video_id)
                ->where('gender', $gender)
                ->where('id', '!=', $speaker->id)
                ->whereNotNull('tts_voice')
                ->pluck('tts_voice')
                ->toArray();

            $available = array_diff($genderVoices, $usedVoices);
            if (!empty($available)) {
                return array_values($available)[0];
            }
        }

        // All voices used or no video context, fall back to ID-based
        return $genderVoices[$speaker->id % count($genderVoices)];
    }

    /**
     * Get voice profile for a speaker based on detected characteristics.
     * Uses pitch_median_hz and age_group from WhisperX when available,
     * falls back to ID-based assignment.
     */
    protected function getVoiceProfile(Speaker $speaker): array
    {
        // Priority 1: Map from detected pitch (most accurate)
        if ($speaker->pitch_median_hz) {
            return $this->profileFromDetectedPitch($speaker);
        }

        // Priority 2: Map from age group
        if ($speaker->age_group && $speaker->age_group !== 'unknown') {
            return $this->profileFromAgeGroup($speaker->age_group);
        }

        // Fallback: ID-based assignment
        $profileIndex = $speaker->id % count($this->voiceProfiles);
        return $this->voiceProfiles[$profileIndex];
    }

    /**
     * Select voice profile based on detected fundamental frequency.
     */
    protected function profileFromDetectedPitch(Speaker $speaker): array
    {
        $pitch = $speaker->pitch_median_hz;
        $gender = strtolower($speaker->gender ?? 'male');

        if ($gender === 'male') {
            // Male pitch ranges: ~85-180 Hz
            if ($pitch < 100) return $this->voiceProfiles[3]; // bass
            if ($pitch < 120) return $this->voiceProfiles[1]; // deep
            if ($pitch < 150) return $this->voiceProfiles[5]; // warm
            return $this->voiceProfiles[2]; // bright
        }

        // Female pitch ranges: ~165-300 Hz
        if ($pitch < 190) return $this->voiceProfiles[5]; // warm
        if ($pitch < 220) return $this->voiceProfiles[0]; // default
        if ($pitch < 260) return $this->voiceProfiles[2]; // bright
        return $this->voiceProfiles[4]; // thin
    }

    /**
     * Select voice profile based on detected age group.
     */
    protected function profileFromAgeGroup(string $ageGroup): array
    {
        return match ($ageGroup) {
            'child' => $this->voiceProfiles[4],      // thin - higher pitch
            'young_adult' => $this->voiceProfiles[2], // bright
            'adult' => $this->voiceProfiles[0],       // default
            'senior' => $this->voiceProfiles[1],      // deep - slower
            default => $this->voiceProfiles[0],
        };
    }

    /**
     * Parse percentage string like "+15%" to integer 15.
     */
    protected function parsePercentage(string $str): int
    {
        return (int) preg_replace('/[^-0-9]/', '', $str);
    }

    /**
     * Parse Hz string like "+10Hz" to integer 10.
     */
    protected function parseHz(string $str): int
    {
        return (int) preg_replace('/[^-0-9]/', '', $str);
    }

    public function cloneVoice(string $audioPath, string $name, array $options = []): string
    {
        throw new RuntimeException('Edge TTS does not support voice cloning');
    }

    public function getCostPerCharacter(): float
    {
        return 0.0; // Free
    }

    protected function normalizeAudio(string $input, string $output, array $options, float $slotDuration = 0): void
    {
        $gainDb = $options['gain_db'] ?? 0.0;
        $directionVolumePercent = $options['direction_volume_percent'] ?? 0;

        // Convert direction volume percent to dB adjustment
        // +30% ≈ +2.3dB, -30% ≈ -3dB
        $directionVolumeDb = 0.0;
        if ($directionVolumePercent != 0) {
            $directionVolumeDb = 20 * log10(1 + ($directionVolumePercent / 100));
        }

        // Simple conversion: MP3 to WAV, 48kHz stereo
        // No tempo adjustment - Edge TTS --rate parameter handles speed
        // No loudnorm - final mix handles loudness normalization once
        $totalGainDb = $gainDb + $directionVolumeDb;
        $volumeFilter = '';
        if (abs($totalGainDb) > 0.1) {
            $sign = $totalGainDb >= 0 ? '+' : '';
            $volumeFilter = ",volume={$sign}" . round($totalGainDb, 1) . "dB";
        }

        $filter = "aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo{$volumeFilter}";

        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -vn -af %s -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($input),
            escapeshellarg($filter),
            escapeshellarg($output)
        );

        exec($cmd, $out, $code);

        if ($code !== 0 || !file_exists($output) || filesize($output) < 1000) {
            Log::error('Audio normalization failed', [
                'exit_code' => $code,
                'output' => implode("\n", $out),
            ]);
            throw new RuntimeException("Audio normalization failed");
        }
    }
}
