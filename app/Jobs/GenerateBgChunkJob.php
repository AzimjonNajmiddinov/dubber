<?php

namespace App\Jobs;

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
 * Mixes one 32-second background chunk: center-cancelled original + TTS overlays.
 *
 * ShouldBeUnique prevents the cascade problem: if 8 TTS completions trigger this job
 * for the same chunk, only the first dispatch is queued; the rest are dropped.
 * The 3-second dispatch delay (set by callers) lets multiple TTS arrive before the
 * job actually runs, so one ffmpeg call covers all of them.
 */
class GenerateBgChunkJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout  = 120;
    public int $tries    = 2;
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
        $sessionKey  = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;

        $session = json_decode($sessionJson, true);
        if (($session['status'] ?? '') === 'stopped') return;

        $bgChunkJson = Redis::hget("instant-dub:{$this->sessionId}:bgchunks", (string) $this->chunkIndex);
        if (!$bgChunkJson) return;

        $bgAudioPath = json_decode($bgChunkJson, true)['path'] ?? null;
        if (!$bgAudioPath || !file_exists($bgAudioPath)) return;

        $lock = Cache::lock("bg-gen:{$this->sessionId}:{$this->chunkIndex}", 90);
        if (!$lock->get()) return;

        try {
            $this->mix($bgAudioPath, $session);
        } finally {
            $lock->release();
        }
    }

    private function mix(string $bgAudioPath, array $session): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $total      = (int) ($session['total_segments'] ?? 0);

        $aacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");
        if (!is_dir($aacDir)) @mkdir($aacDir, 0755, true);

        $chunkDur = round($this->frameAlignedDuration($this->startTime, $this->endTime), 6);
        $outFile  = "{$aacDir}/bg-{$this->chunkIndex}.aac";

        // Stereo center cancellation: dialogue is center-panned (L≈R), so 70% of it is removed.
        // Volume boosted 1.5x to compensate for the energy lost from cancellation.
        $cmd = [
            'ffmpeg', '-y',
            '-f', 'lavfi', '-t', (string) $chunkDur, '-i', 'anullsrc=r=44100:cl=stereo',
            '-t', (string) $chunkDur, '-i', $bgAudioPath,
        ];
        $filters   = ['[1:a]pan=stereo|c0=c0-0.88*c1|c1=c1-0.88*c0,volume=1.0,aresample=44100[bg]'];
        $mixInputs = ['[0:a]', '[bg]'];
        $inputIdx  = 2;
        $tmpFiles  = [];

        // Fetch all TTS chunks in one Redis pipeline
        $chunkKeys = array_map(
            fn($i) => "{$sessionKey}:chunk:{$i}",
            range(0, max(0, $total - 1))
        );
        $chunkValues = $total > 0 ? Redis::mget($chunkKeys) : [];

        foreach ($chunkValues as $i => $chunkJson) {
            if (!$chunkJson) continue;
            $chunk    = json_decode($chunkJson, true);
            $segStart = (float) ($chunk['start_time'] ?? 0);
            $segEnd   = (float) ($chunk['end_time']   ?? 0);
            $b64      = $chunk['audio_base64']         ?? null;

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
            $filters[]   = "[{$inputIdx}:a]adelay={$ttsDelayMs}|{$ttsDelayMs},volume=3.0,aresample=44100[tts{$inputIdx}]";
            $mixInputs[] = "[tts{$inputIdx}]";
            $inputIdx++;
        }

        $filter = implode(';', $filters) . ';' . implode('', $mixInputs)
            . 'amix=inputs=' . count($mixInputs) . ':duration=first:normalize=0';

        $cmd = array_merge($cmd, [
            '-filter_complex', $filter,
            '-ac', '1', '-c:a', 'aac', '-b:a', '96k', '-f', 'adts', $outFile,
        ]);

        $timeout = max(20, (int) ceil($chunkDur) + 15);
        $result  = Process::timeout($timeout)->run($cmd);

        foreach ($tmpFiles as $f) @unlink($f);

        if (!$result->successful()) {
            Log::warning("[DUB] bg-{$this->chunkIndex}.aac mix failed", [
                'session' => $this->sessionId,
                'error'   => Str::limit($result->errorOutput(), 300),
            ]);
            return;
        }

        Log::info(
            "[DUB] bg-{$this->chunkIndex}.aac ready (" . round($this->startTime) . '-' . round($this->endTime) . 's, tts=' . ($inputIdx - 2) . ')',
            ['session' => $this->sessionId]
        );

        $this->checkPlayable($aacDir);
    }

    private function checkPlayable(string $aacDir): void
    {
        // bg-0 + bg-1 ready = 64s buffer. Single authoritative place for playable=true.
        for ($bi = 0; $bi <= 1; $bi++) {
            $f = "{$aacDir}/bg-{$bi}.aac";
            if (!file_exists($f) || filesize($f) <= 10) return;
        }

        $lua = <<<'LUA'
            local data = redis.call('GET', KEYS[1])
            if not data then return 0 end
            local session = cjson.decode(data)
            if not session['playable'] then
                session['playable'] = true
                redis.call('SETEX', KEYS[1], 50400, cjson.encode(session))
                return 1
            end
            return 0
        LUA;

        $fired = Redis::eval($lua, 1, "instant-dub:{$this->sessionId}");
        if ($fired) {
            Log::info("[DUB] playable=true (bg-0+bg-1 ready)", ['session' => $this->sessionId]);
        }
    }

    private function frameAlignedDuration(float $start, float $end): float
    {
        $sr              = 44100;
        $frameSize       = 1024;
        $durationSamples = (int) round(($end - $start) * $sr);
        $alignedSamples  = (int) ceil($durationSamples / $frameSize) * $frameSize;
        return $alignedSamples / $sr;
    }
}
