<?php

namespace App\Jobs\PremiumDub;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

class StartPremiumDubJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public string $dubId,
        public string $videoUrl,
        public string $language,
        public string $translateFrom,
    ) {}

    public function handle(): void
    {
        $key = "premium-dub:{$this->dubId}";

        $this->updateStatus('downloading', 'Downloading video...');

        // 1. Download video
        $workDir = storage_path("app/premium-dub/{$this->dubId}");
        @mkdir($workDir, 0755, true);

        $videoPath = "{$workDir}/video.mp4";
        $audioPath = "{$workDir}/audio.wav";

        $result = Process::timeout(600)->run([
            'ffmpeg', '-y', '-i', $this->videoUrl,
            '-c', 'copy', $videoPath,
        ]);

        if (!$result->successful() || !file_exists($videoPath)) {
            $this->updateStatus('error', 'Video download failed');
            return;
        }

        // 2. Extract audio
        $this->updateStatus('extracting_audio', 'Extracting audio...');

        $result = Process::timeout(120)->run([
            'ffmpeg', '-y', '-i', $videoPath,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le',
            $audioPath,
        ]);

        if (!$result->successful() || !file_exists($audioPath)) {
            $this->updateStatus('error', 'Audio extraction failed');
            return;
        }

        // Get video duration
        $probe = Process::timeout(10)->run([
            'ffprobe', '-hide_banner', '-loglevel', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1', $videoPath,
        ]);
        $videoDuration = $probe->successful() ? (float) trim($probe->output()) : 0;

        // Store paths in session
        $this->updateSession([
            'video_path' => $videoPath,
            'audio_path' => $audioPath,
            'video_duration' => $videoDuration,
        ]);

        Log::info("[PREMIUM] [{$this->dubId}] Video downloaded, audio extracted ({$videoDuration}s)");

        // 3. Dispatch parallel jobs: Demucs + WhisperX
        PremiumDubSeparateStemsJob::dispatch($this->dubId)->onQueue('default');
        PremiumDubTranscribeJob::dispatch($this->dubId)->onQueue('default');
    }

    private function updateStatus(string $status, string $progress = ''): void
    {
        $key = "premium-dub:{$this->dubId}";
        $json = Redis::get($key);
        $session = $json ? json_decode($json, true) : [];
        $session['status'] = $status;
        if ($progress) $session['progress'] = $progress;
        Redis::setex($key, 86400, json_encode($session));
    }

    private function updateSession(array $data): void
    {
        $key = "premium-dub:{$this->dubId}";
        $json = Redis::get($key);
        $session = $json ? json_decode($json, true) : [];
        Redis::setex($key, 86400, json_encode(array_merge($session, $data)));
    }

    public function failed(\Throwable $e): void
    {
        $this->updateStatus('error', 'Start failed: ' . $e->getMessage());
        Log::error("[PREMIUM] [{$this->dubId}] StartPremiumDubJob failed: " . $e->getMessage());
    }
}
