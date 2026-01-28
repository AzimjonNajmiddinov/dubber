<?php

namespace App\Traits;

/**
 * Trait for detecting if text appears to be English.
 * Used to guard against translation/TTS failures where English leaks through.
 */
trait DetectsEnglish
{
    /**
     * Check if text appears to be English based on common word frequency.
     * Returns true if the text seems to contain English (undesired in target language output).
     */
    protected function looksLikeEnglish(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return false;
        }

        // Common English words that would indicate untranslated content
        $commonEnglishWords = [
            'the', 'and', 'you', 'your', 'i', 'we', 'they', 'is', 'are', 'was', 'were',
            'to', 'of', 'in', 'that', 'this', 'it', 'on', 'for', 'with', 'as', 'not',
            'do', 'does', 'did', 'have', 'has', 'what', 'why', 'when', 'where', 'how',
            'yes', 'no', 'please', 'thanks', 'thank', 'hello', 'okay', 'just', 'but',
            'can', 'will', 'would', 'could', 'should', 'been', 'being', 'from', 'or',
        ];

        // Extract Latin-alphabet words (2+ chars)
        preg_match_all('/[a-z]{2,}/i', $t, $matches);
        $words = array_map('mb_strtolower', $matches[0] ?? []);

        // Short phrases need special handling - allow them through
        if (count($words) < 4) {
            return false;
        }

        // Count matches against common English words
        $hits = 0;
        foreach ($words as $word) {
            if (in_array($word, $commonEnglishWords, true)) {
                $hits++;
            }
        }

        // If 2+ common English words found, likely English
        return $hits >= 2;
    }
}
