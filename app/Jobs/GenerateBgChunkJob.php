<?php

namespace App\Jobs;

use App\Support\AudioFrame;
use App\Support\DubSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Mixes one 30-second background chunk: centre-cancelled original + TTS overlays.
 *
 * ShouldBeUnique prevents the cascade problem: if 8 TTS completions trigger this job
 * for the same chunk, only the first dispatch is queued; the rest are dropped.
 * The 3-second dispatch delay (set by callers) lets multiple TTS arrive before the
 * job actually runs, so one ffmpeg call covers all of them.
 */
class GenerateBgChunkJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout   = 180;
    public int $tries     = 2;
    public int $uniqueFor = 60;

    public function __construct(
        public string $sessionId,
        public int    $chunkIndex,
        public float  $startTime,
        public float  $endTime,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->sessionId}:{$this->chunkIndex}";
    }

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['status'] ?? '') === 'stopped') return;

        $bgChunkJson = Redis::hget(DubSession::bgChunksKey($this->sessionId), (string) $this->chunkIndex);
        if (!$bgChunkJson) return;

        $bgAudioPath = json_decode($bgChunkJson, true)['path'] ?? null;
        if (!$bgAudioPath || !file_exists($bgAudioPath)) return;

        $lock = Cache::lock(DubSession::bgGenLockKey($this->sessionId, $this->chunkIndex), 90);
        if (!$lock->get()) return;

        try {
            $this->mix($bgAudioPath, $session);
        } finally {
            $lock->release();
        }
    }

    private function mix(string $bgAudioPath, array $session): void
    {
        $total    = (int) ($session['total_segments'] ?? 0);
        $aacDir   = DubSession::aacDir($this->sessionId, $session);

        if (!is_dir($aacDir)) @mkdir($aacDir, 0755, true);

        $chunkDur = round(AudioFrame::alignedDuration($this->startTime, $this->endTime), 6);
        $outFile  = "{$aacDir}/bg-{$this->chunkIndex}.aac";
        // Write to a temp file, then atomically rename to outFile.
        // This prevents hlsAudioPlaylist from seeing a 0-byte file mid-write
        // (ffmpeg -y truncates the output file before writing), which would
        // cause the HLS EVENT playlist to shrink — a fatal spec violation.
        $writeFile = "{$aacDir}/bg-{$this->chunkIndex}.aac.tmp";

        // Detect channel count of the bg audio chunk
        $chanProbe = Process::timeout(5)->run([
            'ffprobe', '-v', 'error', '-select_streams', 'a:0',
            '-show_entries', 'stream=channels',
            '-of', 'default=nw=1:nk=1', $bgAudioPath,
        ]);
        $bgChannels = (int) trim($chanProbe->output());

        // Stereo centre cancellation: dialogue is centre-panned (L≈R), 88% cancellation
        // removes most of the original speech. Only works with stereo source.
        // For mono sources, just pass through at normal volume (voice removal impossible).
        $bgFilter = $bgChannels >= 2
            ? '[1:a]pan=stereo|c0=c0-0.88*c1|c1=c1-0.88*c0,volume=1.0,aresample=44100[bg]'
            : '[1:a]volume=1.0,aresample=44100[bg]';

        $cmd = [
            'ffmpeg', '-y',
            '-f', 'lavfi', '-t', (string) $chunkDur, '-i', 'anullsrc=r=44100:cl=stereo',
            '-t', (string) $chunkDur, '-i', $bgAudioPath,
        ];
        $filters   = [$bgFilter];
        $mixInputs = ['[0:a]', '[bg]'];
        $inputIdx  = 2;

        // Fetch all TTS chunks in one Redis pipeline
        $chunkKeys   = array_map(fn($i) => DubSession::chunkKey($this->sessionId, $i), range(0, max(0, $total - 1)));
        $chunkValues = $total > 0 ? Redis::mget($chunkKeys) : [];

        foreach ($chunkValues as $i => $chunkJson) {
            if (!$chunkJson) continue;

            $chunk     = json_decode($chunkJson, true);
            $segStart  = (float) ($chunk['start_time'] ?? 0);
            $segEnd    = (float) ($chunk['end_time']   ?? 0);
            $audioPath = $chunk['audio_path']           ?? null;

            if ($segStart >= $this->endTime || $segEnd <= $this->startTime) continue;
            if (!$audioPath || !file_exists($audioPath)) continue;

            $ttsSeek    = max(0.0, $this->startTime - $segStart);
            $ttsDelayMs = (int) round(max(0.0, $segStart - $this->startTime) * 1000);

            $cmd[] = '-ss';
            $cmd[] = (string) round($ttsSeek, 3);
            $cmd[] = '-i';
            $cmd[] = $audioPath;

            $filters[]   = "[{$inputIdx}:a]adelay={$ttsDelayMs}|{$ttsDelayMs},volume=3.0,aresample=44100[tts{$inputIdx}]";
            $mixInputs[] = "[tts{$inputIdx}]";
            $inputIdx++;
        }

        $filter = implode(';', $filters) . ';'
            . implode('', $mixInputs)
            . 'amix=inputs=' . count($mixInputs) . ':duration=first:normalize=0';

        $cmd = array_merge($cmd, [
            '-filter_complex', $filter,
            '-ac', '1', '-c:a', 'aac', '-b:a', '96k', '-f', 'adts', $writeFile,
        ]);

        // Dense-dialogue chunks can have 15+ TTS inputs; give ffmpeg ample time.
        $timeout = max(60, (int) ceil($chunkDur) * 2 + 30);
        $result  = Process::timeout($timeout)->run($cmd);

        if (!$result->successful() || !file_exists($writeFile) || filesize($writeFile) <= 10) {
            @unlink($writeFile);
            Log::warning("[DUB] bg-{$this->chunkIndex}.aac mix failed", [
                'session' => $this->sessionId,
                'error'   => Str::limit($result->errorOutput(), 300),
            ]);
            return;
        }

        // Atomic replace: outFile is only visible once fully written
        rename($writeFile, $outFile);

        Log::info(
            "[DUB] bg-{$this->chunkIndex}.aac ready ("
            . round($this->startTime) . '-' . round($this->endTime) . 's'
            . ', tts=' . ($inputIdx - 2) . ')',
            ['session' => $this->sessionId]
        );

        $this->checkPlayable($aacDir);
    }

    private function checkPlayable(string $aacDir): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session) return;

        // First two mixed chunks at/after the HLS dub start = enough buffer to switch.
        $dubStartTime = max(0.0, (float) ($session['hls_dub_start_time'] ?? 0.0));
        $bgHashData = Redis::hgetall(DubSession::bgChunksKey($this->sessionId)) ?? [];
        ksort($bgHashData, SORT_NUMERIC);

        $buffered = 0;
        $lastIdx = null;
        foreach ($bgHashData as $bgIdx => $bgJson) {
            $bgIdx = (int) $bgIdx;
            $bg = json_decode($bgJson, true);
            $start = (float) ($bg['start'] ?? ($bgIdx * 30.0));
            $end = (float) ($bg['end'] ?? (($bgIdx + 1) * 30.0));
            if ($end <= $dubStartTime) continue;
            if ($lastIdx === null) {
                if ($dubStartTime <= 0.25 && $bgIdx !== 0) return;
                if ($dubStartTime > 0.25 && $start > $dubStartTime + 0.05) return;
            }
            if ($lastIdx !== null && $bgIdx !== $lastIdx + 1) return;

            $f = "{$aacDir}/bg-{$bgIdx}.aac";
            if (!file_exists($f) || filesize($f) <= 10) return;

            $buffered++;
            $lastIdx = $bgIdx;
            if ($buffered >= 2) break;
        }
        if ($buffered < 2) return;

        $ttl = DubSession::TTL;
        $lua = <<<LUA
            local data = redis.call('GET', KEYS[1])
            if not data then return 0 end
            local session = cjson.decode(data)
            if not session['playable'] then
                session['playable'] = true
                redis.call('SETEX', KEYS[1], {$ttl}, cjson.encode(session))
                return 1
            end
            return 0
        LUA;

        $fired = Redis::eval($lua, 1, DubSession::key($this->sessionId));
        if ($fired) {
            Log::info("[DUB] playable=true (bg-0+bg-1 ready)", ['session' => $this->sessionId]);
        }
    }
}
