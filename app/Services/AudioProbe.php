<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class AudioProbe
{
    public static function durationSeconds(string $absolutePath): ?float
    {
        $r = Process::timeout(60)->run([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $absolutePath,
        ]);

        if (!$r->successful()) {
            return null;
        }

        $out = trim((string) $r->output());
        if ($out === '' || !is_numeric($out)) {
            return null;
        }

        return (float) $out;
    }

    public static function makeSilenceWav(string $absolutePath, float $seconds, int $sampleRate = 48000): bool
    {
        $seconds = max(0.0, $seconds);

        $r = Process::timeout(60)->run([
            'ffmpeg', '-y',
            '-f', 'lavfi',
            '-i', "anullsrc=r={$sampleRate}:cl=stereo",
            '-t', (string) $seconds,
            '-c:a', 'pcm_s16le',
            $absolutePath,
        ]);

        return $r->successful() && file_exists($absolutePath);
    }
}
