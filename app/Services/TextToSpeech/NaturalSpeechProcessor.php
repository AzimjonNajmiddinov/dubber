<?php

namespace App\Services\TextToSpeech;

/**
 * Processes text to make TTS output sound more natural.
 *
 * Adds natural speech patterns:
 * - Breathing pauses
 * - Emotional emphasis
 * - Natural rhythm variations
 */
class NaturalSpeechProcessor
{
    /**
     * Process text for more natural TTS delivery.
     */
    public function process(string $text, string $emotion = 'neutral'): string
    {
        $text = trim($text);

        if (empty($text)) {
            return $text;
        }

        // Add natural pauses at punctuation
        $text = $this->addNaturalPauses($text);

        // Add emotional markers based on detected emotion
        $text = $this->addEmotionalMarkers($text, $emotion);

        // Add breathing rhythm for long sentences
        $text = $this->addBreathingRhythm($text);

        return $text;
    }

    /**
     * Add natural pauses at punctuation marks.
     */
    protected function addNaturalPauses(string $text): string
    {
        // Ellipsis - longer pause with trailing feel
        $text = preg_replace('/\.{3,}/', '...', $text);

        // Em-dash - dramatic pause
        $text = str_replace(['—', '--'], ' — ', $text);

        return $text;
    }

    /**
     * Add emotional markers to guide TTS.
     */
    protected function addEmotionalMarkers(string $text, string $emotion): string
    {
        $emotion = strtolower($emotion);

        switch ($emotion) {
            case 'excited':
            case 'happy':
                // Add exclamation if missing at end of energetic phrases
                if (!preg_match('/[!?]$/', $text) && mb_strlen($text) < 50) {
                    $text = rtrim($text, '.') . '!';
                }
                break;

            case 'sad':
            case 'melancholy':
                // Add trailing feel
                if (!str_ends_with($text, '...') && !str_ends_with($text, '?')) {
                    $text = rtrim($text, '.!') . '...';
                }
                break;

            case 'angry':
            case 'frustrated':
                // Ensure forceful ending
                if (!str_ends_with($text, '!')) {
                    $text = rtrim($text, '.') . '!';
                }
                break;

            case 'questioning':
            case 'confused':
                if (!str_ends_with($text, '?')) {
                    $text = rtrim($text, '.!') . '?';
                }
                break;

            case 'whispering':
            case 'secretive':
                // Add ellipsis for hushed tone
                if (!str_ends_with($text, '...')) {
                    $text = rtrim($text, '.!?') . '...';
                }
                break;
        }

        return $text;
    }

    /**
     * Add natural breathing rhythm for long sentences.
     */
    protected function addBreathingRhythm(string $text): string
    {
        // For very long sentences without natural breaks, add subtle comma pauses
        $words = explode(' ', $text);

        if (count($words) > 15) {
            // Find natural break points (after 8-12 words if no punctuation)
            $result = [];
            $wordCount = 0;

            foreach ($words as $word) {
                $result[] = $word;
                $wordCount++;

                // Add comma after 10 words if no punctuation present
                if ($wordCount >= 10 && !preg_match('/[,;:!?.]$/', $word)) {
                    $lastIdx = count($result) - 1;
                    if (!str_contains($result[$lastIdx], ',')) {
                        // Don't add comma before conjunctions
                        $nextWord = $words[array_search($word, $words) + 1] ?? '';
                        if (!in_array(strtolower($nextWord), ['and', 'but', 'or', 'so', 'yet'])) {
                            $result[$lastIdx] .= ',';
                            $wordCount = 0;
                        }
                    }
                }

                // Reset counter on natural punctuation
                if (preg_match('/[,;:!?.]$/', $word)) {
                    $wordCount = 0;
                }
            }

            $text = implode(' ', $result);
        }

        return $text;
    }
}
