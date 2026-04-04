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
        public ?string $videoUrl,
        public string $language,
        public string $translateFrom,
    ) {}

    public function handle(): void
    {
        $session = $this->getSession();

        // If video already saved (upload path), skip download
        if (!empty($session['video_path']) && file_exists($session['video_path'])) {
            Log::info("[PREMIUM] [{$this->dubId}] Video already available, skipping download");
            PremiumDubSeparateStemsJob::dispatch($this->dubId)->onQueue('default');
            PremiumDubTranscribeJob::dispatch($this->dubId)->onQueue('default');
            return;
        }

        if (!$this->videoUrl) {
            $this->updateStatus('error', 'No video URL or file provided');
            return;
        }

        $this->updateStatus('downloading', 'Downloading video...');

        $workDir = storage_path("app/premium-dub/{$this->dubId}");
        @mkdir($workDir, 0755, true);

        $videoPath = "{$workDir}/video.mp4";
        $audioPath = "{$workDir}/audio.wav";

        // Download
        if ($this->isYouTubeUrl($this->videoUrl)) {
            $result = Process::timeout(600)->run([
                'yt-dlp',
                '--no-playlist',
                '--no-part',
                '--remux-video', 'mp4',
                '-f', 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/bestvideo+bestaudio/best',
                '-o', $videoPath,
                $this->videoUrl,
            ]);
        } else {
            $result = Process::timeout(600)->run([
                'ffmpeg', '-y', '-i', $this->videoUrl, '-c', 'copy', $videoPath,
            ]);
        }

        if (!$result->successful() || !file_exists($videoPath)) {
            $err = substr($result->errorOutput() ?: $result->output(), -500);
            $this->updateStatus('error', 'Video download failed: ' . $err);
            Log::error("[PREMIUM] [{$this->dubId}] Download failed:\n" . $result->errorOutput());
            return;
        }

        // Extract audio
        $this->updateStatus('extracting_audio', 'Extracting audio...');

        $audioResult = Process::timeout(120)->run([
            'ffmpeg', '-y', '-i', $videoPath,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le',
            $audioPath,
        ]);

        if (!$audioResult->successful() || !file_exists($audioPath)) {
            $this->updateStatus('error', 'Audio extraction failed');
            Log::error("[PREMIUM] [{$this->dubId}] Audio extraction failed:\n" . $audioResult->errorOutput());
            return;
        }

        $probe = Process::timeout(10)->run([
            'ffprobe', '-hide_banner', '-loglevel', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1', $videoPath,
        ]);
        $videoDuration = $probe->successful() ? (float) trim($probe->output()) : 0;

        $this->updateSession([
            'video_path'     => $videoPath,
            'audio_path'     => $audioPath,
            'video_duration' => $videoDuration,
        ]);

        Log::info("[PREMIUM] [{$this->dubId}] Downloaded & extracted ({$videoDuration}s)");

        PremiumDubSeparateStemsJob::dispatch($this->dubId)->onQueue('default');
        PremiumDubTranscribeJob::dispatch($this->dubId)->onQueue('default');
    }

    private function isYouTubeUrl(string $url): bool
    {
        return (bool) preg_match('/(?:youtube\.com\/watch|youtu\.be\/|youtube\.com\/shorts\/)/i', $url);
    }

    private function getSession(): array
    {
        $json = Redis::get("premium-dub:{$this->dubId}");
        return $json ? json_decode($json, true) : [];
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
