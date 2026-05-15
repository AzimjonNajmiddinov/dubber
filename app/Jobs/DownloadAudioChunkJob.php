<?php

namespace App\Jobs;

use App\Support\DubSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class DownloadAudioChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 3;

    public function __construct(
        public string  $sessionId,
        public int     $chunkIndex,
        public float   $startTime,
        public float   $endTime,
        public ?string $localAudioPath = null,
    ) {}

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['status'] ?? '') === 'stopped') return;

        $workDir = storage_path("app/instant-dub/{$this->sessionId}");
        @mkdir($workDir, 0755, true);

        $chunkFile = "{$workDir}/bg_chunk_{$this->chunkIndex}.aac";

        $result = $this->localAudioPath
            ? $this->cutFromLocal($chunkFile)
            : $this->cutFromHls($chunkFile, $session);

        if ($result === null) return; // silent early exit already logged

        if (!$result->successful() || !file_exists($chunkFile) || filesize($chunkFile) < 100) {
            Log::warning("[DUB] Audio chunk {$this->chunkIndex} download failed", [
                'session' => $this->sessionId,
                'error'   => Str::limit($result->errorOutput(), 200),
            ]);
            return;
        }

        $this->storeBgChunk($chunkFile);

        Log::info(
            "[DUB] Audio chunk {$this->chunkIndex} ready ("
            . round($this->startTime) . '-' . round($this->endTime) . 's, '
            . round(filesize($chunkFile) / 1024) . ' KB)',
            ['session' => $this->sessionId]
        );

        GenerateBgChunkJob::dispatch($this->sessionId, $this->chunkIndex, $this->startTime, $this->endTime)
            ->onQueue('bg-mix')
            ->delay(now()->addSeconds(2));

        $this->applyEnergyTransferToOverlappingSegments($chunkFile);
        $this->checkAndSetComplete();
    }

    // ── Private: audio extraction ─────────────────────────────────────────────

    private function cutFromLocal(string $chunkFile): ?\Illuminate\Process\ProcessResult
    {
        $duration = round($this->endTime - $this->startTime, 3);
        return Process::timeout(90)->run([
            'ffmpeg', '-y',
            '-ss', (string) round($this->startTime, 3),
            '-t',  (string) $duration,
            '-i',  $this->localAudioPath,
            '-vn', '-ac', '1', '-ar', '44100',
            '-c:a', 'aac', '-b:a', '96k', '-f', 'adts', $chunkFile,
        ]);
    }

    private function cutFromHls(string $chunkFile, array $session): ?\Illuminate\Process\ProcessResult
    {
        $segmentsJson = Redis::get(DubSession::audioSegmentsKey($this->sessionId));
        if (!$segmentsJson) return null;

        $allSegments = json_decode($segmentsJson, true);
        $tsUrls      = [];
        $currentTime = 0;

        foreach ($allSegments as $seg) {
            $segEnd = $currentTime + $seg['duration'];
            if ($segEnd > $this->startTime && $currentTime < $this->endTime) {
                $tsUrls[] = $seg['url'];
            }
            $currentTime = $segEnd;
            if ($currentTime >= $this->endTime) break;
        }

        if (empty($tsUrls)) return null;

        $workDir     = storage_path("app/instant-dub/{$this->sessionId}");
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
            '-c:a', 'aac', '-b:a', '96k', '-f', 'adts', $chunkFile,
        ]);

        @unlink($tmpPlaylist);
        return $result;
    }

    private function storeBgChunk(string $chunkFile): void
    {
        $bgChunkData = json_encode([
            'path'  => $chunkFile,
            'start' => $this->startTime,
            'end'   => $this->endTime,
        ]);

        Redis::hset(DubSession::bgChunksKey($this->sessionId), (string) $this->chunkIndex, $bgChunkData);
        Redis::expire(DubSession::bgChunksKey($this->sessionId), DubSession::TTL);

        Cache::lock(DubSession::bgLockKey($this->sessionId), 10)->block(5, function () {
            DubSession::patch($this->sessionId, ['has_bg' => true]);
        });
    }

    private function checkAndSetComplete(): void
    {
        $ttl = DubSession::TTL;
        $lua = <<<LUA
            local data = redis.call('GET', KEYS[1])
            if not data then return 0 end
            local session = cjson.decode(data)
            local ready  = session['segments_ready'] or 0
            local total  = session['total_segments'] or 999999
            local hasBg  = session['has_bg'] == true
            if ready >= total and hasBg and session['status'] ~= 'complete' then
                session['status'] = 'complete'
                redis.call('SETEX', KEYS[1], {$ttl}, cjson.encode(session))
            end
            return ready
        LUA;

        Redis::eval($lua, 1, DubSession::key($this->sessionId));
    }

    // ── Private: energy transfer ──────────────────────────────────────────────

    private function applyEnergyTransferToOverlappingSegments(string $rawAudioPath): void
    {
        $serviceUrl = config('services.prosody_transfer.url') ?: env('PROSODY_TRANSFER_SERVICE_URL');
        if (!$serviceUrl) return;

        $session = DubSession::get($this->sessionId);
        if (!$session) return;

        $total = (int) ($session['total_segments'] ?? 0);
        if ($total === 0) return;

        $chunkKeys   = array_map(fn($i) => DubSession::chunkKey($this->sessionId, $i), range(0, $total - 1));
        $chunkValues = Redis::mget($chunkKeys);

        foreach ($chunkValues as $i => $chunkJson) {
            if (!$chunkJson) continue;

            $chunk    = json_decode($chunkJson, true);
            $segStart = (float) ($chunk['start_time'] ?? 0);
            $segEnd   = (float) ($chunk['end_time']   ?? 0);
            $b64      = $chunk['audio_base64']         ?? null;

            if ($segStart < $this->startTime || $segStart >= $this->endTime) continue;
            if (!$b64) continue;

            $this->transferEnergyForSegment(
                $i, $chunk, $b64, $rawAudioPath,
                $this->startTime, $this->endTime,
                $serviceUrl
            );
        }
    }

    private function transferEnergyForSegment(
        int    $segIdx,
        array  $chunk,
        string $b64,
        string $rawAudioPath,
        float  $bgStart,
        float  $bgEnd,
        string $serviceUrl,
    ): void {
        $segStart = (float) ($chunk['start_time'] ?? 0);
        $segEnd   = (float) ($chunk['end_time']   ?? 0);
        $tmpDir   = "/tmp/instant-dub-{$this->sessionId}";
        @mkdir($tmpDir, 0755, true);

        $tmpMp3  = "{$tmpDir}/prosody_{$this->chunkIndex}_{$segIdx}.mp3";
        $ttsWav  = "{$tmpDir}/prosody_{$this->chunkIndex}_{$segIdx}_tts.wav";
        $refClip = "{$tmpDir}/prosody_{$this->chunkIndex}_{$segIdx}_ref.wav";
        $outWav  = "{$tmpDir}/prosody_{$this->chunkIndex}_{$segIdx}_out.wav";
        $outMp3  = "{$tmpDir}/prosody_{$this->chunkIndex}_{$segIdx}_out.mp3";

        try {
            file_put_contents($tmpMp3, base64_decode($b64));

            $conv = Process::timeout(10)->run([
                'ffmpeg', '-y', '-i', $tmpMp3, '-ar', '44100', '-ac', '1', '-f', 'wav', $ttsWav,
            ]);
            @unlink($tmpMp3);
            if (!$conv->successful() || !file_exists($ttsWav) || filesize($ttsWav) < 100) return;

            $segOffset    = max(0.0, $segStart - $bgStart);
            $chunkDur     = $bgEnd - $bgStart;
            $availableRef = max(0.0, $chunkDur - $segOffset);
            if ($availableRef < 0.15) { @unlink($ttsWav); return; }

            $refDur = min($segEnd - $segStart, $availableRef);
            $cut    = Process::timeout(10)->run([
                'ffmpeg', '-y',
                '-ss', (string) round($segOffset, 3),
                '-t',  (string) round($refDur, 3),
                '-i',  $rawAudioPath,
                '-af', 'highpass=f=300,lowpass=f=3400',
                '-ar', '44100', '-ac', '1', $refClip,
            ]);
            if (!$cut->successful() || !file_exists($refClip) || filesize($refClip) < 100) {
                @unlink($ttsWav);
                return;
            }

            $resp = Http::timeout(30)
                ->attach('tts_audio', file_get_contents($ttsWav), 'tts.wav')
                ->attach('reference', file_get_contents($refClip), 'ref.wav')
                ->post(rtrim($serviceUrl, '/') . '/transfer', ['energy_transfer' => 'true']);

            @unlink($ttsWav);
            @unlink($refClip);
            if ($resp->failed()) return;

            file_put_contents($outWav, $resp->body());
            if (!file_exists($outWav) || filesize($outWav) < 1000) { @unlink($outWav); return; }

            $enc = Process::timeout(10)->run([
                'ffmpeg', '-y', '-i', $outWav, '-codec:a', 'libmp3lame', '-b:a', '128k', $outMp3,
            ]);
            @unlink($outWav);
            if (!$enc->successful() || !file_exists($outMp3) || filesize($outMp3) < 500) {
                @unlink($outMp3);
                return;
            }

            $chunk['audio_base64'] = base64_encode(file_get_contents($outMp3));
            @unlink($outMp3);

            $chunkKey = DubSession::chunkKey($this->sessionId, $segIdx);
            Redis::setex($chunkKey, DubSession::TTL, json_encode($chunk));

            Log::info("[DUB] Energy transfer applied to seg #{$segIdx} via bg-chunk {$this->chunkIndex}", [
                'session' => $this->sessionId,
            ]);
        } catch (\Throwable $e) {
            Log::warning("[DUB] Energy transfer for seg #{$segIdx} failed: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
            foreach ([$tmpMp3, $ttsWav, $refClip, $outWav, $outMp3] as $f) {
                @unlink($f);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning("[DUB] DownloadAudioChunkJob {$this->chunkIndex} failed", [
            'session' => $this->sessionId,
            'error'   => $exception->getMessage(),
        ]);
    }
}
