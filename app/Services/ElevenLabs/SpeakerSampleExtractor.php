<?php

namespace App\Services\ElevenLabs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SpeakerSampleExtractor
{
    private const MAX_SAMPLE_DURATION = 30.0;

    public function extractSamples(string $originalAudioPath, array $segments): array
    {
        $speakerSegments = [];
        foreach ($segments as $seg) {
            $tag = $seg['speaker'] ?? 'M1';
            $speakerSegments[$tag][] = $seg;
        }

        $samples = [];
        $tmpDir = sys_get_temp_dir() . '/elevenlabs-samples-' . uniqid();
        @mkdir($tmpDir, 0755, true);

        foreach ($speakerSegments as $tag => $segs) {
            try {
                $samplePath = $this->extractSpeakerSample($originalAudioPath, $tag, $segs, $tmpDir);
                if ($samplePath) {
                    $samples[$tag] = $samplePath;
                }
            } catch (\Throwable $e) {
                Log::warning('Speaker sample extraction failed', [
                    'speaker' => $tag,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $samples;
    }

    private function extractSpeakerSample(string $audioPath, string $tag, array $segs, string $tmpDir): ?string
    {
        // Sort by duration descending — prefer longer segments for better cloning
        usort($segs, fn($a, $b) => (($b['end'] - $b['start']) <=> ($a['end'] - $a['start'])));

        $collected = 0.0;
        $parts = [];

        foreach ($segs as $seg) {
            $start = (float) $seg['start'];
            $end = (float) $seg['end'];
            $duration = $end - $start;

            if ($duration < 0.5) continue;

            $remaining = self::MAX_SAMPLE_DURATION - $collected;
            if ($remaining <= 0) break;

            $useDuration = min($duration, $remaining);
            $partFile = "{$tmpDir}/{$tag}_part_" . count($parts) . '.wav';

            $result = Process::timeout(15)->run([
                'ffmpeg', '-y',
                '-ss', (string) round($start, 3),
                '-t', (string) round($useDuration, 3),
                '-i', $audioPath,
                '-ac', '1', '-ar', '44100',
                '-c:a', 'pcm_s16le',
                $partFile,
            ]);

            if ($result->successful() && file_exists($partFile) && filesize($partFile) > 1000) {
                $parts[] = $partFile;
                $collected += $useDuration;
            }
        }

        if (empty($parts)) return null;

        $outputPath = "{$tmpDir}/{$tag}_sample.wav";

        if (count($parts) === 1) {
            rename($parts[0], $outputPath);
            return $outputPath;
        }

        // Concatenate parts using ffmpeg concat
        $concatList = "{$tmpDir}/{$tag}_concat.txt";
        $listContent = '';
        foreach ($parts as $part) {
            $listContent .= "file '" . $part . "'\n";
        }
        file_put_contents($concatList, $listContent);

        $result = Process::timeout(15)->run([
            'ffmpeg', '-y',
            '-f', 'concat', '-safe', '0',
            '-i', $concatList,
            '-ac', '1', '-ar', '44100',
            '-c:a', 'pcm_s16le',
            $outputPath,
        ]);

        // Clean up parts
        foreach ($parts as $part) {
            @unlink($part);
        }
        @unlink($concatList);

        if ($result->successful() && file_exists($outputPath) && filesize($outputPath) > 1000) {
            Log::info('Speaker sample extracted', [
                'speaker' => $tag,
                'duration' => round($collected, 1),
                'segments_used' => count($parts),
            ]);
            return $outputPath;
        }

        return null;
    }
}
