<?php

namespace App\Jobs;

use App\Services\WaveDispatcher;
use App\Support\DubSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Dispatches translation + TTS for a single "wave" of segments (5-minute time window).
 *
 * Waves enable instant-dub for movies of any length: only wave 0 starts immediately,
 * subsequent waves are dispatched as playback progresses, keeping the pipeline ahead
 * of the playback cursor without overwhelming the queue with 1000+ segments at once.
 *
 * The actual job fan-out lives in WaveDispatcher (shared with PrepareInstantDubJob,
 * which dispatches wave 0 in-process).
 */
class DispatchWaveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    // language/translateFrom/globalSegmentOffset defaults exist only so jobs
    // queued by the previous release still unserialize and run during deploy.
    public function __construct(
        public string $sessionId,
        public int    $waveIndex,
        public string $language = '',
        public string $translateFrom = '',
        public int    $globalSegmentOffset = -1,
    ) {}

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['status'] ?? '') === 'stopped') return;

        $title = $session['title'] ?? 'Untitled';

        $waveKey = DubSession::waveKey($this->sessionId, $this->waveIndex);
        $waveJson = Redis::get($waveKey);
        if (!$waveJson) {
            Log::warning("[DUB] [{$title}] Wave {$this->waveIndex} data missing from Redis", [
                'session' => $this->sessionId,
            ]);
            return;
        }

        $payload = json_decode($waveJson, true);
        // New format: {offset: N, segments: [...]} — legacy sessions store a bare
        // segment array with the offset in a ":offset" sibling key.
        $segments = $payload['segments'] ?? $payload;
        $offset = $payload['offset']
            ?? ($this->globalSegmentOffset >= 0
                ? $this->globalSegmentOffset
                : (int) Redis::get($waveKey . ':offset'));

        if (empty($segments)) return;

        $language = $this->language !== '' ? $this->language : (string) ($session['language'] ?? 'uz');
        $translateFrom = $this->translateFrom !== ''
            ? $this->translateFrom
            : (string) ($session['translate_from'] ?? ($session['detected_language'] ?? ''));

        (new WaveDispatcher())->dispatch(
            $this->sessionId,
            $this->waveIndex,
            array_values($segments),
            (int) $offset,
            $language,
            $translateFrom,
        );

        // Segments now live in batch keys (or queued TTS jobs) — drop the wave payload.
        Redis::del($waveKey);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[DUB] DispatchWaveJob wave {$this->waveIndex} failed: " . $exception->getMessage(), [
            'session' => $this->sessionId,
        ]);
    }
}
