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
     * Build FFmpeg volume expression that mutes the bed during TTS segments.
     * Returns expression like: 1-min(between(t\,0.5\,2.3)+between(t\,3.0\,5.0)\,1)*0.97
     * During TTS: volume = 0.03 (-30dB), Between TTS: volume = 1.0
     */
    protected function buildDuckingExpression($segments): string
    {
        // Merge overlapping/adjacent segments with padding into blocks
        $padding = 0.08; // 80ms padding before/after each segment
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

        // volume = 1 - min(sum, 1) * 0.97
        // When TTS plays: 1 - 1*0.97 = 0.03 (-30dB)
        // When no TTS:    1 - 0*0.97 = 1.0  (full volume)
        return '1-min(' . implode('+', $terms) . '\\,1)*0.97';
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

                $delayMs = max(0, (int) round(((float) $seg->start_time) * 1000));
                $label = "tts{$i}";

                // Simple TTS processing - delay to correct position
                $filtersBase[] =
                    "[{$i}:a]"
                    . "aresample=48000,"
                    . "aformat=sample_fmts=fltp:channel_layouts=stereo,"
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
            // Gentle EQ to reduce vocal bleed while preserving music quality
            $filtersMain[] =
                "[0:a]"
                . "aresample=48000,"
                . "aformat=sample_fmts=fltp:channel_layouts=stereo,"
                . "highpass=f=40,"
                . "lowpass=f=15000,"
                // Gentle vocal reduction (less aggressive than before)
                . "equalizer=f=100:t=q:w=0.7:g=+1,"     // Warm bass
                . "equalizer=f=400:t=q:w=1.2:g=-2,"     // Slight cut low-mids
                . "equalizer=f=1200:t=q:w=1.5:g=-3,"    // Moderate cut speech range
                . "equalizer=f=2500:t=q:w=1.2:g=-2,"    // Slight cut presence
                . "equalizer=f=8000:t=q:w=0.8:g=+1,"    // Air/sparkle
                . "volume=-8dB,"                         // Lower bed level
                . "volume={$duckExpr}:eval=frame"        // Duck during TTS
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
                // Keep TTS-only file for debugging
                // @unlink($ttsOnlyAbs);
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
