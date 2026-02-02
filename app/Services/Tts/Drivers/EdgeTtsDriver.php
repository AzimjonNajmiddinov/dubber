<?php

namespace App\Services\Tts\Drivers;

use App\Contracts\TtsDriverInterface;
use App\Models\Speaker;
use App\Models\VideoSegment;
use App\Services\TextNormalizer;
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
     * Each profile has pitch and rate offsets to create distinct voices.
     */
    protected array $voiceProfiles = [
        // Profile 0: Default/neutral voice
        ['pitch_offset' => 0, 'rate_offset' => 0, 'name' => 'default'],
        // Profile 1: Slightly deeper, slower (older/calmer character)
        ['pitch_offset' => -15, 'rate_offset' => -8, 'name' => 'deep'],
        // Profile 2: Higher, faster (younger/energetic character)
        ['pitch_offset' => 12, 'rate_offset' => 5, 'name' => 'bright'],
        // Profile 3: Very deep, authoritative
        ['pitch_offset' => -25, 'rate_offset' => -5, 'name' => 'bass'],
        // Profile 4: Nasal/thin quality
        ['pitch_offset' => 20, 'rate_offset' => 3, 'name' => 'thin'],
        // Profile 5: Warm mid-range
        ['pitch_offset' => -8, 'rate_offset' => -3, 'name' => 'warm'],
    ];

    /**
     * Emotion to prosody mapping for more expressive speech.
     */
    protected array $emotionProsody = [
        'happy' => ['rate' => '+15%', 'pitch' => '+10Hz', 'volume' => '+5%'],
        'excited' => ['rate' => '+20%', 'pitch' => '+15Hz', 'volume' => '+10%'],
        'sad' => ['rate' => '-15%', 'pitch' => '-10Hz', 'volume' => '-5%'],
        'angry' => ['rate' => '+10%', 'pitch' => '+5Hz', 'volume' => '+15%'],
        'fear' => ['rate' => '+25%', 'pitch' => '+20Hz', 'volume' => '-5%'],
        'surprise' => ['rate' => '+5%', 'pitch' => '+25Hz', 'volume' => '+5%'],
        'neutral' => ['rate' => '+0%', 'pitch' => '+0Hz', 'volume' => '+0%'],
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
        $speedMultiplier = (float) ($options['speed'] ?? 1.0);

        // Select voice based on speaker gender
        $voice = $this->selectVoice($speaker, $language);

        // Get voice profile for this speaker (creates distinct voices for same-gender speakers)
        $profile = $this->getVoiceProfile($speaker);

        // Get emotion-based prosody
        $emotionProsody = $this->emotionProsody[$emotion] ?? $this->emotionProsody['neutral'];

        // Calculate final rate: combine profile + emotion + speed multiplier
        $profileRate = $profile['rate_offset'];
        $emotionRate = $this->parsePercentage($emotionProsody['rate']);
        $speedRate = ($speedMultiplier - 1.0) * 100; // Convert 1.5 to +50%
        $finalRate = (int) round($profileRate + $emotionRate + $speedRate);

        // Cap rate to reasonable limits (-50% to +100%)
        $finalRate = max(-50, min(100, $finalRate));
        $rate = $finalRate >= 0 ? "+{$finalRate}%" : "{$finalRate}%";

        // Calculate final pitch: combine profile + emotion
        $profilePitch = $profile['pitch_offset'];
        $emotionPitch = $this->parseHz($emotionProsody['pitch']);
        $finalPitch = $profilePitch + $emotionPitch;
        $pitch = $finalPitch >= 0 ? "+{$finalPitch}Hz" : "{$finalPitch}Hz";

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

        // Write text to temp file to avoid shell escaping issues
        $tmpTxt = "/tmp/tts_{$videoId}_{$segmentId}_" . Str::random(8) . ".txt";
        file_put_contents($tmpTxt, $text);

        $cmd = sprintf(
            'edge-tts -f %s --voice %s --rate=%s --pitch=%s --write-media %s 2>&1',
            escapeshellarg($tmpTxt),
            escapeshellarg($voice),
            escapeshellarg($rate),
            escapeshellarg($pitch),
            escapeshellarg($rawMp3)
        );

        Log::info('Edge TTS command', [
            'segment_id' => $segmentId,
            'speaker_id' => $speaker->id,
            'voice' => $voice,
            'profile' => $profile['name'],
            'emotion' => $emotion,
            'rate' => $rate,
            'pitch' => $pitch,
            'speed_multiplier' => $speedMultiplier,
        ]);

        exec($cmd, $output, $code);
        @unlink($tmpTxt);

        if ($code !== 0 || !file_exists($rawMp3) || filesize($rawMp3) < 500) {
            Log::error('Edge TTS synthesis failed', [
                'segment_id' => $segmentId,
                'exit_code' => $code,
                'output' => implode("\n", array_slice($output, -20)),
            ]);
            throw new RuntimeException("Edge TTS synthesis failed for segment {$segmentId}");
        }

        // Convert to normalized WAV and fit to time slot if needed
        $slotDuration = (float) $segment->end_time - (float) $segment->start_time;
        $this->normalizeAudio($rawMp3, $outputWav, $options, $slotDuration);
        @unlink($rawMp3);

        return $outputWav;
    }

    /**
     * Select appropriate voice based on speaker gender and language.
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
            // Try to infer from speaker label
            $label = strtolower($speaker->label ?? '');
            $gender = preg_match('/female|woman|girl|lady/i', $label) ? 'female' : 'male';
        }

        // Get voices for language and gender
        $langVoices = $this->voiceMap[$language] ?? $this->voiceMap['uz'];
        $genderVoices = $langVoices[$gender] ?? $langVoices['male'];

        // Use speaker ID to consistently pick a voice for this speaker
        $voiceIndex = $speaker->id % count($genderVoices);

        return $genderVoices[$voiceIndex];
    }

    /**
     * Get voice profile for a speaker to create distinct voices.
     * Different speakers get different pitch/rate offsets even with same base voice.
     */
    protected function getVoiceProfile(Speaker $speaker): array
    {
        // Use speaker ID to consistently assign a profile
        $profileIndex = $speaker->id % count($this->voiceProfiles);
        return $this->voiceProfiles[$profileIndex];
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
        $lufsTarget = $options['lufs_target'] ?? -16.0;

        // First, get the duration of the input audio
        $durationCmd = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellarg($input)
        );
        $audioDuration = (float) trim(shell_exec($durationCmd) ?? '0');

        // Calculate tempo adjustment if audio is longer than slot
        $tempoFilter = '';
        if ($slotDuration > 0 && $audioDuration > 0 && $audioDuration > $slotDuration) {
            // Need to speed up: tempo = audio_duration / slot_duration
            $tempo = $audioDuration / $slotDuration;

            // Cap tempo at 2.0 (ffmpeg atempo limit per filter, can chain for higher)
            if ($tempo > 2.0) {
                // Chain multiple atempo filters for > 2x speedup
                $tempo1 = 2.0;
                $tempo2 = min(2.0, $tempo / 2.0);
                $tempoFilter = "atempo={$tempo1},atempo={$tempo2},";
                Log::info('Audio tempo adjustment (chained)', [
                    'audio_duration' => $audioDuration,
                    'slot_duration' => $slotDuration,
                    'tempo1' => $tempo1,
                    'tempo2' => $tempo2,
                ]);
            } else {
                $tempoFilter = "atempo={$tempo},";
                Log::info('Audio tempo adjustment', [
                    'audio_duration' => $audioDuration,
                    'slot_duration' => $slotDuration,
                    'tempo' => $tempo,
                ]);
            }
        }

        $filter = sprintf(
            'aresample=48000,' .
            'aformat=sample_fmts=fltp:channel_layouts=stereo,' .
            '%s' . // tempo filter if needed
            'highpass=f=80,' .
            'lowpass=f=10000,' .
            'volume=%sdB,' .
            'loudnorm=I=%s:TP=-1.5:LRA=11,' .
            'aresample=48000',
            $tempoFilter,
            $gainDb >= 0 ? "+{$gainDb}" : $gainDb,
            $lufsTarget
        );

        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -vn -af %s -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($input),
            escapeshellarg($filter),
            escapeshellarg($output)
        );

        exec($cmd, $out, $code);

        if ($code !== 0 || !file_exists($output) || filesize($output) < 5000) {
            Log::error('Audio normalization failed', [
                'exit_code' => $code,
                'output' => implode("\n", $out),
            ]);
            throw new RuntimeException("Audio normalization failed");
        }
    }
}
