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
        public float   $fileOffset     = 0.0, // global start time of localAudioPath (for window files)
    ) {}

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['status'] ?? '') === 'stopped') return;

        $workDir = storage_path("app/instant-dub/{$this->sessionId}");
        @mkdir($workDir, 0755, true);

        $chunkFile = "{$workDir}/bg_chunk_{$this->chunkIndex}.ts";

        $result = $this->localAudioPath
            ? $this->cutFromLocal($chunkFile)
            : $this->cutFromHls($chunkFile, $session);

        if ($result === null) {
            throw new \RuntimeException("No HLS source segments found for bg chunk {$this->chunkIndex}");
        }

        if (!$result->successful() || !file_exists($chunkFile) || filesize($chunkFile) < 100) {
            Log::warning("[DUB] Audio chunk {$this->chunkIndex} download failed", [
                'session' => $this->sessionId,
                'error'   => Str::limit($result->errorOutput(), 200),
            ]);
            throw new \RuntimeException("Audio chunk {$this->chunkIndex} download failed: " . Str::limit($result->errorOutput(), 200));
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
        $this->refreshHasBackgroundState();
    }

    // ── Private: audio extraction ─────────────────────────────────────────────

    private function cutFromLocal(string $chunkFile): ?\Illuminate\Process\ProcessResult
    {
        // Seek relative to file start (window files don't start at t=0 globally)
        $localSeek = max(0.0, round($this->startTime - $this->fileOffset, 3));
        $duration  = round($this->endTime - $this->startTime, 3);
        return Process::timeout(90)->run([
            'ffmpeg', '-y',
            '-ss', (string) $localSeek,
            '-t',  (string) $duration,
            '-i',  $this->localAudioPath,
            '-vn', '-ar', '44100',
            '-c:a', 'aac', '-b:a', '96k',
            '-muxdelay', '0', '-muxpreload', '0',
            '-f', 'mpegts', $chunkFile,
        ]);
    }

    private function cutFromHls(string $chunkFile, array $session): ?\Illuminate\Process\ProcessResult
    {
        $segmentsJson = Redis::get(DubSession::audioSegmentsKey($this->sessionId));
        if (!$segmentsJson) return null;

        $allSegments = json_decode($segmentsJson, true);
        $selection = $this->selectHlsSegmentsForRange($allSegments);

        if (empty($selection['segments'])) return null;

        $workDir     = storage_path("app/instant-dub/{$this->sessionId}");
        $tmpPlaylist = "{$workDir}/chunk_{$this->chunkIndex}.m3u8";

        file_put_contents($tmpPlaylist, $this->buildHlsChunkPlaylist($selection['segments']));

        $duration = max(0.1, round($this->endTime - $this->startTime, 3));
        $seek = max(0.0, round((float) $selection['seek_offset'], 3));
        $result = Process::timeout(90)->run([
            'ffmpeg', '-y',
            '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
            '-i', $tmpPlaylist,
            '-ss', (string) $seek,
            '-t', (string) $duration,
            '-vn', '-ar', '44100',
            '-c:a', 'aac', '-b:a', '96k',
            '-muxdelay', '0', '-muxpreload', '0',
            '-f', 'mpegts', $chunkFile,
        ]);

        @unlink($tmpPlaylist);
        return $result;
    }

    private function selectHlsSegmentsForRange(array $allSegments): array
    {
        $selected = [];
        $currentTime = 0.0;
        $firstStart = null;

        foreach ($allSegments as $seg) {
            $duration = (float) ($seg['duration'] ?? 0.0);
            $segStart = array_key_exists('start', $seg) ? (float) $seg['start'] : $currentTime;
            $segEnd = array_key_exists('end', $seg) ? (float) $seg['end'] : $segStart + $duration;

            if ($segEnd > $this->startTime && $segStart < $this->endTime) {
                $selected[] = array_merge($seg, [
                    'start' => $segStart,
                    'end' => $segEnd,
                    'duration' => $duration > 0 ? $duration : max(0.0, $segEnd - $segStart),
                ]);
                $firstStart ??= $segStart;
            }

            $currentTime = $segEnd;
            if ($segStart >= $this->endTime) break;
        }

        return [
            'segments' => $selected,
            'seek_offset' => $firstStart === null ? 0.0 : max(0.0, $this->startTime - $firstStart),
        ];
    }

    private function buildHlsChunkPlaylist(array $segments): string
    {
        $targetDuration = 1;
        foreach ($segments as $seg) {
            $targetDuration = max($targetDuration, (int) ceil((float) ($seg['duration'] ?? 0.0)));
        }

        $m3u8 = "#EXTM3U\n#EXT-X-VERSION:6\n#EXT-X-TARGETDURATION:{$targetDuration}\n#EXT-X-MEDIA-SEQUENCE:0\n";
        $lastKey = null;
        $lastMap = null;

        foreach ($segments as $seg) {
            $key = $seg['key'] ?? null;
            if ($key && $key !== $lastKey) {
                $m3u8 .= "{$key}\n";
                $lastKey = $key;
            }

            $map = $seg['map'] ?? null;
            if ($map && $map !== $lastMap) {
                $m3u8 .= "{$map}\n";
                $lastMap = $map;
            }

            $duration = max(0.001, (float) ($seg['duration'] ?? 0.0));
            $m3u8 .= '#EXTINF:' . rtrim(rtrim(sprintf('%.6F', $duration), '0'), '.') . ",\n";
            $m3u8 .= ($seg['url'] ?? '') . "\n";
        }

        return $m3u8 . "#EXT-X-ENDLIST\n";
    }

    private function storeBgChunk(string $chunkFile): void
    {
        DubSession::mergeBgChunk($this->sessionId, $this->chunkIndex, [
            'path'  => $chunkFile,
            'start' => $this->startTime,
            'end'   => $this->endTime,
        ]);

        Cache::lock(DubSession::bgLockKey($this->sessionId), 10)->block(5, function () {
            DubSession::patch($this->sessionId, ['has_bg' => true]);
        });
    }

    private function refreshHasBackgroundState(): void
    {
        $ttl = DubSession::TTL;
        $lua = <<<LUA
            local data = redis.call('GET', KEYS[1])
            if not data then return 0 end
            local session = cjson.decode(data)
            session['has_bg'] = true
            redis.call('SETEX', KEYS[1], {$ttl}, cjson.encode(session))
            return 1
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

            $chunk     = json_decode($chunkJson, true);
            $segStart  = (float) ($chunk['start_time'] ?? 0);
            $audioPath = $chunk['audio_path']           ?? null;

            if ($segStart < $this->startTime || $segStart >= $this->endTime) continue;
            if (!$audioPath || !file_exists($audioPath)) continue;

            $this->transferEnergyForSegment(
                $i, $chunk, $audioPath, $rawAudioPath,
                $this->startTime, $this->endTime,
                $serviceUrl
            );
        }
    }

    private function transferEnergyForSegment(
        int    $segIdx,
        array  $chunk,
        string $segAudioPath,
        string $rawAudioPath,
        float  $bgStart,
        float  $bgEnd,
        string $serviceUrl,
    ): void {
        $segStart = (float) ($chunk['start_time'] ?? 0);
        $segEnd   = (float) ($chunk['end_time']   ?? 0);
        $tmpDir   = "/tmp/instant-dub-{$this->sessionId}";
        @mkdir($tmpDir, 0755, true);

        $ttsWav  = "{$tmpDir}/prosody_{$this->chunkIndex}_{$segIdx}_tts.wav";
        $refClip = "{$tmpDir}/prosody_{$this->chunkIndex}_{$segIdx}_ref.wav";
        $outWav  = "{$tmpDir}/prosody_{$this->chunkIndex}_{$segIdx}_out.wav";
        $outMp3  = "{$tmpDir}/prosody_{$this->chunkIndex}_{$segIdx}_out.mp3";

        try {
            $conv = Process::timeout(10)->run([
                'ffmpeg', '-y', '-i', $segAudioPath, '-ar', '44100', '-ac', '1', '-f', 'wav', $ttsWav,
            ]);
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

            // Replace segment audio file with energy-transferred version
            @unlink($segAudioPath);
            rename($outMp3, $segAudioPath);

            // Update chunk in Redis only if path changed
            if (($chunk['audio_path'] ?? '') !== $segAudioPath) {
                $chunk['audio_path'] = $segAudioPath;
                $chunkKey = DubSession::chunkKey($this->sessionId, $segIdx);
                Redis::setex($chunkKey, DubSession::TTL, json_encode($chunk));
            }

            Log::info("[DUB] Energy transfer applied to seg #{$segIdx} via bg-chunk {$this->chunkIndex}", [
                'session' => $this->sessionId,
            ]);
        } catch (\Throwable $e) {
            Log::warning("[DUB] Energy transfer for seg #{$segIdx} failed: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
            foreach ([$ttsWav, $refClip, $outWav, $outMp3] as $f) {
                @unlink($f);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        DubSession::patch($this->sessionId, [
            'status' => 'error',
            'error' => 'Background audio chunk failed: ' . Str::limit($exception->getMessage(), 120),
        ]);

        Log::warning("[DUB] DownloadAudioChunkJob {$this->chunkIndex} failed", [
            'session' => $this->sessionId,
            'error'   => $exception->getMessage(),
        ]);
    }
}
