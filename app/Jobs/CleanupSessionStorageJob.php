<?php

namespace App\Jobs;

use App\Support\DubSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Cleans up all storage files for a completed instant-dub session.
 *
 * Dispatched by PersistDubCacheJob after translations are saved to DB.
 * Delayed 5 minutes to let HLS/PlayerKit clients finish buffering any
 * in-flight AAC segments.
 *
 * Deletes:
 * - TTS audio files (storage/app/instant-dub/{sessionId}/audio/)
 * - AAC HLS chunks (storage/app/instant-dub/{sessionId}/aac/)
 * - Background audio chunks (storage/app/instant-dub/{sessionId}/bg_chunk_*.aac)
 * - YouTube window downloads (storage/app/instant-dub/{sessionId}/yt_window_*.*)
 * - Source audio (storage/app/instant-dub/{sessionId}/source_audio.m4a)
 * - Temp directory (/tmp/instant-dub-{sessionId}/)
 * - Wave-related Redis keys
 */
class CleanupSessionStorageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(public string $sessionId) {}

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId);
        $title = $session['title'] ?? 'Untitled';

        $sessionDir = storage_path("app/instant-dub/{$this->sessionId}");
        $tmpDir = '/tmp/instant-dub-' . $this->sessionId;

        $freedBytes = 0;

        // Calculate size before deletion
        if (is_dir($sessionDir)) {
            $freedBytes = $this->directorySize($sessionDir);
        }
        if (is_dir($tmpDir)) {
            $freedBytes += $this->directorySize($tmpDir);
        }

        // Delete session directory recursively
        $this->deleteDirectory($sessionDir);

        // Delete tmp directory
        $this->deleteDirectory($tmpDir);

        // Clean up wave Redis keys (progress counters, offsets, etc.)
        $this->cleanupWaveRedisKeys();

        $freedMb = round($freedBytes / 1024 / 1024, 1);
        Log::info("[DUB] [{$title}] Storage cleanup: freed {$freedMb} MB", [
            'session' => $this->sessionId,
            'path'    => $sessionDir,
        ]);
    }

    private function cleanupWaveRedisKeys(): void
    {
        $session = DubSession::get($this->sessionId);
        $totalWaves = (int) ($session['total_waves'] ?? 0);

        $keysToDelete = [
            DubSession::wavesDispatchedKey($this->sessionId),
        ];

        for ($w = 0; $w < max($totalWaves, 50); $w++) {
            $keysToDelete[] = DubSession::waveKey($this->sessionId, $w);
            $keysToDelete[] = DubSession::waveKey($this->sessionId, $w) . ':offset';
            $keysToDelete[] = DubSession::waveProgressKey($this->sessionId, $w);
            $keysToDelete[] = DubSession::waveProgressKey($this->sessionId, $w) . ':ready';
            $keysToDelete[] = "instant-dub:{$this->sessionId}:w{$w}:batches-remaining";
        }

        if (!empty($keysToDelete)) {
            Redis::del($keysToDelete);
        }
    }

    private function directorySize(string $dir): int
    {
        $size = 0;
        if (!is_dir($dir)) return 0;

        foreach (glob("{$dir}/*") ?: [] as $entry) {
            if (is_dir($entry)) {
                $size += $this->directorySize($entry);
            } else {
                $size += filesize($entry) ?: 0;
            }
        }
        return $size;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (glob("{$dir}/*") ?: [] as $entry) {
            is_dir($entry) ? $this->deleteDirectory($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning("[DUB] CleanupSessionStorageJob failed: " . $exception->getMessage(), [
            'session' => $this->sessionId,
        ]);
    }
}
