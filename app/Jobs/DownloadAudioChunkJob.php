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

        $this->remixSegmentsInRange($chunkFile);
        $this->maybeRegenerateLeadAac();

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
                    $leadDur = round($this->frameAlignedDuration(0, $firstStart), 6);
                    Process::timeout(30)->run([
                        'ffmpeg', '-y',
                        '-f', 'lavfi', '-t', (string) $leadDur, '-i', 'anullsrc=r=44100:cl=mono',
                        '-ss', '0', '-t', (string) $leadDur, '-i', $bgAudioPath,
                        '-filter_complex',
                        "[1:a]volume=0.2[bg];[0:a][bg]amix=inputs=2:duration=first:normalize=0",
                        '-ac', '1', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $leadFile,
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

            // Only remix segments that belong to this chunk's time range
            if ($segStart < $this->startTime || $segStart >= $this->endTime) continue;

            $audioBase64 = $chunk['audio_base64'] ?? null;

            $aacFile = "{$aacDir}/{$i}.aac";
            $seekInBg = max(0, $segStart - $this->startTime);

            $slotEnd = isset($chunk['slot_end']) ? (float) $chunk['slot_end'] : null;
            if ($slotEnd === null) {
                $nextChunkJson = Redis::get("{$sessionKey}:chunk:" . ($i + 1));
                $slotEnd = $nextChunkJson
                    ? (float) (json_decode($nextChunkJson, true)['start_time'] ?? $segEnd)
                    : $segEnd;
            }
            $slotDuration = round($this->frameAlignedDuration($segStart, $slotEnd), 6);

            if ($audioBase64) {
                // Remix: TTS + background
                $tmpMp3 = "/tmp/remix_{$this->sessionId}_{$i}.mp3";
                file_put_contents($tmpMp3, base64_decode($audioBase64));

                $result = Process::timeout(20)->run([
                    'ffmpeg', '-y',
                    '-f', 'lavfi', '-t', (string) $slotDuration, '-i', 'anullsrc=r=44100:cl=mono',
                    '-ss', (string) round($seekInBg, 3), '-t', (string) $slotDuration, '-i', $bgAudioPath,
                    '-i', $tmpMp3,
                    '-filter_complex',
                    "[1:a]volume=0.2,aresample=44100[bg];[2:a]aresample=44100[tts];[0:a][bg][tts]amix=inputs=3:duration=first:normalize=0",
                    '-ac', '1', '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
                ]);
                @unlink($tmpMp3);
            } else {
                // No TTS (failed/empty): background only at lowered volume
                $result = Process::timeout(20)->run([
                    'ffmpeg', '-y',
                    '-f', 'lavfi', '-t', (string) $slotDuration, '-i', 'anullsrc=r=44100:cl=mono',
                    '-ss', (string) round($seekInBg, 3), '-t', (string) $slotDuration, '-i', $bgAudioPath,
                    '-filter_complex',
                    "[1:a]volume=0.2[bg];[0:a][bg]amix=inputs=2:duration=first:normalize=0",
                    '-ac', '1', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $aacFile,
                ]);
            }

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

    /**
     * Re-generate lead.aac whenever a new bg chunk arrives that overlaps
     * the lead period (0 → first segment start).  The first time lead.aac is
     * created only chunk 0 may be available, which is often shorter than the
     * lead duration → silence at the end of the lead segment.
     */
    private function maybeRegenerateLeadAac(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;
        $session = json_decode($sessionJson, true);

        // Get first segment's start time
        $chunk0Json = Redis::get("{$sessionKey}:chunk:0");
        if (!$chunk0Json) return; // no segments yet
        $firstStart = (float) (json_decode($chunk0Json, true)['start_time'] ?? 0);
        if ($firstStart <= 1.0) return; // no meaningful lead

        // Only re-generate if this chunk overlaps the lead period [0, firstStart]
        if ($this->startTime >= $firstStart) return;

        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");
        $leadFile = "{$aacDir}/lead.aac";
        if (!is_dir($aacDir)) @mkdir($aacDir, 0755, true);

        // Collect all available bg chunks that cover [0, firstStart]
        $bgChunks = $session['bg_chunks'] ?? [];
        $relevant = [];
        foreach ($bgChunks as $chunk) {
            $cs = (float) ($chunk['start'] ?? 0);
            $ce = (float) ($chunk['end'] ?? 0);
            $path = $chunk['path'] ?? null;
            if ($path && file_exists($path) && $ce > 0 && $cs < $firstStart) {
                $relevant[] = $chunk;
            }
        }
        if (empty($relevant)) return;

        usort($relevant, fn($a, $b) => ($a['start'] ?? 0) <=> ($b['start'] ?? 0));

        // Check if available chunks fully cover the lead (otherwise generating now
        // would produce silence at the end — better to wait for next chunk)
        $coveredUntil = 0.0;
        foreach ($relevant as $c) {
            if ((float)($c['start'] ?? 0) <= $coveredUntil) {
                $coveredUntil = max($coveredUntil, (float)($c['end'] ?? 0));
            }
        }
        if ($coveredUntil < $firstStart) return; // not enough chunks yet

        // Build bg input (concat if multiple chunks)
        if (count($relevant) === 1) {
            $bgPath = $relevant[0]['path'];
            $tempConcat = null;
        } else {
            $concatTxt  = "/tmp/lead_concat_{$this->sessionId}.txt";
            $bgPath     = "/tmp/lead_concat_{$this->sessionId}.aac";
            $tempConcat = $bgPath;
            file_put_contents($concatTxt, implode("\n", array_map(
                fn($c) => "file '" . str_replace("'", "'\\''", $c['path']) . "'",
                $relevant
            )));
            $res = Process::timeout(30)->run([
                'ffmpeg', '-y', '-f', 'concat', '-safe', '0',
                '-i', $concatTxt, '-c', 'copy', $bgPath,
            ]);
            @unlink($concatTxt);
            if (!$res->successful()) return;
        }

        $duration = round($this->frameAlignedDuration(0, $firstStart), 6);
        $timeout  = max(30, (int) ceil($duration) + 10);

        $result = Process::timeout($timeout)->run([
            'ffmpeg', '-y',
            '-f', 'lavfi', '-t', (string) $duration, '-i', 'anullsrc=r=44100:cl=mono',
            '-ss', '0', '-t', (string) $duration, '-i', $bgPath,
            '-filter_complex', '[1:a]volume=0.2[bg];[0:a][bg]amix=inputs=2:duration=first:normalize=0',
            '-ac', '1', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $leadFile,
        ]);

        if ($tempConcat) @unlink($tempConcat);

        if ($result->successful()) {
            Log::info("[DUB] lead.aac regenerated with {$duration}s background (chunk {$this->chunkIndex})", [
                'session' => $this->sessionId,
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
