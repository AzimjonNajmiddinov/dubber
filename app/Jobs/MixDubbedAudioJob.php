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

                // PROFESSIONAL MOVIE DUBBING - clean, natural, clear
                $filtersBase[] =
                    "[{$i}:a]"
                    . "aresample=48000,"
                    . "aformat=sample_fmts=fltp:channel_layouts=stereo,"
                    . "adelay={$delayMs}|{$delayMs},"
                    // Clean up
                    . "highpass=f=60,"
                    . "lowpass=f=15000,"
                    // Professional voice EQ - natural and clear
                    . "equalizer=f=120:t=q:w=0.7:g=+2,"      // body/fullness
                    . "equalizer=f=2800:t=q:w=1.0:g=+3,"     // clarity/intelligibility
                    . "equalizer=f=5500:t=q:w=0.8:g=+2,"     // presence/air
                    . "equalizer=f=7500:t=q:w=1.5:g=-1,"     // reduce harshness
                    // Broadcast-style compression for consistent level
                    . "acompressor=threshold=-20dB:ratio=3:attack=10:release=100:makeup=2dB,"
                    // Voice level - prominent but natural
                    . "volume=+4dB"
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

            // 5) Bed + ducking + final mix (USE asplit because ttsbus is reused)
            $filtersMain = $filtersBase;

            // Split ttsbus for two consumers: sidechain + mix
            $filtersMain[] = "[ttsbus]asplit=2[tts_sc][tts_mix]";

            // BACKGROUND BED - suppress vocal bleed from stem separation
            $filtersMain[] =
                "[0:a]"
                . "aresample=48000,"
                . "aformat=sample_fmts=fltp:channel_layouts=stereo,"
                . "highpass=f=30,"
                . "lowpass=f=16000,"
                // Cut vocal frequencies aggressively to reduce English bleed
                . "equalizer=f=80:t=q:w=0.6:g=+2,"     // keep bass
                . "equalizer=f=300:t=q:w=1.0:g=-4,"    // cut low vocal range
                . "equalizer=f=1500:t=q:w=2.0:g=-6,"   // heavy cut on speech range
                . "equalizer=f=3000:t=q:w=1.5:g=-5,"   // cut intelligibility range
                . "equalizer=f=8000:t=q:w=1.0:g=+1,"   // keep high detail
                . "volume=-8dB"              // much lower bed level
                . "[bed]";

            // Aggressive ducking - suppress English vocal bleed when TTS plays
            $filtersMain[] =
                "[bed][tts_sc]sidechaincompress="
                . "threshold=0.008:"         // trigger on quieter TTS
                . "ratio=12:"               // aggressive ducking
                . "attack=8:"               // fast attack
                . "release=400:"            // quick release
                . "knee=4"                  // tighter knee
                . "[ducked]";

            // Final mix - professional balance
            $filtersMain[] =
                "[ducked][tts_mix]amix=inputs=2:duration=first:dropout_transition=0:normalize=0,"
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
