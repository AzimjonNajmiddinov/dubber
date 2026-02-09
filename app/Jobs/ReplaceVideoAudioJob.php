<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReplaceVideoAudioJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1800;

    /**
     * Exponential backoff between retries (seconds).
     */
    public array $backoff = [30, 60, 120];

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
        Log::error('ReplaceVideoAudioJob failed permanently', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $video = Video::find($this->videoId);
            if ($video) {
                $video->update(['status' => 'mux_failed']);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to update video status after ReplaceVideoAudioJob failure', [
                'video_id' => $this->videoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clean up original video and intermediate files after successful dubbing.
     */
    private function cleanupOriginalFiles(Video $video, string $origMp4Abs, string $finalWavAbs): void
    {
        $deletedFiles = [];
        $deletedSize = 0;

        try {
            // 1. Delete original video file
            if (file_exists($origMp4Abs)) {
                $deletedSize += filesize($origMp4Abs);
                @unlink($origMp4Abs);
                $deletedFiles[] = $video->original_path;
            }

            // 2. Delete audio stems (vocals.wav, no_vocals.wav, etc.)
            $stemsDir = Storage::disk('local')->path("audio/stems/{$video->id}");
            if (is_dir($stemsDir)) {
                $stemFiles = glob("{$stemsDir}/*");
                foreach ($stemFiles as $file) {
                    if (is_file($file)) {
                        $deletedSize += filesize($file);
                        @unlink($file);
                    }
                }
                @rmdir($stemsDir);
                $deletedFiles[] = "audio/stems/{$video->id}/*";
            }

            // 3. Delete TTS audio files
            $ttsDir = Storage::disk('local')->path("audio/tts/{$video->id}");
            if (is_dir($ttsDir)) {
                $ttsFiles = glob("{$ttsDir}/*");
                foreach ($ttsFiles as $file) {
                    if (is_file($file)) {
                        $deletedSize += filesize($file);
                        @unlink($file);
                    }
                }
                @rmdir($ttsDir);
                $deletedFiles[] = "audio/tts/{$video->id}/*";
            }

            // 4. Delete final mixed audio
            if (file_exists($finalWavAbs)) {
                $deletedSize += filesize($finalWavAbs);
                @unlink($finalWavAbs);
                $deletedFiles[] = $video->final_audio_path;
            }

            // 5. Delete extracted audio file
            $extractedAudio = Storage::disk('local')->path("audio/extracted/{$video->id}.wav");
            if (file_exists($extractedAudio)) {
                $deletedSize += filesize($extractedAudio);
                @unlink($extractedAudio);
                $deletedFiles[] = "audio/extracted/{$video->id}.wav";
            }

            // 6. Clear video paths in database (keep dubbed_path)
            $video->update([
                'original_path' => null,
                'final_audio_path' => null,
            ]);

            Log::info('Cleanup after dubbing complete', [
                'video_id' => $video->id,
                'deleted_files' => $deletedFiles,
                'freed_space_mb' => round($deletedSize / 1024 / 1024, 2),
            ]);

        } catch (\Throwable $e) {
            Log::warning('Cleanup after dubbing failed (non-fatal)', [
                'video_id' => $video->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(): void
    {
        $lock = Cache::lock("video:{$this->videoId}:replace_audio", 1800);
        if (! $lock->get()) return;

        try {
            /** @var Video $video */
            $video = Video::query()->findOrFail($this->videoId);

            $origMp4Rel = $video->original_path;
            if (!$origMp4Rel) {
                throw new \RuntimeException("Video original_path is NULL for video {$video->id}");
            }

            $origMp4Abs = Storage::disk('local')->path($origMp4Rel);
            if (!file_exists($origMp4Abs)) {
                throw new \RuntimeException("Original MP4 not found: {$origMp4Rel}");
            }

            $finalWavRel = $video->final_audio_path ?: "audio/final/{$video->id}.wav";
            $finalWavAbs = Storage::disk('local')->path($finalWavRel);
            if (!file_exists($finalWavAbs) || filesize($finalWavAbs) < 5000) {
                throw new \RuntimeException("Final WAV not found/invalid: {$finalWavRel}");
            }

            $outRel = "videos/dubbed/{$video->id}_" . Str::random(32) . ".mp4";
            $outAbs = Storage::disk('local')->path($outRel);

            @mkdir(dirname($outAbs), 0777, true);
            @unlink($outAbs);

            // Notes:
            // - Only mapped streams are kept, so no need for -map -0:a.
            // - Drop subs/data: -sn -dn
            // - Drop global metadata/chapters: -map_metadata -1 -map_chapters -1
            // - Force audio to default: -disposition:a:0 default
            // - Tag language: -metadata:s:a:0 language=uzb
            $cmd = sprintf(
                'ffmpeg -y -hide_banner -loglevel error ' .
                '-i %s -i %s ' .
                '-map 0:v:0 -map 1:a:0 ' .
                '-sn -dn -map_metadata -1 -map_chapters -1 ' .
                '-c:v copy ' .
                '-c:a aac -b:a 192k -ar 48000 -ac 2 ' .
                '-disposition:a:0 default -metadata:s:a:0 language=uzb ' .
                '-shortest -movflags +faststart %s 2>&1',
                escapeshellarg($origMp4Abs),
                escapeshellarg($finalWavAbs),
                escapeshellarg($outAbs)
            );

            exec($cmd, $out, $code);

            if ($code !== 0 || !file_exists($outAbs) || filesize($outAbs) < 5000) {
                Log::error('ReplaceVideoAudioJob ffmpeg failed', [
                    'video_id' => $video->id,
                    'exit_code' => $code,
                    'stderr' => implode("\n", array_slice($out ?? [], -120)),
                    'cmd' => $cmd,
                ]);
                throw new \RuntimeException("Failed to mux final audio into MP4 for video {$video->id}");
            }

            // ---------- Verification (NOT md5) ----------
            // Extract audio from output mp4 and compare to finalWav using an RMS difference heuristic.
            // AAC encoding will change bits, but content should remain similar.
            $tmpExtract = "/tmp/out_audio_{$video->id}_" . Str::random(8) . ".wav";
            @unlink($tmpExtract);

            $extractCmd = sprintf(
                'ffmpeg -y -hide_banner -loglevel error -i %s -vn -ac 2 -ar 48000 -c:a pcm_s16le %s 2>&1',
                escapeshellarg($outAbs),
                escapeshellarg($tmpExtract)
            );
            exec($extractCmd, $eOut, $eCode);

            if ($eCode === 0 && file_exists($tmpExtract) && filesize($tmpExtract) > 5000) {
                // Compute RMS difference in a stable way:
                // subtract extracted from final (via amix with inverted phase) and measure RMS.
                $diffCmd = sprintf(
                    'ffmpeg -hide_banner -loglevel error -i %s -i %s ' .
                    '-filter_complex "[0:a]aresample=48000[a0];[1:a]aresample=48000[a1];[a1]volume=-1[a1n];[a0][a1n]amix=inputs=2:normalize=0,astats=reset=1" ' .
                    '-f null - 2>&1',
                    escapeshellarg($finalWavAbs),
                    escapeshellarg($tmpExtract)
                );
                exec($diffCmd, $dOut, $dCode);

                $joined = implode("\n", $dOut ?? []);
                $rms = null;
                if (preg_match('/RMS difference:\s*([0-9\.]+)/i', $joined, $m)) {
                    $rms = (float) $m[1];
                }

                Log::info('ReplaceVideoAudioJob verify', [
                    'video_id' => $video->id,
                    'rms_difference' => $rms,
                ]);

                // If it looks wildly different, log it (do not fail hard — some ffmpeg builds output NaN).
                if ($rms !== null && $rms > 0.05) {
                    Log::warning('Dubbed MP4 audio differs significantly from final wav', [
                        'video_id' => $video->id,
                        'rms_difference' => $rms,
                    ]);
                }

                @unlink($tmpExtract);
            } else {
                Log::warning('Could not extract audio from dubbed mp4 for verification', [
                    'video_id' => $video->id,
                    'exit_code' => $eCode,
                    'stderr' => implode("\n", array_slice($eOut ?? [], -80)),
                ]);
            }

            // Final probe
            $probeCmd = sprintf(
                'ffprobe -hide_banner -loglevel error -show_streams -select_streams a %s 2>&1',
                escapeshellarg($outAbs)
            );
            exec($probeCmd, $probeOut, $probeCode);

            if ($probeCode !== 0) {
                Log::warning('ffprobe failed on dubbed mp4', [
                    'video_id' => $video->id,
                    'stderr' => implode("\n", array_slice($probeOut ?? [], -80)),
                ]);
            }

            $video->update([
                'dubbed_path' => $outRel,
                'status' => 'dubbed_complete',
            ]);

            // Auto-delete original video and intermediate files to save storage
            // TEMPORARILY DISABLED FOR DEBUGGING
            // if (config('dubber.cleanup.delete_after_dubbing', true)) {
            //     $this->cleanupOriginalFiles($video, $origMp4Abs, $finalWavAbs);
            // }
        } finally {
            optional($lock)->release();
        }
    }
}
