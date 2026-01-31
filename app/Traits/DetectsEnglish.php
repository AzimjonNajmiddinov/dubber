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

        // High-confidence English words (excluding short words that overlap with Uzbek Latin)
        // Avoiding: 'i', 'to', 'or', 'on', 'in', 'as', 'no', 'it' - too common in Uzbek Latin
        $commonEnglishWords = [
            'the', 'and', 'you', 'your', 'they', 'are', 'was', 'were',
            'that', 'this', 'for', 'with', 'not',
            'does', 'did', 'have', 'has', 'what', 'why', 'when', 'where', 'how',
            'yes', 'please', 'thanks', 'thank', 'hello', 'okay', 'just', 'but',
            'can', 'will', 'would', 'could', 'should', 'been', 'being', 'from',
            'here', 'there', 'about', 'because', 'going', 'want', 'know', 'think',
            'really', 'very', 'something', 'anything', 'nothing', 'everything',
        ];

        // Extract Latin-alphabet words (3+ chars to avoid false positives)
        preg_match_all('/[a-z]{3,}/i', $t, $matches);
        $words = array_map('mb_strtolower', $matches[0] ?? []);

        // Short phrases - allow them through
        if (count($words) < 5) {
            return false;
        }

        // Count matches against common English words
        $hits = 0;
        foreach ($words as $word) {
            if (in_array($word, $commonEnglishWords, true)) {
                $hits++;
            }
        }

        // For longer texts, use ratio-based detection
        // Text is considered English if >15% of words are common English words
        $ratio = $hits / count($words);

        // Minimum 5 hits AND >15% ratio for it to be considered English
        return $hits >= 5 && $ratio > 0.15;
    }
}
