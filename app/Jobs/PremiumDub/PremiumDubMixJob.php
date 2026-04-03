<?php

namespace App\Jobs\PremiumDub;

use App\Services\Xtts\XttsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PremiumDubMixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public string $dubId,
    ) {}

    public function handle(): void
    {
        $session = $this->getSession();
        $segments = $session['translated_segments'] ?? [];
        $noVocalsPath = $session['no_vocals_path'] ?? null;
        $videoPath = $session['video_path'] ?? null;
        $ttsDir = $session['tts_dir'] ?? null;

        if (!$ttsDir || !$videoPath) {
            $this->updateStatus('error', 'Missing files for mixing');
            return;
        }

        $this->updateStatus('mixing', 'Mixing dubbed audio with background...');

        $workDir = storage_path("app/premium-dub/{$this->dubId}");
        $dubbedAudioPath = "{$workDir}/dubbed_audio.wav";
        $finalVideoPath = "{$workDir}/dubbed_video.mp4";

        try {
            // 1. Get video duration
            $videoDuration = (float) ($session['video_duration'] ?? 0);
            if ($videoDuration <= 0) {
                $probe = Process::timeout(10)->run([
                    'ffprobe', '-hide_banner', '-loglevel', 'error',
                    '-show_entries', 'format=duration',
                    '-of', 'default=nw=1:nk=1', $videoPath,
                ]);
                $videoDuration = (float) trim($probe->output());
            }

            // 2. Create a silent base track matching video duration
            $basePath = "{$workDir}/base_silent.wav";
            Process::timeout(30)->run([
                'ffmpeg', '-y', '-f', 'lavfi',
                '-i', "anullsrc=r=44100:cl=stereo",
                '-t', (string) round($videoDuration, 3),
                '-c:a', 'pcm_s16le', $basePath,
            ]);

            // 3. Overlay each TTS segment onto the base track at correct timing
            $filterParts = [];
            $inputs = ['-i', $basePath];
            $inputIdx = 1;

            foreach ($segments as $i => $seg) {
                $ttsFile = "{$ttsDir}/{$i}.wav";
                if (!file_exists($ttsFile) || filesize($ttsFile) < 200) {
                    $ttsFile = "{$ttsDir}/{$i}.mp3"; // fallback
                }
                if (!file_exists($ttsFile) || filesize($ttsFile) < 200) continue;

                $inputs[] = '-i';
                $inputs[] = $ttsFile;

                $startMs = (int) round(($seg['start'] ?? 0) * 1000);
                $filterParts[] = "[{$inputIdx}:a]adelay={$startMs}|{$startMs},aresample=44100[tts{$i}]";
                $inputIdx++;
            }

            if (empty($filterParts)) {
                $this->updateStatus('error', 'No TTS segments to mix');
                return;
            }

            // Mix all TTS segments together
            $ttsLabels = [];
            foreach (array_keys($filterParts) as $idx) {
                // Extract label from filter
                preg_match('/\[(tts\d+)\]$/', $filterParts[$idx], $m);
                $ttsLabels[] = "[{$m[1]}]";
            }

            $filterComplex = implode(";", $filterParts);
            $filterComplex .= ";" . implode("", $ttsLabels) . "amix=inputs=" . count($ttsLabels) . ":normalize=0[dubbed]";

            // 4. If we have no_vocals (Demucs output), mix it with dubbed vocals
            if ($noVocalsPath && file_exists($noVocalsPath)) {
                $inputs[] = '-i';
                $inputs[] = $noVocalsPath;
                $bgIdx = $inputIdx;

                // Background at full volume (it's already clean — no speech)
                $filterComplex .= ";[{$bgIdx}:a]aresample=44100[bg]";
                $filterComplex .= ";[dubbed][bg]amix=inputs=2:duration=first:normalize=0[final]";
                $mapLabel = '[final]';
            } else {
                $mapLabel = '[dubbed]';
            }

            // Build ffmpeg command
            $cmd = array_merge(
                ['ffmpeg', '-y'],
                $inputs,
                [
                    '-filter_complex', $filterComplex,
                    '-map', $mapLabel,
                    '-ac', '2', '-ar', '44100', '-c:a', 'pcm_s16le',
                    $dubbedAudioPath,
                ]
            );

            $result = Process::timeout(300)->run($cmd);

            if (!$result->successful() || !file_exists($dubbedAudioPath)) {
                Log::error("[PREMIUM] [{$this->dubId}] Audio mix failed: " . Str::limit($result->errorOutput(), 500));
                $this->updateStatus('error', 'Audio mixing failed');
                return;
            }

            @unlink($basePath);

            // 5. Merge dubbed audio onto video
            $this->updateStatus('muxing', 'Creating final video...');

            $result = Process::timeout(300)->run([
                'ffmpeg', '-y',
                '-i', $videoPath,
                '-i', $dubbedAudioPath,
                '-c:v', 'copy',
                '-c:a', 'aac', '-b:a', '192k',
                '-map', '0:v:0', '-map', '1:a:0',
                '-shortest',
                $finalVideoPath,
            ]);

            if (!$result->successful() || !file_exists($finalVideoPath)) {
                Log::error("[PREMIUM] [{$this->dubId}] Muxing failed: " . Str::limit($result->errorOutput(), 500));
                $this->updateStatus('error', 'Video muxing failed');
                return;
            }

            // 6. Cleanup intermediate files
            @unlink($dubbedAudioPath);

            // 7. Cleanup XTTS cloned voices
            $clonedVoices = $session['cloned_voices'] ?? [];
            if (!empty($clonedVoices)) {
                $client = new XttsClient();
                foreach ($clonedVoices as $voiceId) {
                    try { $client->deleteVoice($voiceId); } catch (\Throwable) {}
                }
            }

            $this->updateSession([
                'status' => 'complete',
                'progress' => 'Done!',
                'final_video_path' => $finalVideoPath,
                'final_video_size' => filesize($finalVideoPath),
            ]);

            Log::info("[PREMIUM] [{$this->dubId}] Premium dub complete: " . round(filesize($finalVideoPath) / 1024 / 1024, 1) . " MB");

        } catch (\Throwable $e) {
            $this->updateStatus('error', 'Mixing failed: ' . Str::limit($e->getMessage(), 100));
            Log::error("[PREMIUM] [{$this->dubId}] PremiumDubMixJob failed: " . $e->getMessage());
        }
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
        $this->updateStatus('error', 'Mixing failed');
        Log::error("[PREMIUM] [{$this->dubId}] PremiumDubMixJob failed: " . $e->getMessage());
    }
}
