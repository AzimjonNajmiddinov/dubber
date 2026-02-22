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
     *
     * Professional dubbing approach:
     * - Background starts lowering BEFORE speech begins (pre-duck)
     * - Background recovers SLOWLY after speech ends (post-duck)
     * - Adjacent dialogue blocks are merged so background stays consistently
     *   low during conversation, only rising during real pauses
     * - Smooth S-curve-like ramps (linear in FFmpeg, but long enough to sound natural)
     */
    protected function buildDuckingExpression($segments): string
    {
        // Merge segments into dialogue blocks with generous padding.
        // 600ms padding → blocks within 1.2s merge into one continuous duck.
        // This prevents the "pumping" effect where background rapidly rises
        // and falls between consecutive dialogue lines.
        $padding = 0.60;
        $blocks = [];

        foreach ($segments as $seg) {
            $start = max(0, (float) $seg->start_time - $padding);
            $end = (float) $seg->end_time + $padding;

            if (!empty($blocks) && $start <= end($blocks)['end']) {
                $blocks[count($blocks) - 1]['end'] = max($blocks[count($blocks) - 1]['end'], $end);
            } else {
                $blocks[] = ['start' => $start, 'end' => $end];
            }
        }

        if (empty($blocks)) {
            return '1';
        }

        // Ramp durations — long and asymmetric for natural feel:
        // - Fade-in (duck down): 400ms — background lowers gradually before speech
        // - Fade-out (recover):  800ms — background comes back very slowly after speech
        // The slow recovery is KEY — it's the most noticeable transition in dubbing.
        // Human hearing is more sensitive to sounds appearing than disappearing.
        $fadeIn = 0.40;
        $fadeOut = 0.80;

        $terms = [];
        foreach ($blocks as $b) {
            $rampUpStart = $b['start'] - $fadeIn;
            $rampDownEnd = $b['end'] + $fadeOut;
            $terms[] = sprintf(
                'min(max((t-%.3f)/%.3f\\,0)\\,1)*min(max((%.3f-t)/%.3f\\,0)\\,1)',
                $rampUpStart,
                $fadeIn,
                $rampDownEnd,
                $fadeOut
            );
        }

        // Duck depth: 0.50 → during speech bed is at 50% volume (-6dB)
        // Less aggressive than before (was 0.55 → 45%), keeps background present
        // but clearly subordinate to speech.
        return '1-min(' . implode('+', $terms) . '\\,1)*0.50';
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

            // 2) Fetch TTS segments (with speaker for crossfade logic)
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

            $segmentValues = $segments->values();
            $segmentCount = $segmentValues->count();

            foreach ($segmentValues as $idx => $seg) {
                $ttsRel = $seg->tts_audio_path;
                $ttsAbs = Storage::disk('local')->path($ttsRel);

                if (!file_exists($ttsAbs) || filesize($ttsAbs) < 500) {
                    throw new \RuntimeException("Missing/invalid TTS file: {$ttsRel}");
                }

                $inputs[] = '-i ' . escapeshellarg($ttsAbs);

                $slotStart = (float) $seg->start_time;
                $delayMs = max(0, (int) round($slotStart * 1000));
                $label = "tts{$i}";

                // Compute max allowed duration: gap until next segment starts
                $maxDuration = null;
                if ($idx < $segmentCount - 1) {
                    $nextSeg = $segmentValues[$idx + 1];
                    $gap = (float) $nextSeg->start_time - (float) $seg->start_time;
                    if ($gap > 0) {
                        $maxDuration = $gap - 0.03; // 30ms safety margin
                    }
                }

                $filters = "aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo";

                // Hard-trim TTS audio so it cannot bleed into the next segment
                if ($maxDuration !== null && $maxDuration > 0.1) {
                    $fadeStart = max(0, $maxDuration - 0.05);
                    $filters .= ",atrim=end=" . number_format($maxDuration, 3, '.', '');
                    $filters .= ",afade=t=out:st=" . number_format($fadeStart, 3, '.', '') . ":d=0.05";
                }

                $filters .= ",adelay={$delayMs}|{$delayMs}";

                $filtersBase[] = "[{$i}:a]{$filters}[{$label}]";

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

            // 5) Bed + final mix — duck bed during speech to hide Demucs vocal residue
            $filtersMain = $filtersBase;

            $duckExpr = $this->buildDuckingExpression($segments);

            $filtersMain[] =
                "[0:a]"
                . "aresample=48000,"
                . "aformat=sample_fmts=fltp:channel_layouts=stereo,"
                . "highpass=f=40,"
                . "volume='" . $duckExpr . "':eval=frame"
                . "[ducked]";

            // Final mix — bed (ducked) + TTS vocals
            // loudnorm with higher LRA (13) allows more natural dynamics
            $filtersMain[] =
                "[ducked][ttsbus]amix=inputs=2:duration=first:dropout_transition=0:normalize=0,"
                . "loudnorm=I=-14:TP=-1.5:LRA=13,"
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
