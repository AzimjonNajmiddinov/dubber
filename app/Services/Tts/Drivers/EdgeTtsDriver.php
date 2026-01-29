<?php

namespace App\Services\Tts\Drivers;

use App\Contracts\TtsDriverInterface;
use App\Models\Speaker;
use App\Models\VideoSegment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class EdgeTtsDriver implements TtsDriverInterface
{
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
        return false; // Only prosody tweaks, not real emotions
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
        $voice = $options['voice'] ?? $speaker->tts_voice ?? 'uz-UZ-SardorNeural';
        $rate = $options['rate'] ?? $speaker->tts_rate ?? '+0%';
        $pitch = $options['pitch'] ?? $speaker->tts_pitch ?? '+0Hz';

        $videoId = $segment->video_id;
        $segmentId = $segment->id;

        $outDir = Storage::disk('local')->path("audio/tts/{$videoId}");
        @mkdir($outDir, 0777, true);

        $rawMp3 = "{$outDir}/seg_{$segmentId}.raw.mp3";
        $outputWav = "{$outDir}/seg_{$segmentId}.wav";

        @unlink($rawMp3);
        @unlink($outputWav);

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

        // Convert to normalized WAV
        $this->normalizeAudio($rawMp3, $outputWav, $options);
        @unlink($rawMp3);

        return $outputWav;
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
        $gainDb = $options['gain_db'] ?? 0.0;
        $lufsTarget = $options['lufs_target'] ?? -16.0;

        $filter = sprintf(
            'aresample=48000,' .
            'aformat=sample_fmts=fltp:channel_layouts=stereo,' .
            'highpass=f=80,' .
            'lowpass=f=10000,' .
            'volume=%sdB,' .
            'loudnorm=I=%s:TP=-1.5:LRA=11,' .
            'aresample=48000',
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
            throw new RuntimeException("Audio normalization failed");
        }
    }
}
