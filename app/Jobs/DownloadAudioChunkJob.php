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

        $segmentsJson = Redis::get("instant-dub:{$this->sessionId}:audio-segments");
        if (!$segmentsJson) return;

        $allSegments = json_decode($segmentsJson, true);

        // Find .ts segments within our time range
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

        $tmpPlaylist = "{$workDir}/chunk_{$this->chunkIndex}.m3u8";
        $m3u8 = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXT-X-MEDIA-SEQUENCE:0\n";
        foreach ($tsUrls as $url) {
            $m3u8 .= "#EXTINF:10.0,\n{$url}\n";
        }
        $m3u8 .= "#EXT-X-ENDLIST\n";
        file_put_contents($tmpPlaylist, $m3u8);

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

        // Store chunk in session
        $sessionJson = Redis::get($sessionKey);
        if ($sessionJson) {
            $s = json_decode($sessionJson, true);
            $s['original_audio_path'] = $chunkFile;
            $bgChunks = $s['bg_chunks'] ?? [];
            $bgChunks[$this->chunkIndex] = [
                'path' => $chunkFile,
                'start' => $this->startTime,
                'end' => $this->endTime,
            ];
            $s['bg_chunks'] = $bgChunks;
            Redis::setex($sessionKey, 50400, json_encode($s));
        }

        Log::info("[DUB] Audio chunk {$this->chunkIndex} ready (" . round($this->startTime) . "-" . round($this->endTime) . "s, " . round(filesize($chunkFile) / 1024) . " KB)", [
            'session' => $this->sessionId,
        ]);

        $this->remixSegmentsInRange($chunkFile);

        // Check if all TTS segments are ready + bg is now available → set complete/playable
        $this->checkAndSetComplete();
    }

    private function checkAndSetComplete(): void
    {
        $lua = <<<'LUA'
            local data = redis.call('GET', KEYS[1])
            if not data then return 0 end
            local session = cjson.decode(data)
            local ready = session['segments_ready'] or 0
            local total = session['total_segments'] or 999999
            local hasBg = session['bg_chunks'] ~= nil and next(session['bg_chunks']) ~= nil
            if not hasBg then return ready end
            if ready >= total and session['status'] ~= 'complete' then
                session['status'] = 'complete'
                session['playable'] = true
                redis.call('SETEX', KEYS[1], 50400, cjson.encode(session))
            elseif not session['playable'] and ready >= math.min(math.ceil(total * 0.1), 30) then
                session['playable'] = true
                redis.call('SETEX', KEYS[1], 50400, cjson.encode(session))
            end
            return ready
        LUA;

        Redis::eval($lua, 1, "instant-dub:{$this->sessionId}");
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

        // Generate lead.aac for chunk 0
        if ($this->chunkIndex === 0) {
            $chunk0Json = Redis::get("{$sessionKey}:chunk:0");
            if ($chunk0Json) {
                $chunk0 = json_decode($chunk0Json, true);
                $firstStart = (float) ($chunk0['start_time'] ?? 0);
                if ($firstStart > 1.0) {
                    $leadFile = "{$aacDir}/lead.aac";
                    $leadDur = round($firstStart, 3);
                    // Use anullsrc as base to guarantee exact duration (ADTS trims silence)
                    Process::timeout(30)->run([
                        'ffmpeg', '-y',
                        '-f', 'lavfi', '-t', (string) $leadDur, '-i', 'anullsrc=r=44100:cl=mono',
                        '-ss', '0', '-t', (string) $leadDur, '-i', $bgAudioPath,
                        '-filter_complex',
                        "[1:a]volume=0.2[bg];[0:a][bg]amix=inputs=2:duration=first:normalize=0",
                        '-ac', '1', '-c:a', 'aac', '-b:a', '64k', '-f', 'mp4', '-movflags', '+frag_keyframe+empty_moov+default_base_moof', $leadFile,
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

            if ($segStart < $this->startTime || $segStart >= $this->endTime) continue;

            $audioBase64 = $chunk['audio_base64'] ?? null;
            if (!$audioBase64) continue;

            $aacFile = "{$aacDir}/{$i}.aac";
            $seekInBg = max(0, $segStart - $this->startTime);

            // Compute full slot duration (speech + gap until next segment)
            $nextChunkJson = Redis::get("{$sessionKey}:chunk:" . ($i + 1));
            $nextStart = $nextChunkJson
                ? (float) (json_decode($nextChunkJson, true)['start_time'] ?? $segEnd)
                : $segEnd;
            $slotDuration = round(max(0.1, $nextStart - $segStart), 3);

            $tmpMp3 = "/tmp/remix_{$this->sessionId}_{$i}.mp3";
            file_put_contents($tmpMp3, base64_decode($audioBase64));

            // ONE segment: TTS overlaid on background for full slot duration
            // Real background audio preserves ADTS duration (no silent trim issue)
            $result = Process::timeout(20)->run([
                'ffmpeg', '-y',
                '-ss', (string) round($seekInBg, 3), '-t', (string) $slotDuration, '-i', $bgAudioPath,
                '-i', $tmpMp3,
                '-filter_complex',
                "[0:a]volume=0.2,aresample=44100[bg];[1:a]aresample=44100[tts];[bg][tts]amix=inputs=2:duration=first:normalize=0",
                '-t', (string) $slotDuration,
                '-ac', '1', '-c:a', 'aac', '-b:a', '128k', '-f', 'mp4', '-movflags', '+frag_keyframe+empty_moov+default_base_moof', $aacFile,
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
