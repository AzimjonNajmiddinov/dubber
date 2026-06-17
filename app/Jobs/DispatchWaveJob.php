<?php

namespace App\Jobs;

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
 */
class DispatchWaveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(
        public string $sessionId,
        public int    $waveIndex,
        public string $language,
        public string $translateFrom,
        public int    $globalSegmentOffset, // first segment's global index in total_segments
    ) {}

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['status'] ?? '') === 'stopped') return;

        $title = $session['title'] ?? 'Untitled';

        // Load wave segments from Redis
        $waveKey = DubSession::waveKey($this->sessionId, $this->waveIndex);
        $waveJson = Redis::get($waveKey);
        if (!$waveJson) {
            Log::warning("[DUB] [{$title}] Wave {$this->waveIndex} data missing from Redis", [
                'session' => $this->sessionId,
            ]);
            return;
        }

        $segments = json_decode($waveJson, true);
        if (empty($segments)) return;

        // Mark this wave as dispatched (atomic increment)
        Redis::incr(DubSession::wavesDispatchedKey($this->sessionId));
        Redis::expire(DubSession::wavesDispatchedKey($this->sessionId), DubSession::TTL);

        // Store wave segment count for progress tracking
        Redis::setex(
            DubSession::waveProgressKey($this->sessionId, $this->waveIndex),
            DubSession::TTL,
            json_encode(['total' => count($segments), 'ready' => 0])
        );

        $needsTranslation = $this->translateFrom && $this->translateFrom !== $this->language;

        if (!$needsTranslation) {
            // No translation — dispatch TTS directly
            foreach ($segments as $i => $seg) {
                $text = trim($seg['text']);
                $text = trim(preg_replace('/\[[^\]]*\]\s*/', '', $text));
                $text = str_replace('`', '\'', $text);

                $globalIdx = $this->globalSegmentOffset + $i;
                $slotEnd = isset($segments[$i + 1]) ? $segments[$i + 1]['start'] : null;

                ProcessInstantDubSegmentJob::dispatch(
                    $this->sessionId, $globalIdx, $text,
                    $seg['start'], $seg['end'], $this->language,
                    $seg['speaker'] ?? 'M1',
                    $slotEnd,
                )->onQueue('segment-generation');
            }

            Log::info("[DUB] [{$title}] Wave {$this->waveIndex}: " . count($segments) . " segments dispatched (no translation)", [
                'session' => $this->sessionId,
            ]);
            return;
        }

        // Split into 15-segment translation batches
        $batches = array_chunk($segments, 15);
        $totalBatches = count($batches);

        // Store batches in Redis with wave-specific keys to avoid collision with wave 0
        $batchKeyPrefix = "instant-dub:{$this->sessionId}:w{$this->waveIndex}:batch";
        foreach ($batches as $batchIdx => $batch) {
            Redis::setex("{$batchKeyPrefix}:{$batchIdx}", DubSession::TTL, json_encode(array_values($batch)));
        }

        // Initialize batch counter for this wave
        Redis::setex("instant-dub:{$this->sessionId}:w{$this->waveIndex}:batches-remaining", DubSession::TTL, $totalBatches);

        // Dispatch translation batches — all parallel (character context from wave 0 already available)
        for ($i = 0; $i < $totalBatches; $i++) {
            TranslateInstantDubBatchJob::dispatch(
                $this->sessionId,
                $i,
                $totalBatches,
                $this->language,
                $this->translateFrom,
                $this->globalSegmentOffset,
                $this->waveIndex, // pass wave index for wave-specific batch keys
            )->onQueue('segment-generation');
        }

        // Dispatch bg audio download for this wave's time range
        $waveStart = (float) $segments[0]['start'];
        $waveEnd = (float) end($segments)['end'];
        $this->dispatchBgAudioForRange($waveStart, $waveEnd);

        // Clean up wave key (segments are now in batch keys)
        Redis::del($waveKey);

        Log::info("[DUB] [{$title}] Wave {$this->waveIndex}: " . count($segments) . " segments in {$totalBatches} batches dispatched ({$this->translateFrom}→{$this->language})", [
            'session' => $this->sessionId,
            'offset'  => $this->globalSegmentOffset,
        ]);
    }

    /**
     * Dispatch DownloadAudioChunkJob for bg audio chunks overlapping this wave's time range.
     * For HLS sources, reuses the audio segments already stored in Redis.
     * For YouTube, DownloadYouTubeWindowJob windows are already dispatched — we just need
     * the chunk jobs which are handled by the window job itself.
     */
    private function dispatchBgAudioForRange(float $rangeStart, float $rangeEnd): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session) return;

        $videoUrl = $session['video_url'] ?? '';

        // YouTube: bg audio is already handled by DownloadYouTubeWindowJob
        // which dispatches DownloadAudioChunkJob for each 30s slice automatically.
        if (str_contains($videoUrl, 'youtube.com') || str_contains($videoUrl, 'youtu.be')) {
            return;
        }

        // HLS: dispatch chunk jobs for the time range
        $segmentsJson = Redis::get(DubSession::audioSegmentsKey($this->sessionId));
        if (!$segmentsJson) return;

        $allSegments = json_decode($segmentsJson, true);
        $CHUNK_SIZE = 30.0;
        $currentTime = 0.0;
        $dispatched = 0;

        // Calculate which bg chunks overlap with this wave's time range
        $chunkStart = floor($rangeStart / $CHUNK_SIZE) * $CHUNK_SIZE;
        while ($chunkStart < $rangeEnd) {
            $chunkEnd = min($chunkStart + $CHUNK_SIZE, $rangeEnd);
            $chunkIdx = (int) round($chunkStart / $CHUNK_SIZE);

            // Check if this chunk is already in Redis (already dispatched by earlier wave)
            $bgChunkExists = Redis::hget(DubSession::bgChunksKey($this->sessionId), (string) $chunkIdx);
            if (!$bgChunkExists) {
                DownloadAudioChunkJob::dispatch($this->sessionId, $chunkIdx, $chunkStart, $chunkEnd)
                    ->onQueue('default');
                $dispatched++;
            }

            $chunkStart += $CHUNK_SIZE;
        }

        if ($dispatched > 0) {
            Log::info("[DUB] Wave {$this->waveIndex}: dispatched {$dispatched} bg audio chunks for " . round($rangeStart) . "-" . round($rangeEnd) . "s", [
                'session' => $this->sessionId,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[DUB] DispatchWaveJob wave {$this->waveIndex} failed: " . $exception->getMessage(), [
            'session' => $this->sessionId,
        ]);
    }
}
