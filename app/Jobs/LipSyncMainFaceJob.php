<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LipSyncMainFaceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 2;

    /**
     * Exponential backoff between retries (seconds).
     */
    public array $backoff = [60, 180];

    public function __construct(public int $videoId) {}

    /**
     * Handle job failure - mark video as done without lipsync.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('LipSync failed permanently, marking video as done without lipsync', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $video = Video::find($this->videoId);
            if ($video && $video->status === 'lipsync_processing') {
                $video->update(['status' => 'dubbed_complete']);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to update video status after lipsync failure', [
                'video_id' => $this->videoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(): void
    {
        $lock = Cache::lock("video:{$this->videoId}:lipsync", 3600);
        if (! $lock->get()) return;

        try {
            /** @var Video $video */
            $video = Video::query()->findOrFail($this->videoId);

            // Input: already dubbed mp4 (Uzbek audio)
            $dubbedRel = $video->dubbed_path;
            if (!$dubbedRel || !Storage::disk('local')->exists($dubbedRel)) {
                throw new \RuntimeException("Dubbed MP4 not found (dubbed_path is empty or missing)");
            }

            // Use the same Uzbek audio as source of truth:
            // Prefer final mixed WAV; fallback: extract from dubbed mp4.
            $audioRel = $video->final_audio_path ?: "audio/final/{$video->id}.wav";
            if (!Storage::disk('local')->exists($audioRel)) {
                // fallback: extract audio from dubbed mp4 into audio/final/{id}_from_mp4.wav
                $audioRel = "audio/final/{$video->id}_from_mp4.wav";
                $audioAbs = Storage::disk('local')->path($audioRel);
                @mkdir(dirname($audioAbs), 0777, true);
                @unlink($audioAbs);

                $dubbedAbs = Storage::disk('local')->path($dubbedRel);
                $cmd = sprintf(
                    "ffmpeg -y -hide_banner -loglevel error -i %s -vn -ac 2 -ar 48000 -c:a pcm_s16le %s 2>&1",
                    escapeshellarg($dubbedAbs),
                    escapeshellarg($audioAbs)
                );
                exec($cmd, $o, $c);
                if ($c !== 0 || !file_exists($audioAbs) || filesize($audioAbs) < 5000) {
                    Log::error('LipSync audio extract failed', [
                        'video_id' => $video->id,
                        'exit_code' => $c,
                        'stderr' => implode("\n", array_slice($o ?? [], -120)),
                    ]);
                    throw new \RuntimeException("Failed to extract audio for lipsync");
                }
            }

            // Output mp4
            $outRel = "videos/lipsynced/{$video->id}_" . Str::random(24) . ".mp4";

            $video->update(['status' => 'lipsync_processing']);

            $res = Http::timeout(3600)
                ->retry(1, 1000)
                ->post('http://lipsync:8000/lipsync', [
                    'video_path' => $dubbedRel,  // REL
                    'audio_path' => $audioRel,   // REL
                    'out_path'   => $outRel,     // REL
                ]);

            $json = $res->json();

            if ($res->failed() || !is_array($json) || !($json['ok'] ?? false)) {
                Log::error('LipSync service failed', [
                    'video_id' => $video->id,
                    'http_status' => $res->status(),
                    'body' => mb_substr($res->body(), 0, 4000),
                    'json' => $json,
                ]);
                throw new \RuntimeException('LipSync request failed');
            }

            if (!Storage::disk('local')->exists($outRel)) {
                throw new \RuntimeException("LipSync output not found: {$outRel}");
            }

            // Verify output file has reasonable size
            $outAbs = Storage::disk('local')->path($outRel);
            $outSize = filesize($outAbs);
            $dubbedSize = filesize(Storage::disk('local')->path($dubbedRel));

            if ($outSize < 10000) {
                Log::error('LipSync output too small', [
                    'video_id' => $video->id,
                    'out_size' => $outSize,
                    'dubbed_size' => $dubbedSize,
                ]);
                throw new \RuntimeException("LipSync output is too small: {$outSize} bytes");
            }

            // Log success with file comparison
            Log::info('LipSync completed', [
                'video_id' => $video->id,
                'input_size' => $dubbedSize,
                'output_size' => $outSize,
                'size_ratio' => $dubbedSize > 0 ? round($outSize / $dubbedSize, 2) : null,
            ]);

            $video->update([
                'lipsynced_path' => $outRel,
                'status' => 'lipsync_done',
            ]);

        } finally {
            optional($lock)->release();
        }
    }
}
