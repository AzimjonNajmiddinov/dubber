<?php

namespace App\Jobs;

use App\Models\Speaker;
use App\Models\Video;
use App\Models\VideoSegment;
use App\Services\Tts\TtsManager;
use App\Traits\DetectsEnglish;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Process a single TTS segment - designed for parallel execution.
 */
class ProcessTtsSegmentJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use DetectsEnglish;

    public int $timeout = 120;
    public int $tries = 2;
    public array $backoff = [5, 15];

    public function __construct(
        public int $segmentId,
        public int $videoId
    ) {}

    public function handle(TtsManager $ttsManager): void
    {
        // Skip if batch was cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        $seg = VideoSegment::find($this->segmentId);
        if (!$seg || $seg->tts_audio_path) {
            return; // Already processed or deleted
        }

        $video = Video::find($this->videoId);
        if (!$video) {
            return;
        }

        $text = trim((string) $seg->translated_text);
        if ($text === '' || $this->looksLikeEnglish($text)) {
            Log::warning('Skipping segment - empty or English', ['segment_id' => $seg->id]);
            return;
        }

        $speaker = $seg->speaker_id ? Speaker::find($seg->speaker_id) : null;
        $slotDuration = ((float) $seg->end_time) - ((float) $seg->start_time);
        $emotion = $this->detectEmotion($seg->text ?? '', $text);

        $options = [
            'emotion' => $emotion,
            'speed' => 1.0,
            'gain_db' => 0.0,
        ];

        // Get driver
        $driverName = config('dubber.tts.driver', 'xtts');
        $driver = $ttsManager->driver($driverName);

        $outDir = Storage::disk('local')->path("audio/tts/{$video->id}");
        @mkdir($outDir, 0777, true);

        try {
            $outputPath = $driver->synthesize($text, $speaker, $seg, $options);
            $this->fitAudioToSlot($outputPath, $slotDuration);

            $relPath = str_replace(Storage::disk('local')->path(''), '', $outputPath);
            $seg->update(['tts_audio_path' => $relPath]);

        } catch (\Throwable $e) {
            // Try fallback
            $fallbackName = config('dubber.tts.fallback', 'edge');
            if ($fallbackName && $fallbackName !== $driverName) {
                $fallbackDriver = $ttsManager->driver($fallbackName);
                $outputPath = $fallbackDriver->synthesize($text, $speaker, $seg, $options);
                $this->fitAudioToSlot($outputPath, $slotDuration);

                $relPath = str_replace(Storage::disk('local')->path(''), '', $outputPath);
                $seg->update(['tts_audio_path' => $relPath]);
            } else {
                throw $e;
            }
        }
    }

    protected function detectEmotion(string $original, string $translated): string
    {
        $text = $original . ' ' . $translated;
        $lower = mb_strtolower($text);

        if (preg_match('/[!]{2,}|\bwow\b|\bamazing\b|ajoyib/i', $text)) return 'happy';
        if (preg_match('/[?]{2,}|\bwhat\b.*[?]|nima/i', $text)) return 'surprise';
        if (preg_match('/\b(angry|mad|furious|damn)\b|jin/i', $lower)) return 'angry';
        if (preg_match('/\b(sad|sorry|unfortunately)\b|afsuski/i', $lower)) return 'sad';

        return 'neutral';
    }

    protected function fitAudioToSlot(string $audioPath, float $slotDuration): void
    {
        if ($slotDuration <= 0 || !file_exists($audioPath)) {
            return;
        }

        $probe = Process::timeout(10)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'csv=p=0',
            $audioPath,
        ]);

        if (!$probe->successful()) {
            return;
        }

        $audioDuration = (float) trim($probe->output());
        if ($audioDuration <= 0) {
            return;
        }

        $ratio = $audioDuration / $slotDuration;
        $maxTempo = 1.15; // Very gentle - Edge TTS pre-calculates rate
        $minTempo = 0.90;

        // Skip if close enough (within 10%)
        if ($ratio >= 0.90 && $ratio <= 1.10) {
            return;
        }

        if ($ratio < $minTempo) {
            // Too short - slow down slightly
            $filter = 'atempo=' . number_format(max($ratio, $minTempo), 4, '.', '');
        } elseif ($ratio <= $maxTempo) {
            // Minor speedup
            $filter = 'atempo=' . number_format($ratio, 4, '.', '');
        } else {
            // Too long - trim with gentle fade (no speedup beyond 15%)
            $fadeOut = max(0.05, min(0.15, $slotDuration * 0.05));
            $fadeStart = max(0, $slotDuration - $fadeOut);
            $filter = "atrim=0:{$slotDuration},asetpts=PTS-STARTPTS,afade=t=out:st={$fadeStart}:d={$fadeOut}";
        }

        $tmpPath = $audioPath . '.fitted.wav';

        $result = Process::timeout(30)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $audioPath,
            '-af', $filter,
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
            $tmpPath,
        ]);

        if ($result->successful() && file_exists($tmpPath) && filesize($tmpPath) > 0) {
            rename($tmpPath, $audioPath);
        } else {
            @unlink($tmpPath);
        }
    }
}
