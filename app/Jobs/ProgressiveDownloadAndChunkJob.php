<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

class ProgressiveDownloadAndChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 2;

    public function __construct(
        public string $sessionId,
        public string $url,
        public string $targetLanguage,
        public string $ttsDriver,
    ) {}

    public function handle(): void
    {
        $sessionKey = "progressive:{$this->sessionId}";

        if ($this->isAborted()) {
            return;
        }

        $this->updateSession(['status' => 'downloading']);

        $tempDir = storage_path("app/temp/progressive/{$this->sessionId}");
        @mkdir($tempDir, 0755, true);

        $rawPath = "{$tempDir}/raw_audio.wav";
        $wavPath = "{$tempDir}/audio.wav";

        try {
            // Download audio-only via yt-dlp
            $cookiesFile = $this->findCookiesFile();
            $cmd = [
                'yt-dlp',
                '-f', 'bestaudio[ext=m4a]/bestaudio',
                '-x', '--audio-format', 'wav',
                '-o', $rawPath,
                '--no-playlist',
                '--no-warnings',
                '--force-ipv4',
            ];

            if ($cookiesFile) {
                $cmd[] = '--cookies';
                $cmd[] = $cookiesFile;
            }

            $cmd[] = $this->url;

            $result = Process::timeout(300)->run($cmd);

            if (!$result->successful() || !file_exists($rawPath)) {
                Log::error('yt-dlp audio download failed', [
                    'session_id' => $this->sessionId,
                    'stderr' => mb_substr($result->errorOutput(), 0, 500),
                ]);
                $this->updateSession(['status' => 'error']);
                return;
            }

            // Convert to 16kHz mono WAV for WhisperX
            $convertResult = Process::timeout(120)->run([
                'ffmpeg', '-y', '-i', $rawPath,
                '-ar', '16000', '-ac', '1', '-c:a', 'pcm_s16le',
                $wavPath,
            ]);

            @unlink($rawPath);

            if (!$convertResult->successful() || !file_exists($wavPath)) {
                Log::error('Audio conversion failed', ['session_id' => $this->sessionId]);
                $this->updateSession(['status' => 'error']);
                return;
            }

            // Get duration
            $duration = $this->getAudioDuration($wavPath);

            if ($duration <= 0) {
                Log::error('Invalid audio duration', ['session_id' => $this->sessionId]);
                $this->updateSession(['status' => 'error']);
                return;
            }

            // Split into 12s chunks
            $chunkDuration = 12;
            $totalChunks = (int) ceil($duration / $chunkDuration);

            $this->updateSession([
                'status' => 'processing',
                'total_chunks' => $totalChunks,
            ]);

            Log::info('Progressive: splitting audio into chunks', [
                'session_id' => $this->sessionId,
                'duration' => $duration,
                'total_chunks' => $totalChunks,
            ]);

            for ($i = 0; $i < $totalChunks; $i++) {
                if ($this->isAborted()) {
                    return;
                }

                $offset = $i * $chunkDuration;
                $chunkPath = "{$tempDir}/chunk_{$i}.wav";

                $splitResult = Process::timeout(30)->run([
                    'ffmpeg', '-y',
                    '-ss', (string) $offset,
                    '-t', (string) $chunkDuration,
                    '-i', $wavPath,
                    '-c:a', 'pcm_s16le',
                    $chunkPath,
                ]);

                if (!$splitResult->successful() || !file_exists($chunkPath)) {
                    Log::warning('Failed to split chunk', [
                        'session_id' => $this->sessionId,
                        'chunk' => $i,
                    ]);
                    continue;
                }

                $chunkEnd = min($offset + $chunkDuration, $duration);

                ProcessProgressiveChunkJob::dispatch(
                    $this->sessionId,
                    $i,
                    $chunkPath,
                    (float) $offset,
                    $chunkEnd - $offset,
                    $this->targetLanguage,
                    $this->ttsDriver,
                )->onQueue('progressive');
            }

            // Clean up full audio file (chunks are cleaned after processing)
            @unlink($wavPath);

        } catch (\Throwable $e) {
            Log::error('Progressive download failed', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            $this->updateSession(['status' => 'error']);
        }
    }

    private function isAborted(): bool
    {
        $json = Redis::get("progressive:{$this->sessionId}");
        if (!$json) return true;
        $session = json_decode($json, true);
        return ($session['status'] ?? '') === 'stopped';
    }

    private function updateSession(array $updates): void
    {
        $json = Redis::get("progressive:{$this->sessionId}");
        if (!$json) return;

        $session = json_decode($json, true);
        $session = array_merge($session, $updates);
        Redis::setex("progressive:{$this->sessionId}", 14400, json_encode($session));
    }

    private function getAudioDuration(string $path): float
    {
        $result = Process::timeout(10)->run([
            'ffprobe', '-hide_banner', '-loglevel', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1',
            $path,
        ]);

        return (float) trim($result->output());
    }

    private function findCookiesFile(): ?string
    {
        $paths = [
            base_path('cookies.txt'),
            storage_path('app/cookies.txt'),
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && filesize($path) > 100) {
                return $path;
            }
        }

        return null;
    }
}
