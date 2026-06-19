<?php

namespace App\Jobs;

use App\Support\AudioFrame;
use App\Support\DubSession;
use App\Support\InstantDubHlsReadiness;
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
        $aacDir   = DubSession::aacDir($this->sessionId, $session);

        if (!is_dir($aacDir)) @mkdir($aacDir, 0755, true);

        $chunkDur = round(AudioFrame::alignedDuration($this->startTime, $this->endTime), 6);
        $outFile  = "{$aacDir}/bg-{$this->chunkIndex}.ts";
        // Write to a temp file, then atomically rename to outFile.
        // This prevents hlsAudioPlaylist from seeing a 0-byte file mid-write
        // (ffmpeg -y truncates the output file before writing), which would
        // cause the HLS EVENT playlist to shrink — a fatal spec violation.
        $writeFile = "{$aacDir}/bg-{$this->chunkIndex}.ts.tmp";

        $coverage = InstantDubHlsReadiness::chunkMixCoverage(
            $this->sessionId,
            $session,
            $this->startTime,
            $this->endTime,
        );

        if (!$coverage['can_mix']) {
            if (InstantDubHlsReadiness::chunkHasVerifiedDub($this->sessionId, $session, $this->chunkIndex, null, $aacDir)) {
                @unlink($writeFile);
                Log::info("[DUB] bg-{$this->chunkIndex}.ts already verified; ignoring later waiting coverage", [
                    'session' => $this->sessionId,
                    'expected_speech' => $coverage['expected_speech'],
                    'ready_speech' => $coverage['ready_speech'],
                    'missing_segment_plan' => !empty($coverage['missing_segment_plan']),
                    'missing' => array_slice($coverage['missing_indexes'] ?? [], 0, 12),
                ]);
                $this->checkPlayable($aacDir);
                return;
            }

            InstantDubHlsReadiness::markDubChunkWaiting($this->sessionId, $this->chunkIndex, $coverage);
            @unlink($outFile);
            @unlink($writeFile);
            Log::info("[DUB] bg-{$this->chunkIndex}.ts waiting for TTS coverage", [
                'session' => $this->sessionId,
                'expected_speech' => $coverage['expected_speech'],
                'ready_speech' => $coverage['ready_speech'],
                'missing_segment_plan' => !empty($coverage['missing_segment_plan']),
                'missing' => array_slice($coverage['missing_indexes'] ?? [], 0, 12),
            ]);
            return;
        }

        // Detect channel count of the bg audio chunk
        $chanProbe = Process::timeout(5)->run([
            'ffprobe', '-v', 'error', '-select_streams', 'a:0',
            '-show_entries', 'stream=channels',
            '-of', 'default=nw=1:nk=1', $bgAudioPath,
        ]);
        $bgChannels = (int) trim($chanProbe->output());

        $bgFilter = $this->backgroundFilterForMix($bgChannels, (int) ($coverage['expected_speech'] ?? 0));

        $cmd = [
            'ffmpeg', '-y',
            '-f', 'lavfi', '-t', (string) $chunkDur, '-i', 'anullsrc=r=44100:cl=stereo',
            '-t', (string) $chunkDur, '-i', $bgAudioPath,
        ];
        $filters   = [$bgFilter];
        $mixInputs = ['[0:a]', '[bg]'];
        $inputIdx  = 2;

        foreach (($coverage['ready_chunks'] ?? []) as $chunk) {
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
            '-ac', '1', '-c:a', 'aac', '-b:a', '96k',
            '-muxdelay', '0', '-muxpreload', '0',
            '-f', 'mpegts', $writeFile,
        ]);

        // Dense-dialogue chunks can have 15+ TTS inputs; give ffmpeg ample time.
        $timeout = max(60, (int) ceil($chunkDur) * 2 + 30);
        $result  = Process::timeout($timeout)->run($cmd);

        if (!$result->successful() || !file_exists($writeFile) || filesize($writeFile) <= 10) {
            @unlink($writeFile);
            Log::warning("[DUB] bg-{$this->chunkIndex}.ts mix failed", [
                'session' => $this->sessionId,
                'error'   => Str::limit($result->errorOutput(), 300),
            ]);
            return;
        }

        // Atomic replace: outFile is only visible once fully written
        rename($writeFile, $outFile);
        $ttsInputs = $inputIdx - 2;

        InstantDubHlsReadiness::markDubChunkReady($this->sessionId, $this->chunkIndex, $coverage, $ttsInputs);

        Log::info(
            "[DUB] bg-{$this->chunkIndex}.ts ready ("
            . round($this->startTime) . '-' . round($this->endTime) . 's'
            . ', tts=' . $ttsInputs . ')',
            ['session' => $this->sessionId]
        );

        $this->checkPlayable($aacDir);
    }

    private function backgroundFilterForMix(int $bgChannels, int $expectedSpeech): string
    {
        if ($expectedSpeech <= 0) {
            return '[1:a]volume=1.0,aresample=44100[bg]';
        }

        if ($bgChannels >= 2) {
            return '[1:a]pan=stereo|c0=c0-0.95*c1|c1=c1-0.95*c0,volume=0.18,aresample=44100[bg]';
        }

        return '[1:a]volume=0.0,aresample=44100[bg]';
    }

    private function checkPlayable(string $aacDir): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session) return;

        $window = InstantDubHlsReadiness::readyWindow($this->sessionId, $session, $aacDir);
        $this->markCompleteIfFullyMixed($session, $window);
        if (empty($window['ready'])) return;

        $ttl = DubSession::TTL;
        $lua = <<<LUA
            local data = redis.call('GET', KEYS[1])
            if not data then return 0 end
            local session = cjson.decode(data)
            if not session['playable'] then
                session['playable'] = true
                session['hls_switch_verified'] = true
                session['hls_verified_format'] = 'ts'
                redis.call('SETEX', KEYS[1], {$ttl}, cjson.encode(session))
                return 1
            end
            session['hls_switch_verified'] = true
            session['hls_verified_format'] = 'ts'
            redis.call('SETEX', KEYS[1], {$ttl}, cjson.encode(session))
            return 0
        LUA;

        $fired = Redis::eval($lua, 1, DubSession::key($this->sessionId));
        if ($fired) {
            Log::info("[DUB] playable=true (verified HLS dub runway ready)", [
                'session' => $this->sessionId,
                'ready_seconds' => round((float) $window['ready_seconds'], 1),
                'required_seconds' => round((float) $window['required_seconds'], 1),
                'last_ready_bg_idx' => $window['last_ready_bg_idx'],
            ]);
        }
    }

    private function markCompleteIfFullyMixed(array $session, array $window): void
    {
        if (empty($window['complete'])) {
            return;
        }

        $ready = (int) ($session['segments_ready'] ?? 0);
        $total = (int) ($session['total_segments'] ?? 0);
        if ($total <= 0 || $ready < $total) {
            return;
        }

        DubSession::patch($this->sessionId, [
            'status' => 'complete',
            'playable' => true,
            'hls_switch_verified' => true,
            'hls_verified_format' => 'ts',
            'hls_ready_seconds' => round((float) $window['ready_seconds'], 3),
            'hls_required_seconds' => round((float) $window['required_seconds'], 3),
            'hls_continuous_until' => round((float) $window['continuous_until'], 3),
            'hls_last_ready_bg_idx' => $window['last_ready_bg_idx'],
        ]);

        PersistDubCacheJob::dispatch($this->sessionId)->onQueue('default');
    }
}
