<?php

namespace App\Services\Tts;

/**
 * Builds SSML markup for Edge-TTS with per-sentence prosody control.
 * Edge-TTS auto-detects SSML when input contains <speak> tags.
 */
class SsmlBuilder
{
    private string $voice;
    private string $locale;
    private array $elements = [];

    public function __construct(string $voice, string $locale = 'uz-UZ')
    {
        $this->voice = $voice;
        $this->locale = $locale;
    }

    /**
     * Add text with prosody attributes (rate, pitch, volume).
     */
    public function addProsody(string $text, array $prosody = []): self
    {
        $attrs = [];
        if (!empty($prosody['rate'])) $attrs[] = 'rate="' . $prosody['rate'] . '"';
        if (!empty($prosody['pitch'])) $attrs[] = 'pitch="' . $prosody['pitch'] . '"';
        if (!empty($prosody['volume'])) $attrs[] = 'volume="' . $prosody['volume'] . '"';

        $escaped = htmlspecialchars($text, ENT_XML1, 'UTF-8');

        if (empty($attrs)) {
            $this->elements[] = $escaped;
        } else {
            $attrStr = implode(' ', $attrs);
            $this->elements[] = "<prosody {$attrStr}>{$escaped}</prosody>";
        }

        return $this;
    }

    /**
     * Add a silence break.
     */
    public function addBreak(string $time = '300ms'): self
    {
        $this->elements[] = "<break time=\"{$time}\"/>";
        return $this;
    }

    /**
     * Build complete SSML document.
     */
    public function build(): string
    {
        $content = implode('', $this->elements);

        return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="'
            . htmlspecialchars($this->locale, ENT_XML1) . '">'
            . '<voice name="' . htmlspecialchars($this->voice, ENT_XML1) . '">'
            . $content
            . '</voice></speak>';
    }

    /**
     * Build SSML from text with emotion-aware per-sentence prosody.
     * Splits text into sentences, applies prosody and natural pauses.
     */
    public static function fromTextWithEmotion(
        string $text,
        string $voice,
        string $emotion,
        array $prosodyBase,
        string $locale = 'uz-UZ'
    ): string {
        $builder = new self($voice, $locale);

        // Split into sentences (preserve sentence-ending punctuation)
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($sentences)) {
            $builder->addProsody($text, $prosodyBase);
            return $builder->build();
        }

        foreach ($sentences as $i => $sentence) {
            if ($i > 0) {
                $pauseMs = self::getSentencePause($sentence, $emotion);
                $builder->addBreak("{$pauseMs}ms");
            }

            // Adjust prosody per sentence type
            $sentenceProsody = self::adjustProsodyForSentence($sentence, $prosodyBase, $emotion);
            $builder->addProsody($sentence, $sentenceProsody);
        }

        return $builder->build();
    }

    /**
     * Get pause duration between sentences based on emotion.
     */
    private static function getSentencePause(string $nextSentence, string $emotion): int
    {
        $basePause = match ($emotion) {
            'sad' => 500,
            'angry' => 200,
            'excited', 'happy' => 250,
            'fear' => 300,
            default => 350,
        };

        // Contrast/continuation words get a slightly longer pause
        if (preg_match('/^\s*(but|however|yet|still|no|нет|лекин|аммо)/iu', $nextSentence)) {
            $basePause += 100;
        }

        return $basePause;
    }

    /**
     * Adjust prosody for individual sentence characteristics.
     */
    private static function adjustProsodyForSentence(string $sentence, array $prosody, string $emotion): array
    {
        $adjusted = $prosody;
        $trimmed = rtrim($sentence);

        // Exclamatory sentences: boost pitch and rate slightly
        if (str_ends_with($trimmed, '!')) {
            $adjusted['pitch'] = self::adjustHz($adjusted['pitch'] ?? '+0Hz', 5);
            $adjusted['rate'] = self::adjustPercent($adjusted['rate'] ?? '+0%', 3);
        }

        // Questions: rise pitch for natural intonation
        if (str_ends_with($trimmed, '?')) {
            $adjusted['pitch'] = self::adjustHz($adjusted['pitch'] ?? '+0Hz', 8);
        }

        // Ellipsis: slow down slightly for dramatic effect
        if (str_contains($sentence, '...') || str_contains($sentence, '…')) {
            $adjusted['rate'] = self::adjustPercent($adjusted['rate'] ?? '+0%', -5);
        }

        return $adjusted;
    }

    /**
     * Adjust a Hz value string by a delta.
     * "+10Hz" + delta 5 => "+15Hz"
     */
    private static function adjustHz(string $hz, int $delta): string
    {
        $current = (int) preg_replace('/[^-0-9]/', '', $hz);
        $new = $current + $delta;
        return ($new >= 0 ? "+{$new}" : "{$new}") . 'Hz';
    }

    /**
     * Adjust a percentage value string by a delta.
     * "+10%" + delta 3 => "+13%"
     */
    private static function adjustPercent(string $pct, int $delta): string
    {
        $current = (int) preg_replace('/[^-0-9]/', '', $pct);
        $new = $current + $delta;
        return ($new >= 0 ? "+{$new}" : "{$new}") . '%';
    }

    /**
     * Map language code to SSML locale.
     */
    public static function languageToLocale(string $lang): string
    {
        return match (strtolower($lang)) {
            'uz', 'uzbek' => 'uz-UZ',
            'ru', 'russian' => 'ru-RU',
            'en', 'english' => 'en-US',
            'tr', 'turkish' => 'tr-TR',
            default => 'en-US',
        };
    }
}
