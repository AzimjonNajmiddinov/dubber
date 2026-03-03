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
    protected array $voiceMap = [
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
        return false;
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

        // Select voice based on speaker gender (SardorNeural for male, MadinaNeural for female)
        $voice = $this->selectVoice($speaker, $language);

        $videoId = $segment->video_id;
        $segmentId = $segment->id;

        $outDir = Storage::disk('local')->path("audio/tts/{$videoId}");
        @mkdir($outDir, 0777, true);

        $rawMp3 = "{$outDir}/seg_{$segmentId}.raw.mp3";
        $outputWav = "{$outDir}/seg_{$segmentId}.wav";

        @unlink($rawMp3);
        @unlink($outputWav);

        // Normalize text (numbers to words, punctuation cleanup)
        $text = TextNormalizer::normalize($text, $language);

        // Uzbek pronunciation fix: Edge TTS needs U+02BB for correct oʻ/gʻ
        if ($language === 'uz' || str_contains($language, 'uzbek')) {
            $text = $this->fixUzbekPronunciation($text);
        }

        // Plain text → edge-tts → MP3. No SSML, no prosody, no pitch/rate changes.
        $tmpFile = "/tmp/tts_{$videoId}_{$segmentId}_" . Str::random(8) . '.txt';
        file_put_contents($tmpFile, $text);

        $cmd = sprintf(
            'edge-tts -f %s --voice %s --write-media %s 2>&1',
            escapeshellarg($tmpFile),
            escapeshellarg($voice),
            escapeshellarg($rawMp3)
        );

        Log::info('Edge TTS synthesis', [
            'segment_id' => $segmentId,
            'voice' => $voice,
            'text_length' => mb_strlen($text),
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

        // Convert MP3 to 48kHz stereo WAV (pipeline standard)
        $this->normalizeAudio($rawMp3, $outputWav, $options);
        @unlink($rawMp3);

        return $outputWav;
    }

    protected function selectVoice(Speaker $speaker, string $language): string
    {
        if (!empty($speaker->tts_voice)) {
            return $speaker->tts_voice;
        }

        $gender = strtolower($speaker->gender ?? 'male');
        if (!in_array($gender, ['male', 'female'])) {
            $gender = 'male';
        }

        $langVoices = $this->voiceMap[$language] ?? $this->voiceMap['uz'];
        $genderVoices = $langVoices[$gender] ?? $langVoices['male'];

        return $genderVoices[0];
    }

    public function cloneVoice(string $audioPath, string $name, array $options = []): string
    {
        throw new RuntimeException('Edge TTS does not support voice cloning');
    }

    public function getCostPerCharacter(): float
    {
        return 0.0; // Free
    }

    protected function normalizeAudio(string $input, string $output, array $options): void
    {
        // Simple MP3 → 48kHz stereo WAV conversion (pipeline standard format)
        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -vn -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($input),
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

    /**
     * Fix Uzbek pronunciation for Edge TTS.
     *
     * Edge TTS's Uzbek voice ignores U+02BB and all Latin apostrophe variants —
     * "oʻzbekiston" sounds identical to "ozbekiston". But Cyrillic Uzbek
     * characters ARE pronounced correctly:
     * - oʻ → ў (U+045E, Cyrillic Short U) — correct Uzbek /ɵ/ sound
     * - gʻ → ғ (U+0493, Cyrillic Gha)    — correct Uzbek /ʁ/ sound
     *
     * Tested: plain "ozbekiston" = 14400 bytes, "ўzbekiston" = 13968 bytes (different).
     * Tested: plain "galaba" = 9216 bytes, "ғalaba" = 8928 bytes (different).
     */
    protected function fixUzbekPronunciation(string $text): string
    {
        return self::fixUzbekOGPronunciation($text);
    }

    /**
     * Replace Uzbek oʻ/gʻ with Cyrillic ў/ғ for correct Edge TTS pronunciation.
     * Static so it can be called from ProcessInstantDubSegmentJob too.
     */
    public static function fixUzbekOGPronunciation(string $text): string
    {
        // All apostrophe variants found in Uzbek text (GPT output, web text, etc.)
        $apostrophes = [
            "'",               // U+0027 ASCII apostrophe
            "\xE2\x80\x99",   // U+2019 Right single quotation mark (')
            "\xCA\xBC",       // U+02BC Modifier letter apostrophe (ʼ)
            "\xCA\xBB",       // U+02BB Modifier letter turned comma (ʻ)
            "`",               // U+0060 Grave accent (backtick)
        ];

        // Replace o'/O' → ў/Ў (Cyrillic) and g'/G' → ғ/Ғ (Cyrillic)
        foreach ($apostrophes as $a) {
            $text = str_replace(
                ["o{$a}", "O{$a}", "g{$a}", "G{$a}"],
                ["\xD1\x9E", "\xD0\x8E", "\xD2\x93", "\xD2\x92"],
                $text
            );
        }

        return $text;
    }
}
