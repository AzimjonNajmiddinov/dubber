<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\DispatchWaveJob;
use App\Jobs\GenerateBgChunkJob;
use App\Jobs\PersistDubCacheJob;
use App\Services\TextNormalizer;
use App\Support\DubSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ProcessInstantDubSegmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;
    public int $tries = 4; // Allow retries to survive multiple horizon restarts during deploys

    public function __construct(
        public string  $sessionId,
        public int     $index,
        public string  $text,
        public float   $startTime,
        public float   $endTime,
        public string  $language,
        public string  $speaker = 'M1',
        public ?float  $slotEnd = null,
        public ?string $sourceText = null,
        public ?string $delivery = null, // "emotion|pace" e.g. "angry|fast"
        public int     $waveIndex = 0,
    ) {}

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['status'] ?? '') === 'stopped') {
            return;
        }

        // Strip delivery hints that may have leaked through translation ({calm|slow} or {emotion:calm|pace:slow})
        $this->text = trim(preg_replace('/\s*\{(?:emotion:)?[a-z]+\|(?:pace:)?[a-z]+\}\s*$/i', '', trim($this->text)));

        $title = $session['title'] ?? 'Untitled';

        try {
            $slotDuration = $this->endTime - $this->startTime;
            $tmpDir = '/tmp/instant-dub-' . $this->sessionId;
            @mkdir($tmpDir, 0755, true);

            if ($this->isSilentPlaceholderText($this->text)) {
                $silentMp3 = $this->generateSilentTtsPlaceholder($tmpDir, $slotDuration);
                $silentDuration = $this->getAudioDuration($silentMp3) ?: max(0.25, $slotDuration);
                $this->storeReadyChunkAndDispatch($silentMp3, $silentDuration, [
                    'translation_missing' => true,
                ], false);

                Log::warning("[DUB] [{$title}] Segment #{$this->index} has no speakable translated text; using silent placeholder", [
                    'session' => $this->sessionId,
                ]);
                return;
            }

            // 1. Generate Edge TTS audio for the current instant-dub flow.
            $rawMp3 = "{$tmpDir}/seg_{$this->index}.mp3";

            // Read per-speaker Edge voice entry from voice map.
            $voiceMap     = json_decode(Redis::get(DubSession::voicesKey($this->sessionId)), true) ?? [];
            $speakerEntry = $voiceMap[$this->speaker] ?? [];
            if (!is_array($speakerEntry) || (!empty($speakerEntry['driver']) && $speakerEntry['driver'] !== 'edge')) {
                $speakerEntry = [];
            }

            try {
                $this->generateWithEdgeTts($rawMp3, $tmpDir, $speakerEntry);
            } catch (\Throwable $ttsEx) {
                try {
                    $silentMp3 = $this->generateSilentTtsPlaceholder($tmpDir, $slotDuration);
                    $silentDuration = $this->getAudioDuration($silentMp3) ?: max(0.25, $slotDuration);
                    $this->storeReadyChunkAndDispatch($silentMp3, $silentDuration, [
                        'tts_failed' => true,
                        'tts_error' => Str::limit($ttsEx->getMessage(), 300),
                    ], false);

                    Log::warning("[DUB] [{$title}] Edge TTS unavailable for seg #{$this->index}; using silent placeholder: " . Str::limit($ttsEx->getMessage(), 200), [
                        'session' => $this->sessionId,
                    ]);
                } catch (\Throwable $fallbackEx) {
                    DubSession::patch($this->sessionId, [
                        'status' => 'error',
                        'error' => 'TTS failed: ' . Str::limit($ttsEx->getMessage(), 300),
                    ]);
                    Log::error("[DUB] [{$title}] Edge TTS unavailable for seg #{$this->index}; fallback placeholder failed: " . $fallbackEx->getMessage(), [
                        'session' => $this->sessionId,
                    ]);
                }
                return;
            }

            // 2. Adjust tempo so Edge TTS fills the timeslot naturally.
            $ttsDuration = $this->getAudioDuration($rawMp3);
            $finalMp3 = $rawMp3;

            if ($slotDuration > 0.5 && $ttsDuration > 0.1) {
                $ratio = $ttsDuration / $slotDuration;
                $tempo = null;

                if ($ratio > 1.05) {
                    $tempo = min($ratio, 1.35);
                } elseif ($ratio < 0.9) {
                    $targetDuration = $slotDuration * 0.95;
                    $tempo = max($ttsDuration / $targetDuration, 0.8);
                }

                if ($tempo !== null) {
                    $adjustedMp3 = "{$tmpDir}/seg_{$this->index}_adj.mp3";
                    $result = Process::timeout(15)->run([
                        'ffmpeg', '-y', '-i', $rawMp3,
                        '-filter:a', "atempo={$tempo}",
                        '-codec:a', 'libmp3lame', '-b:a', '128k',
                        $adjustedMp3,
                    ]);

                    if ($result->successful() && file_exists($adjustedMp3) && filesize($adjustedMp3) > 200) {
                        @unlink($rawMp3);
                        $finalMp3 = $adjustedMp3;
                        $ttsDuration = $this->getAudioDuration($finalMp3);
                    }
                }
            }

            $this->storeReadyChunkAndDispatch($finalMp3, $ttsDuration);

            Log::info("[DUB] [{$title}] Segment #{$this->index} ready ({$this->speaker}, " . round($ttsDuration, 2) . "s): " . Str::limit($this->text, 60), [
                'session' => $this->sessionId,
            ]);

        } catch (\Throwable $e) {
            DubSession::patch($this->sessionId, [
                'status' => 'error',
                'error' => 'TTS segment failed: ' . Str::limit($e->getMessage(), 120),
            ]);
            Log::error("[DUB] [{$title}] Segment #{$this->index} FAILED: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        DubSession::patch($this->sessionId, [
            'status' => 'error',
            'error' => 'TTS segment failed after retries: ' . Str::limit($exception->getMessage(), 120),
        ]);

        Log::error("[DUB] Segment #{$this->index} killed by worker: " . Str::limit($exception->getMessage(), 100), [
            'session' => $this->sessionId,
        ]);
    }

    private function storeReadyChunkAndDispatch(
        string $finalMp3,
        float $ttsDuration,
        array $extra = [],
        bool $applyEnergy = true,
    ): string {
        $audioDir = DubSession::audioDir($this->sessionId);
        @mkdir($audioDir, 0755, true);

        $ext = pathinfo($finalMp3, PATHINFO_EXTENSION) ?: 'mp3';
        $audioPath = "{$audioDir}/seg_{$this->index}.{$ext}";
        @unlink($audioPath);
        if (!@rename($finalMp3, $audioPath)) {
            throw new \RuntimeException("Unable to store TTS audio for segment #{$this->index}");
        }

        $payload = array_merge([
            'index'          => $this->index,
            'start_time'     => $this->startTime,
            'end_time'       => $this->endTime,
            'slot_end'       => $this->slotEnd,
            'text'           => $this->text,
            'source_text'    => $this->sourceText,
            'speaker'        => $this->speaker,
            'audio_path'     => $audioPath,
            'audio_duration' => $ttsDuration,
        ], $extra);

        Redis::setex(DubSession::chunkKey($this->sessionId, $this->index), DubSession::TTL, json_encode($payload));

        if ($applyEnergy) {
            $this->applyEnergyTransferIfBgReady();
        }

        $this->dispatchBgChunksForSegment();
        $this->incrementReady();
        $this->dispatchPersistIfComplete();

        return $audioPath;
    }

    private function dispatchBgChunksForSegment(): void
    {
        // ShouldBeUnique + 3s delay collapses concurrent TTS completions into one ffmpeg run.
        $bgHashData = Redis::hgetall(DubSession::bgChunksKey($this->sessionId)) ?? [];
        foreach ($bgHashData as $bgIdx => $bgJson) {
            $bg = json_decode($bgJson, true);
            $cs = (float) ($bg['start'] ?? 0);
            $ce = (float) ($bg['end'] ?? 0);
            if ($this->startTime < $ce && $this->endTime > $cs) {
                GenerateBgChunkJob::dispatch($this->sessionId, (int) $bgIdx, $cs, $ce)
                    ->onQueue('bg-mix')
                    ->delay(now()->addSeconds(3));
            }
        }
    }

    private function generateSilentTtsPlaceholder(string $tmpDir, float $duration): string
    {
        $duration = max(0.25, min(30.0, $duration));
        $outputMp3 = "{$tmpDir}/seg_{$this->index}_silent.mp3";

        $result = Process::timeout(10)->run([
            'ffmpeg', '-y',
            '-f', 'lavfi',
            '-t', (string) round($duration, 3),
            '-i', 'anullsrc=r=44100:cl=mono',
            '-codec:a', 'libmp3lame',
            '-b:a', '64k',
            $outputMp3,
        ]);

        if (!$result->successful() || !file_exists($outputMp3) || filesize($outputMp3) <= 100) {
            @unlink($outputMp3);
            throw new \RuntimeException('Unable to generate silent TTS placeholder: ' . Str::limit($result->errorOutput(), 200));
        }

        return $outputMp3;
    }

    private function generateWithEdgeTts(string $outputMp3, string $tmpDir, array $speakerEntry = []): void
    {
        if (!empty($speakerEntry['voice'])) {
            $voice = $speakerEntry['voice'];
            $pitch = $speakerEntry['pitch'] ?? '+0Hz';
            $rate = $speakerEntry['rate'] ?? '+0%';
        } else {
            $voice = $this->getDefaultEdgeVoice();
            $pitch = '+0Hz';
            $rate = '+0%';
        }

        $text = TextNormalizer::normalize($this->text, $this->language);
        $edgeTts = $this->resolveEdgeTts();

        // Try up to 5 times with increasing delays for network resilience
        $attempts = [
            ['voice' => $voice, 'pitch' => $pitch, 'rate' => $rate, 'delay' => 0],
            ['voice' => $voice, 'pitch' => $pitch, 'rate' => $rate, 'delay' => 1000],
            ['voice' => $voice, 'pitch' => '+0Hz', 'rate' => '+0%', 'delay' => 2000],
            ['voice' => $this->getDefaultEdgeVoice(), 'pitch' => '+0Hz', 'rate' => '+0%', 'delay' => 3000],
            ['voice' => $this->getDefaultEdgeVoice(), 'pitch' => '+0Hz', 'rate' => '+0%', 'delay' => 5000],
        ];

        // Deduplicate but keep extra retries for network errors
        $lastError = '';
        foreach ($attempts as $i => $attempt) {
            if ($attempt['delay'] > 0) {
                usleep($attempt['delay'] * 1000);
            }

            $tmpTxt = "{$tmpDir}/text_{$this->index}.txt";
            file_put_contents($tmpTxt, $text);

            $cmd = [
                $edgeTts, '-f', $tmpTxt,
                '--voice', $attempt['voice'],
                "--pitch={$attempt['pitch']}",
                "--rate={$attempt['rate']}",
                '--write-media', $outputMp3,
            ];

            $result = Process::timeout(30)->run($cmd);
            @unlink($tmpTxt);

            if ($result->successful() && file_exists($outputMp3) && filesize($outputMp3) >= 500) {
                return; // Success
            }

            $lastError = Str::limit($result->errorOutput() ?: $result->output(), 300);
            @unlink($outputMp3);

            Log::warning("[DUB] Segment #{$this->index} Edge TTS attempt " . ($i + 1) . " failed ({$attempt['voice']}), retrying", [
                'session' => $this->sessionId,
            ]);
        }

        throw new \RuntimeException('Edge TTS failed after all retries (bin=' . $edgeTts . '): ' . $lastError);
    }

    /**
     * Segment vaqtiga mos vocals referensini topib, prosody-transfer-service ga yuboradi.
     * Muvaffaqiyatli bo'lsa transfer qilingan WAV faylini qaytaradi, aks holda null.
     */
    private function applyEnergyTransferIfBgReady(): void
    {
        $serviceUrl = config('services.prosody_transfer.url') ?: env('PROSODY_TRANSFER_SERVICE_URL');
        if (!$serviceUrl) return;

        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['disable_prosody'] ?? false)) return;

        $bgPath = null; $bgStart = null; $bgEnd = null;
        $bgHashData = Redis::hgetall(DubSession::bgChunksKey($this->sessionId)) ?? [];
        foreach ($bgHashData as $bgJson) {
            $bg   = json_decode($bgJson, true);
            $cs   = (float) ($bg['start'] ?? 0);
            $ce   = (float) ($bg['end']   ?? 0);
            $path = $bg['path'] ?? null;
            if ($path && file_exists($path) && $this->startTime >= $cs && $this->startTime < $ce) {
                $bgPath = $path; $bgStart = $cs; $bgEnd = $ce;
                break;
            }
        }
        if (!$bgPath) return;

        $chunkKey  = DubSession::chunkKey($this->sessionId, $this->index);
        $chunkJson = Redis::get($chunkKey);
        if (!$chunkJson) return;
        $chunk     = json_decode($chunkJson, true);
        $audioPath = $chunk['audio_path'] ?? null;
        if (!$audioPath || !file_exists($audioPath)) return;

        $tmpDir = '/tmp/instant-dub-' . $this->sessionId;
        @mkdir($tmpDir, 0755, true);

        try {
            $ttsWav = "{$tmpDir}/et_{$this->index}_tts.wav";
            $conv = Process::timeout(10)->run([
                'ffmpeg', '-y', '-i', $audioPath, '-ar', '44100', '-ac', '1', '-f', 'wav', $ttsWav,
            ]);
            if (!$conv->successful() || !file_exists($ttsWav) || filesize($ttsWav) < 100) return;

            $segOffset    = max(0.0, $this->startTime - $bgStart);
            $availableRef = max(0.0, ($bgEnd - $bgStart) - $segOffset);
            if ($availableRef < 0.15) { @unlink($ttsWav); return; }

            $refDur  = min($this->endTime - $this->startTime, $availableRef);
            $refClip = "{$tmpDir}/et_{$this->index}_ref.wav";
            $cut = Process::timeout(10)->run([
                'ffmpeg', '-y',
                '-ss', (string) round($segOffset, 3),
                '-t',  (string) round($refDur, 3),
                '-i',  $bgPath,
                '-af', 'highpass=f=300,lowpass=f=3400',
                '-ar', '44100', '-ac', '1',
                $refClip,
            ]);
            if (!$cut->successful() || !file_exists($refClip) || filesize($refClip) < 100) {
                @unlink($ttsWav); return;
            }

            $resp = \Illuminate\Support\Facades\Http::timeout(30)
                ->attach('tts_audio', file_get_contents($ttsWav), 'tts.wav')
                ->attach('reference', file_get_contents($refClip), 'ref.wav')
                ->post(rtrim($serviceUrl, '/') . '/transfer');

            @unlink($ttsWav); @unlink($refClip);
            if ($resp->failed()) return;

            $outWav = "{$tmpDir}/et_{$this->index}_out.wav";
            $outMp3 = "{$tmpDir}/et_{$this->index}_out.mp3";
            file_put_contents($outWav, $resp->body());
            if (!file_exists($outWav) || filesize($outWav) < 1000) { @unlink($outWav); return; }

            $enc = Process::timeout(10)->run([
                'ffmpeg', '-y', '-i', $outWav, '-codec:a', 'libmp3lame', '-b:a', '128k', $outMp3,
            ]);
            @unlink($outWav);
            if (!$enc->successful() || !file_exists($outMp3) || filesize($outMp3) < 500) {
                @unlink($outMp3); return;
            }

            // Replace the original audio file with the energy-transferred version
            @unlink($audioPath);
            rename($outMp3, $audioPath);

            // Update chunk in Redis if the path/extension changed (e.g. wav → mp3)
            if (($chunk['audio_path'] ?? '') !== $audioPath) {
                $chunk['audio_path'] = $audioPath;
                Redis::setex($chunkKey, DubSession::TTL, json_encode($chunk));
            }

            Log::info("[DUB] Energy transfer ok — seg #{$this->index}", ['session' => $this->sessionId]);

        } catch (\Throwable $e) {
            Log::warning("[DUB] Energy transfer failed — seg #{$this->index}: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
            foreach ([
                "{$tmpDir}/et_{$this->index}.mp3",
                "{$tmpDir}/et_{$this->index}_tts.wav",
                "{$tmpDir}/et_{$this->index}_ref.wav",
                "{$tmpDir}/et_{$this->index}_out.wav",
                "{$tmpDir}/et_{$this->index}_out.mp3",
            ] as $f) @unlink($f);
        }
    }

    private function resolveEdgeTts(): string
    {
        // Check standard PATH first
        $which = trim(shell_exec('which edge-tts 2>/dev/null') ?? '');
        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        // Check common user-install locations (pip install --user, pipx)
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/root');
        $candidates = [
            "{$home}/.local/bin/edge-tts",
            "{$home}/bin/edge-tts",
            '/usr/local/bin/edge-tts',
            '/opt/pipx/bin/edge-tts',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Fallback — let the OS try to find it (will fail with clear error)
        return 'edge-tts';
    }

    private function getDefaultEdgeVoice(): string
    {
        $voices = [
            'uz' => 'uz-UZ-SardorNeural',
            'ru' => 'ru-RU-DmitryNeural',
            'en' => 'en-US-GuyNeural',
            'es' => 'es-ES-AlvaroNeural',
            'fr' => 'fr-FR-HenriNeural',
            'de' => 'de-DE-ConradNeural',
            'tr' => 'tr-TR-AhmetNeural',
            'ar' => 'ar-SA-HamedNeural',
            'zh' => 'zh-CN-YunxiNeural',
            'ja' => 'ja-JP-KeitaNeural',
            'ko' => 'ko-KR-InJoonNeural',
        ];

        return $voices[$this->language] ?? 'uz-UZ-SardorNeural';
    }

    private function getAudioDuration(string $path): float
    {
        $result = Process::timeout(10)->run([
            'ffprobe', '-hide_banner', '-loglevel', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1',
            $path,
        ]);

        return (float) trim($result->output());
    }

    private function dispatchPersistIfComplete(): void
    {
        $s = DubSession::get($this->sessionId);
        if ($s && ($s['status'] ?? '') === 'complete') {
            PersistDubCacheJob::dispatch($this->sessionId)->onQueue('default');
        }
    }

    private function incrementReady(): void
    {
        $sessionKey = DubSession::key($this->sessionId);

        // Atomic increment + completion check via Lua.
        // playable=true is only set here when no video-backed bg audio is expected.
        // When bg audio is present, GenerateBgChunkJob.checkPlayable() sets playable=true
        // only after a verified contiguous HLS dub runway is ready.
        $ttl = DubSession::TTL;
        $lua = <<<LUA
            local data = redis.call('GET', KEYS[1])
            if not data then return 0 end
            local session = cjson.decode(data)
            session['segments_ready'] = (session['segments_ready'] or 0) + 1
            session['last_progress_at'] = tonumber(ARGV[1])
            local total = session['total_segments'] or 999999
            if session['segments_ready'] >= total then
                local totalBg = session['total_bg_chunks']
                local expectsBg = session['video_url'] ~= nil and session['video_url'] ~= ''
                if not expectsBg or (totalBg and totalBg == 0) then
                    session['status'] = 'complete'
                    session['playable'] = true
                end
            end
            redis.call('SETEX', KEYS[1], {$ttl}, cjson.encode(session))

            return session['segments_ready']
        LUA;

        Redis::eval($lua, 1, $sessionKey, now()->timestamp);

        // Trigger next wave dispatch if this segment's wave is nearly done
        $this->dispatchNextWaveIfReady();
    }

    /**
     * Wave waterfall trigger: when 80% of a wave's segments are ready,
     * dispatch the next wave. This keeps the pipeline ahead of playback.
     */
    private function dispatchNextWaveIfReady(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session) return;

        $totalWaves = (int) ($session['total_waves'] ?? 0);
        if ($totalWaves <= 1) return; // single wave — nothing to dispatch

        $waveIndex = $this->waveIndex;

        // Read wave progress
        $progressKey = DubSession::waveProgressKey($this->sessionId, $waveIndex);
        $progressJson = Redis::get($progressKey);
        if (!$progressJson) return;

        $progress = json_decode($progressJson, true);
        $waveTotal = (int) ($progress['total'] ?? 0);
        if ($waveTotal === 0) return;

        // Atomic increment wave ready count
        $newReady = (int) Redis::incr($progressKey . ':ready');
        Redis::expire($progressKey . ':ready', DubSession::TTL);

        // Check if 80% threshold reached
        $threshold = (int) ceil($waveTotal * 0.8);
        if ($newReady < $threshold) return;

        // Only trigger once — check if next wave is already dispatched
        $nextWave = $waveIndex + 1;
        if ($nextWave >= $totalWaves) return;

        // Atomic check-and-set: only dispatch if not already dispatched
        $dispatched = (int) Redis::get(DubSession::wavesDispatchedKey($this->sessionId));
        if ($dispatched > $nextWave) return; // already dispatched

        // Try to claim this dispatch (atomic increment prevents double dispatch)
        $newDispatched = (int) Redis::incr(DubSession::wavesDispatchedKey($this->sessionId));
        Redis::expire(DubSession::wavesDispatchedKey($this->sessionId), DubSession::TTL);
        if ($newDispatched !== $nextWave + 1) return; // another segment already claimed it

        // Read wave offset
        $waveOffset = (int) Redis::get(DubSession::waveKey($this->sessionId, $nextWave) . ':offset');

        // Read language info from session
        $language = $session['language'] ?? 'uz';
        $translateFrom = $session['translate_from'] ?? ($session['detected_language'] ?? '');

        DispatchWaveJob::dispatch(
            $this->sessionId, $nextWave, $language, $translateFrom, $waveOffset,
        )->onQueue('segment-generation');

        Log::info("[DUB] Wave {$waveIndex} at {$newReady}/{$waveTotal} ({$threshold} threshold) — dispatched wave {$nextWave}", [
            'session' => $this->sessionId,
        ]);
    }

    private function isSilentPlaceholderText(string $text): bool
    {
        $text = trim(preg_replace('/\s*\{(?:emotion:)?[a-z]+\|(?:pace:)?[a-z]+\}\s*$/i', '', trim($text)) ?? '');
        if ($text === '') {
            return true;
        }

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) === '';
    }
}
