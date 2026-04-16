<?php

namespace App\Jobs;

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

        $bgForMix = $chunkFile;
        if ($this->hasSubtitlesInRange()) {
            $noVocalsFile = $this->separateVocals($chunkFile, $workDir);
            $bgForMix = $noVocalsFile ?? $chunkFile;
            // Vocals ready — apply prosody transfer to overlapping TTS segments
            $this->applyProsodyToOverlappingSegments($workDir);
        }
        $this->generateBgChunkAac($bgForMix);

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
     * Check if any subtitle segment overlaps this chunk's time range.
     * Only chunks with dubbed speech need vocal separation.
     */
    private function hasSubtitlesInRange(): bool
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return false;

        $session = json_decode($sessionJson, true);
        $total = (int) ($session['total_segments'] ?? 0);
        if ($total === 0) return false;

        for ($i = 0; $i < $total; $i++) {
            $chunkJson = Redis::get("{$sessionKey}:chunk:{$i}");
            if (!$chunkJson) continue;
            $chunk = json_decode($chunkJson, true);
            $segStart = (float) ($chunk['start_time'] ?? 0);
            $segEnd   = (float) ($chunk['end_time'] ?? 0);
            if ($segStart < $this->endTime && $segEnd > $this->startTime) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upload chunk audio to Demucs RunPod service, download no_vocals track.
     * Returns local path to no_vocals file, or null on failure (fallback to original).
     */
    private function separateVocals(string $audioPath, string $workDir): ?string
    {
        $serviceUrl = config('services.demucs.url') ?: env('DEMUCS_SERVICE_URL');
        if (!$serviceUrl) return null;

        try {
            // Demucs WAV talab qiladi — AAC/other formatdan convert
            $sendPath = $audioPath;
            $tmpWav   = null;
            if (!str_ends_with(strtolower($audioPath), '.wav')) {
                $tmpWav   = "{$workDir}/bg_chunk_{$this->chunkIndex}_demucs.wav";
                $conv = Process::timeout(30)->run([
                    'ffmpeg', '-y', '-i', $audioPath,
                    '-ac', '2', '-ar', '44100', '-f', 'wav', $tmpWav,
                ]);
                if ($conv->successful() && file_exists($tmpWav) && filesize($tmpWav) > 100) {
                    $sendPath = $tmpWav;
                }
            }

            // Demucs GPU da bir vaqtda 1 ta request — OOM oldini olish uchun
            $demucsLock = \Illuminate\Support\Facades\Cache::lock('demucs-concurrency', 120);
            $demucsLock->block(60);
            try {
                $response = Http::timeout(120)
                    ->attach('audio', file_get_contents($sendPath), 'audio.wav')
                    ->post("{$serviceUrl}/separate");
            } finally {
                $demucsLock->release();
                if ($tmpWav) @unlink($tmpWav);
            }

            if ($response->failed()) {
                Log::warning("[DUB] Demucs separation failed for chunk {$this->chunkIndex}", [
                    'session' => $this->sessionId,
                    'status'  => $response->status(),
                ]);
                return null;
            }

            $result      = $response->json();
            $noVocalsUrl = $result['no_vocals_url'] ?? null;
            $vocalsUrl   = $result['vocals_url'] ?? null;
            if (!$noVocalsUrl) return null;

            // Relative URL bo'lsa service URL bilan to'ldirish
            if ($noVocalsUrl && !str_starts_with($noVocalsUrl, 'http')) {
                $noVocalsUrl = rtrim($serviceUrl, '/') . '/' . ltrim($noVocalsUrl, '/');
            }
            if ($vocalsUrl && !str_starts_with($vocalsUrl, 'http')) {
                $vocalsUrl = rtrim($serviceUrl, '/') . '/' . ltrim($vocalsUrl, '/');
            }

            $noVocalsFile = "{$workDir}/bg_chunk_{$this->chunkIndex}_novocals.wav";
            $download = Http::timeout(60)->get($noVocalsUrl);
            if ($download->failed()) return null;
            file_put_contents($noVocalsFile, $download->body());
            if (!file_exists($noVocalsFile) || filesize($noVocalsFile) < 100) return null;

            // vocals — prosody transfer uchun referens sifatida saqlanadi
            if ($vocalsUrl) {
                $vocalsFile = "{$workDir}/bg_chunk_{$this->chunkIndex}_vocals.wav";
                $vocDownload = Http::timeout(60)->get($vocalsUrl);
                if ($vocDownload->successful()) {
                    file_put_contents($vocalsFile, $vocDownload->body());
                    if (file_exists($vocalsFile) && filesize($vocalsFile) > 100) {
                        // bg_chunks ga vocals_path qo'shish
                        $lock = \Illuminate\Support\Facades\Cache::lock("instant-dub:{$this->sessionId}:bg-lock", 10);
                        $lock->block(10, function () use ($vocalsFile) {
                            $sJson = Redis::get("instant-dub:{$this->sessionId}");
                            if (!$sJson) return;
                            $s = json_decode($sJson, true);
                            if (isset($s['bg_chunks'][$this->chunkIndex])) {
                                $s['bg_chunks'][$this->chunkIndex]['vocals_path'] = $vocalsFile;
                                Redis::setex("instant-dub:{$this->sessionId}", 50400, json_encode($s));
                            }
                        });
                    }
                }
            }

            Log::info("[DUB] Demucs chunk {$this->chunkIndex} separated (" . round(filesize($noVocalsFile) / 1024) . " KB)", [
                'session' => $this->sessionId,
            ]);

            return $noVocalsFile;
        } catch (\Throwable $e) {
            Log::warning("[DUB] Demucs exception for chunk {$this->chunkIndex}", [
                'session' => $this->sessionId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
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

        // If vocal-separated file exists for this chunk, prefer it over raw audio
        $workDir      = storage_path("app/instant-dub/{$this->sessionId}");
        $noVocalsFile = "{$workDir}/bg_chunk_{$this->chunkIndex}_novocals.wav";
        if (file_exists($noVocalsFile) && filesize($noVocalsFile) > 100) {
            $bgAudioPath = $noVocalsFile;
        }

        $cmd      = [
            'ffmpeg', '-y',
            '-f', 'lavfi', '-t', (string) $chunkDur, '-i', 'anullsrc=r=44100:cl=mono',
            '-t', (string) $chunkDur, '-i', $bgAudioPath,
        ];
        // Normalize background to a fixed target (-20 dBFS mean) so that
        // no_vocals (quieter after vocal removal) and raw audio sound at the same level.
        $bgGainDb  = $this->measureVolumeGain($bgAudioPath, -20.0);
        $filters   = ["[1:a]volume={$bgGainDb}dB,aresample=44100[bg]"];
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

    /**
     * Measure mean volume of an audio file and return the gain (dB) needed
     * to reach $targetDb. Clamped to [-6, +18] dB to avoid extremes.
     */
    private function measureVolumeGain(string $audioPath, float $targetDb = -20.0): float
    {
        $result = Process::timeout(10)->run([
            'ffmpeg', '-i', $audioPath,
            '-af', 'volumedetect',
            '-vn', '-sn', '-dn',
            '-f', 'null', '/dev/null',
        ]);
        if (preg_match('/mean_volume:\s*([-\d.]+)\s*dB/', $result->errorOutput(), $m)) {
            $meanDb = (float) $m[1];
            return max(-6.0, min(18.0, $targetDb - $meanDb));
        }
        // Fallback: no measurement → no gain change
        return 0.0;
    }

    private function frameAlignedDuration(float $start, float $end): float
    {
        $startFrames = (int) round($start * 44100 / 1024);
        $endFrames = (int) round($end * 44100 / 1024);
        return max(1, $endFrames - $startFrames) * 1024 / 44100;
    }

    /**
     * After Demucs separates vocals for this chunk, apply prosody transfer to all
     * TTS segments in this time range and update their audio_base64 in Redis.
     * This runs for both live and cache-hit sessions.
     */
    private function applyProsodyToOverlappingSegments(string $workDir): void
    {
        $serviceUrl = config('services.prosody_transfer.url') ?: env('PROSODY_TRANSFER_SERVICE_URL');
        if (!$serviceUrl) return;

        $vocalsFile = "{$workDir}/bg_chunk_{$this->chunkIndex}_vocals.wav";
        if (!file_exists($vocalsFile) || filesize($vocalsFile) < 100) return;

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

            // Only process segments whose START falls within this bg chunk.
            // Segments overlapping the boundary will be handled by the chunk that owns their start.
            // This prevents double-processing (which can overwrite with a wrong reference).
            if ($segStart < $this->startTime || $segStart >= $this->endTime) continue;
            if (!$b64) continue;

            try {
                // Write TTS audio to temp file
                $tmpMp3 = "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}.mp3";
                file_put_contents($tmpMp3, base64_decode($b64));

                // Convert TTS MP3 → WAV for prosody service
                $ttsWav = "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}_tts.wav";
                $conv = Process::timeout(10)->run([
                    'ffmpeg', '-y', '-i', $tmpMp3,
                    '-ar', '44100', '-ac', '1', '-f', 'wav', $ttsWav,
                ]);
                @unlink($tmpMp3);
                if (!$conv->successful() || !file_exists($ttsWav) || filesize($ttsWav) < 100) continue;

                // Cut vocals reference — clamp to bg chunk boundary to avoid empty clips
                $segOffset  = max(0.0, $segStart - $this->startTime);
                $chunkDur   = $this->endTime - $this->startTime;
                $availableRef = max(0.0, $chunkDur - $segOffset);
                // Need at least 0.15s of reference audio, otherwise prosody won't help
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
                    '-i',  $vocalsFile,
                    $refClip,
                ]);
                if (!$cut->successful() || !file_exists($refClip) || filesize($refClip) < 100) {
                    @unlink($ttsWav);
                    continue;
                }

                // Call prosody transfer service (RMS gain envelope only, no WORLD)
                $resp = \Illuminate\Support\Facades\Http::timeout(30)
                    ->attach('tts_audio',  file_get_contents($ttsWav),  'tts.wav')
                    ->attach('reference',  file_get_contents($refClip), 'ref.wav')
                    ->post(rtrim($serviceUrl, '/') . '/transfer', [
                        'energy_transfer' => 'true',
                    ]);

                @unlink($ttsWav);
                @unlink($refClip);

                if ($resp->failed()) continue;

                // Convert result WAV → MP3 and update Redis
                $outWav = "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}_out.wav";
                $outMp3 = "/tmp/prosody_{$this->sessionId}_{$this->chunkIndex}_{$i}_out.mp3";
                file_put_contents($outWav, $resp->body());
                // Sanity check: output must be reasonably large and not silent
                if (!file_exists($outWav) || filesize($outWav) < 1000) {
                    @unlink($outWav);
                    continue;
                }

                $enc = Process::timeout(10)->run([
                    'ffmpeg', '-y', '-i', $outWav,
                    '-codec:a', 'libmp3lame', '-b:a', '128k', $outMp3,
                ]);
                @unlink($outWav);
                // Output MP3 must be meaningfully larger than an empty file
                if (!$enc->successful() || !file_exists($outMp3) || filesize($outMp3) < 500) {
                    @unlink($outMp3);
                    continue;
                }

                $newB64 = base64_encode(file_get_contents($outMp3));
                @unlink($outMp3);

                $chunk['audio_base64'] = $newB64;
                Redis::setex($chunkKey, 50400, json_encode($chunk));

                Log::info("[DUB] Prosody applied to seg #{$i} via bg-chunk {$this->chunkIndex}", [
                    'session' => $this->sessionId,
                ]);

            } catch (\Throwable $e) {
                Log::warning("[DUB] Prosody for seg #{$i} failed: " . $e->getMessage(), [
                    'session' => $this->sessionId,
                ]);
                // Cleanup any leftover temp files
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

    public function failed(\Throwable $exception): void
    {
        Log::warning("[DUB] DownloadAudioChunkJob {$this->chunkIndex} failed", [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
