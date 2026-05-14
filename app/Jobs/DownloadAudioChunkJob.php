<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Jobs\GenerateBgChunkJob;
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
        public string  $sessionId,
        public int     $chunkIndex,
        public float   $startTime,
        public float   $endTime,
        public ?string $localAudioPath = null,
    ) {}

    public function handle(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;

        $session = json_decode($sessionJson, true);
        if (($session['status'] ?? '') === 'stopped') return;

        $workDir = storage_path("app/instant-dub/{$this->sessionId}");
        @mkdir($workDir, 0755, true);

        $chunkFile = "{$workDir}/bg_chunk_{$this->chunkIndex}.aac";

        if ($this->localAudioPath) {
            // YouTube path: cut directly from pre-downloaded local file
            $duration = round($this->endTime - $this->startTime, 3);
            $result = Process::timeout(90)->run([
                'ffmpeg', '-y',
                '-ss', (string) round($this->startTime, 3),
                '-t',  (string) $duration,
                '-i',  $this->localAudioPath,
                '-vn', '-ac', '1', '-ar', '44100',
                '-c:a', 'aac', '-b:a', '96k',
                '-f', 'adts', $chunkFile,
            ]);
        } else {
            // HLS path: fetch .ts segments and build temp playlist
            $segmentsJson = Redis::get("instant-dub:{$this->sessionId}:audio-segments");
            if (!$segmentsJson) return;

            $allSegments = json_decode($segmentsJson, true);
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
        }

        if (!$result->successful() || !file_exists($chunkFile) || filesize($chunkFile) < 100) {
            Log::warning("[DUB] Audio chunk {$this->chunkIndex} download failed", [
                'session' => $this->sessionId,
                'error' => Str::limit($result->errorOutput(), 200),
            ]);
            return;
        }

        // Store bg chunk metadata in a dedicated Redis hash (keeps session JSON lean)
        $bgChunkData = json_encode(['path' => $chunkFile, 'start' => $this->startTime, 'end' => $this->endTime]);
        Redis::hset("instant-dub:{$this->sessionId}:bgchunks", (string) $this->chunkIndex, $bgChunkData);
        Redis::expire("instant-dub:{$this->sessionId}:bgchunks", 50400);

        // Mark has_bg in session so checkAndSetComplete can detect it without reading the hash
        $bgLock = Cache::lock("instant-dub:{$this->sessionId}:bg-lock", 10);
        $bgLock->block(5, function () use ($sessionKey, $chunkFile) {
            $s = json_decode(Redis::get($sessionKey) ?? '{}', true);
            $s['original_audio_path'] = $chunkFile;
            $s['has_bg'] = true;
            Redis::setex($sessionKey, 50400, json_encode($s));
        });

        Log::info("[DUB] Audio chunk {$this->chunkIndex} ready (" . round($this->startTime) . "-" . round($this->endTime) . "s, " . round(filesize($chunkFile) / 1024) . " KB)", [
            'session' => $this->sessionId,
        ]);

        // Dispatch the bg mix job with a short delay so concurrent TTS completions
        // can accumulate before the ffmpeg run. ShouldBeUnique drops duplicates.
        GenerateBgChunkJob::dispatch($this->sessionId, $this->chunkIndex, $this->startTime, $this->endTime)
            ->onQueue('bg-mix')
            ->delay(now()->addSeconds(2));

        $this->applyEnergyTransferToOverlappingSegments($chunkFile);

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
            local hasBg = session['has_bg'] == true
            if ready >= total and hasBg and session['status'] ~= 'complete' then
                session['status'] = 'complete'
                redis.call('SETEX', KEYS[1], 50400, cjson.encode(session))
            end
            return ready
        LUA;

        Redis::eval($lua, 1, "instant-dub:{$this->sessionId}");
    }

    private function applyEnergyTransferToOverlappingSegments(string $rawAudioPath): void
    {
        $serviceUrl = config('services.prosody_transfer.url') ?: env('PROSODY_TRANSFER_SERVICE_URL');
        if (!$serviceUrl) return;

        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;
        $session = json_decode($sessionJson, true);
        $total = (int) ($session['total_segments'] ?? 0);
        if ($total === 0) return;

        for ($i = 0; $i < $total; $i++) {
            $chunkKey  = "{$sessionKey}:chunk:{$i}";
            $chunkJson = Redis::get($chunkKey);
            if (!$chunkJson) continue;

            $chunk    = json_decode($chunkJson, true);
            $segStart = (float) ($chunk['start_time'] ?? 0);
            $segEnd   = (float) ($chunk['end_time'] ?? 0);
            $b64      = $chunk['audio_base64'] ?? null;

            if ($segStart < $this->startTime || $segStart >= $this->endTime) continue;
            if (!$b64) continue;

            try {
                $tmpMp3 = "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}.mp3";
                file_put_contents($tmpMp3, base64_decode($b64));

                $ttsWav = "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}_tts.wav";
                $conv = Process::timeout(10)->run([
                    'ffmpeg', '-y', '-i', $tmpMp3,
                    '-ar', '44100', '-ac', '1', '-f', 'wav', $ttsWav,
                ]);
                @unlink($tmpMp3);
                if (!$conv->successful() || !file_exists($ttsWav) || filesize($ttsWav) < 100) continue;

                $segOffset    = max(0.0, $segStart - $this->startTime);
                $chunkDur     = $this->endTime - $this->startTime;
                $availableRef = max(0.0, $chunkDur - $segOffset);
                if ($availableRef < 0.15) {
                    @unlink($ttsWav);
                    continue;
                }
                $refDur  = min($segEnd - $segStart, $availableRef);
                $refClip = "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}_ref.wav";
                $cut = Process::timeout(10)->run([
                    'ffmpeg', '-y',
                    '-ss', (string) round($segOffset, 3),
                    '-t',  (string) round($refDur, 3),
                    '-i',  $rawAudioPath,
                    '-af', 'highpass=f=300,lowpass=f=3400',
                    '-ar', '44100', '-ac', '1',
                    $refClip,
                ]);
                if (!$cut->successful() || !file_exists($refClip) || filesize($refClip) < 100) {
                    @unlink($ttsWav);
                    continue;
                }

                $resp = \Illuminate\Support\Facades\Http::timeout(30)
                    ->attach('tts_audio', file_get_contents($ttsWav), 'tts.wav')
                    ->attach('reference', file_get_contents($refClip), 'ref.wav')
                    ->post(rtrim($serviceUrl, '/') . '/transfer', [
                        'energy_transfer' => 'true',
                    ]);

                @unlink($ttsWav);
                @unlink($refClip);

                if ($resp->failed()) continue;

                $outWav = "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}_out.wav";
                $outMp3 = "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}_out.mp3";
                file_put_contents($outWav, $resp->body());
                if (!file_exists($outWav) || filesize($outWav) < 1000) {
                    @unlink($outWav);
                    continue;
                }

                $enc = Process::timeout(10)->run([
                    'ffmpeg', '-y', '-i', $outWav,
                    '-codec:a', 'libmp3lame', '-b:a', '128k', $outMp3,
                ]);
                @unlink($outWav);
                if (!$enc->successful() || !file_exists($outMp3) || filesize($outMp3) < 500) {
                    @unlink($outMp3);
                    continue;
                }

                $chunk['audio_base64'] = base64_encode(file_get_contents($outMp3));
                @unlink($outMp3);
                Redis::setex($chunkKey, 50400, json_encode($chunk));

                Log::info("[DUB] Energy transfer applied to seg #{$i} via bg-chunk {$this->chunkIndex}", [
                    'session' => $this->sessionId,
                ]);

            } catch (\Throwable $e) {
                Log::warning("[DUB] Energy transfer for seg #{$i} failed: " . $e->getMessage(), [
                    'session' => $this->sessionId,
                ]);
                foreach ([
                    "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}.mp3",
                    "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}_tts.wav",
                    "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}_ref.wav",
                    "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}_out.wav",
                    "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}_out.mp3",
                ] as $f) @unlink($f);
            }
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
