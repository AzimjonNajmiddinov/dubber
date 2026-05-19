<?php

namespace App\Jobs;

use App\Support\DubSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Downloads one time-window of a YouTube video using yt-dlp --download-sections.
 * Dispatches DownloadAudioChunkJob for each 30-second slice immediately on success,
 * enabling FIFO processing: early segments start TTS while later windows are still downloading.
 */
class DownloadYouTubeWindowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min per window (network-bound)
    public int $tries   = 2;

    const CHUNK_SIZE = 30.0; // seconds per TTS segment

    public function __construct(
        public string $sessionId,
        public string $videoUrl,
        public int    $windowIndex,
        public float  $windowStart,
        public float  $windowEnd,
    ) {}

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['status'] ?? '') === 'stopped') return;

        $workDir    = storage_path("app/instant-dub/{$this->sessionId}");
        @mkdir($workDir, 0755, true);
        $windowBase = "{$workDir}/yt_window_{$this->windowIndex}";

        $startTs = $this->formatTimestamp($this->windowStart);
        $endTs   = $this->formatTimestamp($this->windowEnd);

        $result = Process::timeout(1800)->run([
            'yt-dlp',
            '--download-sections', "*{$startTs}-{$endTs}",
            '-f', 'bestaudio[ext=m4a]/bestaudio',
            '-o', $windowBase . '.%(ext)s',
            '--no-playlist', '--quiet', '--no-warnings',
            '--extractor-args', 'youtube:player_client=web_creator,mweb,ios',
            $this->videoUrl,
        ]);

        $windowFile = $this->findWindowFile($windowBase);

        if (!$windowFile) {
            Log::warning("[DUB] YouTube window {$this->windowIndex} ({$startTs}-{$endTs}) download failed", [
                'session' => $this->sessionId,
                'error'   => Str::limit($result->errorOutput(), 500),
            ]);
            return;
        }

        Log::info("[DUB] YouTube window {$this->windowIndex} ready ({$startTs}-{$endTs}, " . round(filesize($windowFile) / 1024) . ' KB)', [
            'session' => $this->sessionId,
        ]);

        // Dispatch a DownloadAudioChunkJob for every 30s slice in this window
        $chunkStart = $this->windowStart;
        while ($chunkStart < $this->windowEnd) {
            $chunkEnd = min($chunkStart + self::CHUNK_SIZE, $this->windowEnd);
            $chunkIdx = (int) round($chunkStart / self::CHUNK_SIZE);

            DownloadAudioChunkJob::dispatch(
                $this->sessionId,
                $chunkIdx,
                $chunkStart,
                $chunkEnd,
                $windowFile,
                $this->windowStart,
            )->onQueue('default');

            $chunkStart = $chunkEnd;
        }
    }

    private function findWindowFile(string $base): ?string
    {
        foreach (['m4a', 'webm', 'opus', 'ogg', 'mp3', 'aac'] as $ext) {
            $path = "{$base}.{$ext}";
            if (file_exists($path) && filesize($path) > 1000) {
                return $path;
            }
        }
        return null;
    }

    private function formatTimestamp(float $seconds): string
    {
        $h = (int) ($seconds / 3600);
        $m = (int) (($seconds % 3600) / 60);
        $s = (int) ($seconds % 60);
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[DUB] DownloadYouTubeWindowJob {$this->windowIndex} killed: " . Str::limit($exception->getMessage(), 200), [
            'session' => $this->sessionId,
        ]);
    }
}
