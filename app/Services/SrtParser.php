<?php

namespace App\Services;

class SrtParser
{
    /**
     * Parse SRT text into an array of segments.
     *
     * @return array<int, array{start: float, end: float, text: string}>
     */
    public static function parse(string $srt): array
    {
        $srt = str_replace("\r\n", "\n", trim($srt));
        $blocks = preg_split('/\n\n+/', $srt);
        $segments = [];

        foreach ($blocks as $block) {
            $lines = explode("\n", trim($block));

            if (count($lines) < 2) {
                continue;
            }

            // Find the timecode line (contains " --> ")
            $timecodeIndex = null;
            foreach ($lines as $i => $line) {
                if (str_contains($line, ' --> ')) {
                    $timecodeIndex = $i;
                    break;
                }
            }

            if ($timecodeIndex === null) {
                continue;
            }

            $timeParts = explode(' --> ', $lines[$timecodeIndex]);
            if (count($timeParts) !== 2) {
                continue;
            }

            $start = self::timecodeToSeconds(trim($timeParts[0]));
            $end = self::timecodeToSeconds(trim($timeParts[1]));

            if ($start === null || $end === null) {
                continue;
            }

            // Text is everything after the timecode line
            $textLines = array_slice($lines, $timecodeIndex + 1);
            $text = trim(implode(' ', array_map('trim', $textLines)));

            if ($text === '') {
                continue;
            }

            $segments[] = [
                'start' => $start,
                'end' => $end,
                'text' => $text,
            ];
        }

        return $segments;
    }

    /**
     * Convert SRT timecode (HH:MM:SS,mmm) to seconds.
     */
    private static function timecodeToSeconds(string $tc): ?float
    {
        // Support both comma and dot as millisecond separator
        $tc = str_replace(',', '.', $tc);

        if (!preg_match('/^(\d{1,2}):(\d{2}):(\d{2})\.(\d{1,3})$/', $tc, $m)) {
            return null;
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];
        $seconds = (int) $m[3];
        $ms = str_pad($m[4], 3, '0');

        return $hours * 3600 + $minutes * 60 + $seconds + ((int) $ms) / 1000;
    }
}
