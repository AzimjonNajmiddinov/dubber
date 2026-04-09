<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class DownloadAudioChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
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

        // Use a lock to prevent concurrent chunk jobs from overwriting each other's bg_chunks entry
        $lock = \Illuminate\Support\Facades\Cache::lock("instant-dub:{$this->sessionId}:bg-lock", 10);
        $lock->block(10, function () use ($sessionKey, $chunkFile) {
            $sessionJson = Redis::get($sessionKey);
            if (!$sessionJson) return;
            $s = json_decode($sessionJson, true);
            $s['original_audio_path'] = $chunkFile;
            $bgChunks = $s['bg_chunks'] ?? [];
            $bgChunks[$this->chunkIndex] = [
                'path'  => $chunkFile,
                'start' => $this->startTime,
                'end'   => $this->endTime,
            ];
            $s['bg_chunks'] = $bgChunks;
            Redis::setex($sessionKey, 50400, json_encode($s));
        });

        Log::info("[DUB] Audio chunk {$this->chunkIndex} ready (" . round($this->startTime) . "-" . round($this->endTime) . "s, " . round(filesize($chunkFile) / 1024) . " KB)", [
            'session' => $this->sessionId,
        ]);

        $this->generateBgChunkAac($chunkFile);

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
            if ready >= total and hasBg and session['status'] ~= 'complete' then
                session['status'] = 'complete'
                session['playable'] = true
                redis.call('SETEX', KEYS[1], 50400, cjson.encode(session))
            elseif not session['playable'] and hasBg and ready >= math.min(math.ceil(total * 0.1), 30) then
                session['playable'] = true
                redis.call('SETEX', KEYS[1], 50400, cjson.encode(session))
            end
            return ready
        LUA;

        Redis::eval($lua, 1, "instant-dub:{$this->sessionId}");
    }

    /**
     * Generate bg-{chunkIndex}.aac for this chunk's full time range.
     * Contains background audio at 0.2 volume + any TTS segments in range mixed via adelay.
     * Called when the bg chunk downloads. ProcessInstantDubSegmentJob calls it again after TTS arrives.
     */
    public function generateBgChunkAac(string $bgAudioPath): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;

        $session  = json_decode($sessionJson, true);
        $total    = (int) ($session['total_segments'] ?? 0);
        $aacDir   = storage_path("app/instant-dub/{$this->sessionId}/aac");
        if (!is_dir($aacDir)) @mkdir($aacDir, 0755, true);

        $chunkDur = round($this->frameAlignedDuration($this->startTime, $this->endTime), 6);
        $outFile  = "{$aacDir}/bg-{$this->chunkIndex}.aac";

        $cmd      = [
            'ffmpeg', '-y',
            '-f', 'lavfi', '-t', (string) $chunkDur, '-i', 'anullsrc=r=44100:cl=mono',
            '-t', (string) $chunkDur, '-i', $bgAudioPath,
        ];
        $filters   = ["[1:a]volume=0.2,aresample=44100[bg]"];
        $mixInputs = ['[0:a]', '[bg]'];
        $inputIdx  = 2;
        $tmpFiles  = [];

        for ($i = 0; $i < $total; $i++) {
            $chunkJson = Redis::get("{$sessionKey}:chunk:{$i}");
            if (!$chunkJson) continue;
            $chunk    = json_decode($chunkJson, true);
            $segStart = (float) ($chunk['start_time'] ?? 0);
            $segEnd   = (float) ($chunk['end_time'] ?? 0);
            $b64      = $chunk['audio_base64'] ?? null;

            if ($segStart >= $this->endTime || $segEnd <= $this->startTime) continue;
            if (!$b64) continue;

            $tmpMp3 = "/tmp/bggen_{$this->sessionId}_{$this->chunkIndex}_{$i}.mp3";
            file_put_contents($tmpMp3, base64_decode($b64));
            $tmpFiles[] = $tmpMp3;

            $ttsSeek    = max(0.0, $this->startTime - $segStart);
            $ttsDelayMs = (int) round(max(0.0, $segStart - $this->startTime) * 1000);

            $cmd[] = '-ss';
            $cmd[] = (string) round($ttsSeek, 3);
            $cmd[] = '-i';
            $cmd[] = $tmpMp3;
            $filters[]   = "[{$inputIdx}:a]adelay={$ttsDelayMs}|{$ttsDelayMs},aresample=44100[tts{$inputIdx}]";
            $mixInputs[] = "[tts{$inputIdx}]";
            $inputIdx++;
        }

        $filter = implode(';', $filters) . ';' . implode('', $mixInputs)
            . "amix=inputs={$inputIdx}:duration=first:normalize=0";

        $cmd = array_merge($cmd, [
            '-filter_complex', $filter,
            '-ac', '1', '-c:a', 'aac', '-b:a', '96k', '-f', 'adts', $outFile,
        ]);

        $timeout = max(20, (int) ceil($chunkDur) + 10);
        $result  = Process::timeout($timeout)->run($cmd);

        foreach ($tmpFiles as $f) @unlink($f);

        if ($result->successful()) {
            Log::info("[DUB] bg-{$this->chunkIndex}.aac ready (" . round($this->startTime) . "-" . round($this->endTime) . "s, tts=" . ($inputIdx - 2) . ")", [
                'session' => $this->sessionId,
            ]);
        } else {
            Log::warning("[DUB] bg-{$this->chunkIndex}.aac failed", [
                'session' => $this->sessionId,
                'error'   => Str::limit($result->errorOutput(), 300),
            ]);
        }
    }

    private function frameAlignedDuration(float $start, float $end): float
    {
        $startFrames = (int) round($start * 44100 / 1024);
        $endFrames = (int) round($end * 44100 / 1024);
        return max(1, $endFrames - $startFrames) * 1024 / 44100;
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning("[DUB] DownloadAudioChunkJob {$this->chunkIndex} failed", [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
