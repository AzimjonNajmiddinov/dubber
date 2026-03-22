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
use Illuminate\Support\Str;

class DownloadAudioChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(
        public string $sessionId,
        public int    $chunkIndex,
        public float  $startTime,
        public float  $endTime,
    ) {}

    public function handle(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;

        $session = json_decode($sessionJson, true);
        if (($session['status'] ?? '') === 'stopped') return;

        // Get all audio segments from Redis
        $segmentsJson = Redis::get("instant-dub:{$this->sessionId}:audio-segments");
        if (!$segmentsJson) return;

        $allSegments = json_decode($segmentsJson, true);

        // Find which .ts segments fall within our time range
        $currentTime = 0;
        $tsUrls = [];
        foreach ($allSegments as $seg) {
            $segEnd = $currentTime + $seg['duration'];
            if ($segEnd > $this->startTime && $currentTime < $this->endTime) {
                $tsUrls[] = $seg['url'];
            }
            $currentTime = $segEnd;
            if ($currentTime >= $this->endTime) break;
        }

        if (empty($tsUrls)) return;

        $workDir = storage_path("app/instant-dub/{$this->sessionId}");
        @mkdir($workDir, 0755, true);

        $chunkFile = "{$workDir}/bg_chunk_{$this->chunkIndex}.aac";

        // Write a temporary playlist with just our segments
        $tmpPlaylist = "{$workDir}/chunk_{$this->chunkIndex}.m3u8";
        $m3u8 = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXT-X-MEDIA-SEQUENCE:0\n";
        foreach ($tsUrls as $url) {
            $m3u8 .= "#EXTINF:10.0,\n{$url}\n";
        }
        $m3u8 .= "#EXT-X-ENDLIST\n";
        file_put_contents($tmpPlaylist, $m3u8);

        // Download and convert to AAC
        $result = Process::timeout(90)->run([
            'ffmpeg', '-y',
            '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
            '-i', $tmpPlaylist,
            '-vn', '-ac', '1', '-ar', '44100',
            '-c:a', 'aac', '-b:a', '96k',
            '-f', 'adts', $chunkFile,
        ]);

        @unlink($tmpPlaylist);

        if (!$result->successful() || !file_exists($chunkFile) || filesize($chunkFile) < 100) {
            Log::warning("[DUB] Audio chunk {$this->chunkIndex} download failed", [
                'session' => $this->sessionId,
                'error' => Str::limit($result->errorOutput(), 200),
            ]);
            return;
        }

        // Get actual duration of downloaded chunk
        $probe = Process::timeout(10)->run([
            'ffprobe', '-hide_banner', '-loglevel', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1', $chunkFile,
        ]);
        $chunkDuration = $probe->successful() ? (float) trim($probe->output()) : ($this->endTime - $this->startTime);

        // Store chunk path in session
        $sessionJson = Redis::get($sessionKey);
        if ($sessionJson) {
            $s = json_decode($sessionJson, true);
            $s['original_audio_path'] = $chunkFile; // latest chunk path for new segments
            $bgChunks = $s['bg_chunks'] ?? [];
            $bgChunks[$this->chunkIndex] = [
                'path' => $chunkFile,
                'start' => $this->startTime,
                'end' => $this->startTime + $chunkDuration,
            ];
            $s['bg_chunks'] = $bgChunks;
            Redis::setex($sessionKey, 50400, json_encode($s));
        }

        Log::info("[DUB] Audio chunk {$this->chunkIndex} ready (" . round($this->startTime) . "-" . round($this->startTime + $chunkDuration) . "s, " . round(filesize($chunkFile) / 1024) . " KB)", [
            'session' => $this->sessionId,
        ]);

        // Remix TTS segments that fall within this chunk's time range
        $this->remixSegmentsInRange($chunkFile);
    }

    private function remixSegmentsInRange(string $bgAudioPath): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;

        $session = json_decode($sessionJson, true);
        $total = (int) ($session['total_segments'] ?? 0);
        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");

        if (!is_dir($aacDir)) @mkdir($aacDir, 0755, true);

        $remixed = 0;

        // Also generate lead.aac if chunk 0 and first segment starts late
        if ($this->chunkIndex === 0) {
            $chunk0Json = Redis::get("{$sessionKey}:chunk:0");
            if ($chunk0Json) {
                $chunk0 = json_decode($chunk0Json, true);
                $firstStart = (float) ($chunk0['start_time'] ?? 0);
                if ($firstStart > 1.0) {
                    $leadFile = "{$aacDir}/lead.aac";
                    Process::timeout(30)->run([
                        'ffmpeg', '-y',
                        '-i', $bgAudioPath,
                        '-ss', '0', '-t', (string) round($firstStart, 3),
                        '-af', 'volume=0.2',
                        '-ac', '1', '-ar', '44100', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $leadFile,
                    ]);
                }
            }
        }

        for ($i = 0; $i < $total; $i++) {
            $chunkJson = Redis::get("{$sessionKey}:chunk:{$i}");
            if (!$chunkJson) continue;

            $chunk = json_decode($chunkJson, true);
            $segStart = (float) ($chunk['start_time'] ?? 0);
            $segEnd = (float) ($chunk['end_time'] ?? 0);

            // Only remix segments within this chunk's time range
            if ($segStart < $this->startTime || $segStart >= $this->endTime) continue;

            $audioBase64 = $chunk['audio_base64'] ?? null;
            if (!$audioBase64) continue;

            $aacFile = "{$aacDir}/{$i}.aac";

            // Compute slot duration
            $nextChunkJson = Redis::get("{$sessionKey}:chunk:" . ($i + 1));
            $slotEnd = $nextChunkJson
                ? (float) (json_decode($nextChunkJson, true)['start_time'] ?? $segEnd)
                : $segEnd;
            $slotDuration = round(max(0.1, $slotEnd - $segStart), 3);

            // The background audio chunk starts at $this->startTime
            // We need to seek to (segStart - chunkStart) within the bg file
            $seekInBg = max(0, $segStart - $this->startTime);

            $tmpMp3 = "/tmp/remix_{$this->sessionId}_{$i}.mp3";
            file_put_contents($tmpMp3, base64_decode($audioBase64));

            $result = Process::timeout(20)->run([
                'ffmpeg', '-y',
                '-i', $tmpMp3,
                '-ss', (string) round($seekInBg, 3), '-i', $bgAudioPath,
                '-filter_complex',
                "[0:a]aresample=44100,apad=whole_dur={$slotDuration}[tts];[1:a]atrim=duration={$slotDuration},volume=0.2[bg];[tts][bg]amix=inputs=2:duration=first:normalize=0",
                '-t', (string) $slotDuration,
                '-ac', '1', '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
            ]);

            @unlink($tmpMp3);

            if ($result->successful()) {
                $remixed++;
            }
        }

        if ($remixed > 0) {
            Log::info("[DUB] Remixed {$remixed} segments with background (chunk {$this->chunkIndex})", [
                'session' => $this->sessionId,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning("[DUB] DownloadAudioChunkJob {$this->chunkIndex} failed", [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
