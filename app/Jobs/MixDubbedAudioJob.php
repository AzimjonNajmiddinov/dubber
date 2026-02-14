<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoSegment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MixDubbedAudioJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 10;
    public int $uniqueFor = 1800;

    /**
     * Backoff between retries (seconds).
     * Shorter intervals since this job may requeue while waiting for dependencies.
     */
    public array $backoff = [15, 30, 45, 60, 90, 120, 150, 180, 210, 240];

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    /**
     * Handle job failure - mark video as failed.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('MixDubbedAudioJob failed permanently', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $video = Video::find($this->videoId);
            if ($video) {
                $video->update(['status' => 'mix_failed']);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to update video status after MixDubbedAudioJob failure', [
                'video_id' => $this->videoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build FFmpeg volume expression that ducks the bed during TTS segments.
     * Professional dubbing style: background stays audible but lower.
     * Returns expression like: 1-min(between(t\,0.5\,2.3)+between(t\,3.0\,5.0)\,1)*0.6
     * During TTS: volume = 0.4 (-8dB), Between TTS: volume = 1.0
     */
    protected function buildDuckingExpression($segments): string
    {
        // Merge overlapping/adjacent segments with padding into blocks
        $padding = 0.05; // 50ms padding before/after each segment
        $blocks = [];

        foreach ($segments as $seg) {
            $start = max(0, (float) $seg->start_time - $padding);
            $end = (float) $seg->end_time + $padding;

            if (!empty($blocks) && $start <= end($blocks)['end']) {
                // Merge with previous block
                $blocks[count($blocks) - 1]['end'] = max($blocks[count($blocks) - 1]['end'], $end);
            } else {
                $blocks[] = ['start' => $start, 'end' => $end];
            }
        }

        if (empty($blocks)) {
            return '1'; // No ducking needed
        }

        // Build between() terms with escaped commas for FFmpeg filter_complex
        $terms = [];
        foreach ($blocks as $b) {
            $terms[] = sprintf(
                'between(t\\,%.3f\\,%.3f)',
                $b['start'],
                $b['end']
            );
        }

        // volume = 1 - min(sum, 1) * 0.6
        // When TTS plays: 1 - 1*0.6 = 0.4 (-8dB) - background still audible
        // When no TTS:    1 - 0*0.6 = 1.0  (full volume)
        return '1-min(' . implode('+', $terms) . '\\,1)*0.6';
    }

    public function handle(): void
    {
        $lock = Cache::lock("video:{$this->videoId}:mix", 1800);
        if (!$lock->get()) return;

        try {
            /** @var Video $video */
            $video = Video::query()->findOrFail($this->videoId);

            // 1) Require bed stem
            $bedRel = "audio/stems/{$video->id}/no_vocals.wav";
            $bedAbs = Storage::disk('local')->path($bedRel);

            if (!file_exists($bedAbs) || filesize($bedAbs) < 5000) {
                Log::info('Mix waiting for no_vocals stem; requeueing', [
                    'video_id' => $video->id,
                    'expected' => $bedRel,
                    'exists' => file_exists($bedAbs),
                    'size' => file_exists($bedAbs) ? filesize($bedAbs) : null,
                ]);
                $this->release(20);
                return;
            }

            // 2) Fetch TTS segments
            $segments = VideoSegment::query()
                ->where('video_id', $video->id)
                ->whereNotNull('tts_audio_path')
                ->orderBy('start_time')
                ->get();

            if ($segments->isEmpty()) {
                throw new \RuntimeException("No TTS segments found for video {$video->id}");
            }

            // Output WAV for ReplaceVideoAudioJob
            $finalRel = "audio/final/{$video->id}.wav";
            $finalAbs = Storage::disk('local')->path($finalRel);
            @mkdir(dirname($finalAbs), 0777, true);
            @unlink($finalAbs);

            // Debug TTS-only render
            $ttsOnlyRel = "audio/final/_tts_only_{$video->id}.wav";
            $ttsOnlyAbs = Storage::disk('local')->path($ttsOnlyRel);
            @unlink($ttsOnlyAbs);

            // 3) Build inputs and base per-tts filters
            // [0] = bed, [1..N] = tts
            $inputs = [];
            $inputs[] = '-i ' . escapeshellarg($bedAbs);

            $filtersBase = [];
            $ttsLabels = [];
            $i = 1;

            foreach ($segments as $seg) {
                $ttsRel = $seg->tts_audio_path;
                $ttsAbs = Storage::disk('local')->path($ttsRel);

                if (!file_exists($ttsAbs) || filesize($ttsAbs) < 500) {
                    throw new \RuntimeException("Missing/invalid TTS file: {$ttsRel}");
                }

                $inputs[] = '-i ' . escapeshellarg($ttsAbs);

                // Get TTS duration for centering
                $ttsDuration = 0;
                $probeCmd = sprintf(
                    'ffprobe -v error -show_entries format=duration -of csv=p=0 %s 2>/dev/null',
                    escapeshellarg($ttsAbs)
                );
                $ttsDuration = (float) trim(shell_exec($probeCmd) ?? '0');

                // Calculate slot duration
                $slotStart = (float) $seg->start_time;
                $slotEnd = (float) $seg->end_time;
                $slotDuration = $slotEnd - $slotStart;

                // START TTS AT SEGMENT START - no centering!
                // Centering adds unnatural pauses. Better to start speaking immediately
                // and let natural speech rhythm fill the slot.
                $delayMs = max(0, (int) round($slotStart * 1000));
                $label = "tts{$i}";

                // TTS processing - minimal fade to prevent clicks only
                // Very short fades (10-15ms) - just enough to avoid audio pops
                // NO aggressive fade-out that cuts word endings!
                $fadeIn = 0.01;   // 10ms fade-in (click prevention only)
                $fadeOut = 0.015; // 15ms fade-out (click prevention only)
                $fadeOutStart = max(0, $ttsDuration - $fadeOut);

                $filtersBase[] =
                    "[{$i}:a]"
                    . "aresample=48000,"
                    . "aformat=sample_fmts=fltp:channel_layouts=stereo,"
                    . "afade=t=in:st=0:d={$fadeIn},"
                    . "afade=t=out:st={$fadeOutStart}:d={$fadeOut},"
                    . "adelay={$delayMs}|{$delayMs}"
                    . "[{$label}]";

                $ttsLabels[] = "[{$label}]";
                $i++;
            }

            // Build ttsbus from labels
            if (count($ttsLabels) === 1) {
                $filtersBase[] = $ttsLabels[0] . "anull[ttsbus]";
            } else {
                $filtersBase[] =
                    implode('', $ttsLabels)
                    . "amix=inputs=" . count($ttsLabels)
                    . ":normalize=0:dropout_transition=0[ttsbus]";
            }

            // 4) Render TTS-only debug (NO asplit here)
            $filtersTtsOnly = $filtersBase;
            $filtersTtsOnly[] = "[ttsbus]alimiter=limit=0.95[tts_only]";
            $fcTtsOnly = implode(';', $filtersTtsOnly);

            $cmdTtsOnly = sprintf(
                "ffmpeg -y -hide_banner -loglevel error %s -filter_complex %s -map %s -c:a pcm_s16le %s 2>&1",
                implode(' ', $inputs),
                escapeshellarg($fcTtsOnly),
                escapeshellarg('[tts_only]'),
                escapeshellarg($ttsOnlyAbs)
            );

            $outT = [];
            exec($cmdTtsOnly, $outT, $codeT);

            if ($codeT !== 0 || !file_exists($ttsOnlyAbs) || filesize($ttsOnlyAbs) < 5000) {
                Log::error('TTS-only render failed', [
                    'video_id' => $video->id,
                    'exit_code' => $codeT,
                    'stderr' => implode("\n", array_slice($outT ?? [], -120)),
                    'cmd' => $cmdTtsOnly,
                    'tts_only_exists' => file_exists($ttsOnlyAbs),
                    'tts_only_size' => file_exists($ttsOnlyAbs) ? filesize($ttsOnlyAbs) : null,
                ]);
                throw new \RuntimeException("TTS-only render failed for video {$video->id}");
            }

            // 5) Bed + deterministic time-based ducking + final mix
            $filtersMain = $filtersBase;

            // Build volume expression that mutes the bed during TTS segments
            $duckExpr = $this->buildDuckingExpression($segments);

            Log::info('Bed ducking expression built', [
                'video_id' => $video->id,
                'expression_length' => strlen($duckExpr),
            ]);

            // BACKGROUND BED with time-based ducking
            // Keep background loud like original, only duck during speech
            $filtersMain[] =
                "[0:a]"
                . "aresample=48000,"
                . "aformat=sample_fmts=fltp:channel_layouts=stereo,"
                . "highpass=f=60,"   // Remove rumble
                . "lowpass=f=14000," // Remove hiss
                . "volume={$duckExpr}:eval=frame"  // Duck during TTS only
                . "[ducked]";

            // Final mix - bed (ducked) + TTS
            $filtersMain[] =
                "[ducked][ttsbus]amix=inputs=2:duration=first:dropout_transition=0:normalize=0,"
                . "loudnorm=I=-14:TP=-1.5:LRA=11,"    // broadcast loudness standard
                . "alimiter=limit=0.95"
                . "[mixed]";

            $fc = implode(';', $filtersMain);

            $cmd = sprintf(
                "ffmpeg -y -hide_banner -loglevel error %s -filter_complex %s -map %s -c:a pcm_s16le %s 2>&1",
                implode(' ', $inputs),
                escapeshellarg($fc),
                escapeshellarg('[mixed]'),
                escapeshellarg($finalAbs)
            );

            Log::info('FFmpeg mix starting', [
                'video_id' => $video->id,
                'bed' => $bedRel,
                'final' => $finalRel,
                'tts_count' => $segments->count(),
                'filter_complex_length' => strlen($fc),
                'first_3_delays_ms' => array_map(fn($s) => (int) round(((float) $s->start_time) * 1000), $segments->take(3)->all()),
            ]);

            $out = [];
            exec($cmd, $out, $code);

            if ($code !== 0 || !file_exists($finalAbs) || filesize($finalAbs) < 5000) {
                Log::error('FFmpeg mix failed; using TTS-only as final', [
                    'video_id' => $video->id,
                    'exit_code' => $code,
                    'stderr' => implode("\n", array_slice($out ?? [], -120)),
                    'cmd' => $cmd,
                    'final_exists' => file_exists($finalAbs),
                    'final_size' => file_exists($finalAbs) ? filesize($finalAbs) : null,
                ]);

                copy($ttsOnlyAbs, $finalAbs);
            } else {
                // Clean up debug TTS-only file after successful mix
                @unlink($ttsOnlyAbs);
            }

            Log::info('Mix completed', [
                'video_id' => $video->id,
                'final_size' => filesize($finalAbs),
                'tts_count' => $segments->count(),
            ]);

            $video->update([
                'final_audio_path' => $finalRel,
                'status' => 'mixed',
            ]);

            ReplaceVideoAudioJob::dispatch($video->id);

        } finally {
            optional($lock)->release();
        }
    }
}
