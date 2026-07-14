<?php

namespace App\Services;

use App\Jobs\DownloadAudioChunkJob;
use App\Jobs\ProcessInstantDubSegmentJob;
use App\Jobs\TranslateInstantDubBatchJob;
use App\Jobs\TranslateInstantDubMicroBatchJob;
use App\Support\DubSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Single dispatch path for one instant-dub "wave" (a 5-minute window of
 * segments): wave 0 is split into a fast micro-batch plus translation
 * batches so audio starts within seconds; later waves become translation
 * batches plus their bg-audio range; when no translation is needed, TTS
 * jobs are dispatched directly.
 *
 * Called in-process by PrepareInstantDubJob (wave 0, no queue hop) and by
 * DispatchWaveJob (waves 1+, triggered by the 80% waterfall).
 */
class WaveDispatcher
{
    private const MICRO_BATCH_SIZE = 3;
    private const BATCH_SIZE = 15;
    private const BG_CHUNK_SECONDS = 30.0;

    public function dispatch(
        string $sessionId,
        int $waveIndex,
        array $segments,
        int $globalOffset,
        string $language,
        string $translateFrom,
        ?float $nextWaveFirstStart = null,
    ): void {
        if (empty($segments)) {
            return;
        }

        $session = DubSession::get($sessionId) ?? [];
        $title = $session['title'] ?? 'Untitled';

        // Wave progress tracking for the 80% waterfall trigger
        Redis::setex(
            DubSession::waveProgressKey($sessionId, $waveIndex),
            DubSession::TTL,
            json_encode(['total' => count($segments), 'ready' => 0])
        );

        $needsTranslation = $translateFrom !== '' && $translateFrom !== $language;

        if (!$needsTranslation) {
            foreach ($segments as $i => $seg) {
                $text = trim($seg['text']);
                $text = trim(preg_replace('/\[[^\]]*\]\s*/', '', $text));
                $text = str_replace('`', '\'', $text);

                $slotEnd = isset($segments[$i + 1]) ? $segments[$i + 1]['start'] : $nextWaveFirstStart;

                ProcessInstantDubSegmentJob::dispatch(
                    $sessionId, $globalOffset + $i, $text,
                    $seg['start'], $seg['end'], $language,
                    $seg['speaker'] ?? 'M1',
                    $slotEnd,
                    null,
                    null,
                    $waveIndex,
                )->onQueue('segment-generation');
            }

            Log::info("[DUB] [{$title}] Wave {$waveIndex}: " . count($segments) . " segments dispatched (no translation)", [
                'session' => $sessionId,
            ]);

            return;
        }

        // Wave 0: peel off a tiny micro-batch so audio starts within seconds
        $batchSegments = $segments;
        $microCount = 0;
        if ($waveIndex === 0) {
            $microCount = min(self::MICRO_BATCH_SIZE, count($segments));
            $microSegments = array_slice($segments, 0, $microCount);
            $batchSegments = array_slice($segments, $microCount);

            $nextSegmentStart = !empty($batchSegments) ? (float) $batchSegments[0]['start'] : $nextWaveFirstStart;

            TranslateInstantDubMicroBatchJob::dispatch(
                $sessionId,
                $microSegments,
                $language,
                $translateFrom,
                $nextSegmentStart,
            )->onQueue('segment-generation');
        }

        // Store remaining segments as translation batches; batch 0 runs the
        // character analysis (wave 0) and fans out batches 1..N in parallel.
        $batches = array_chunk($batchSegments, self::BATCH_SIZE);
        $totalBatches = count($batches);
        foreach ($batches as $batchIdx => $batch) {
            Redis::setex(
                DubSession::batchKey($sessionId, $batchIdx, $waveIndex),
                DubSession::TTL,
                json_encode(array_values($batch))
            );
        }

        if ($totalBatches > 0) {
            Redis::setex(DubSession::batchesRemainingKey($sessionId, $waveIndex), DubSession::TTL, $totalBatches);

            TranslateInstantDubBatchJob::dispatch(
                $sessionId,
                0,
                $totalBatches,
                $language,
                $translateFrom,
                $globalOffset + $microCount,
                $waveIndex,
            )->onQueue('segment-generation');
        }

        // Later waves fetch their own bg audio range; wave 0's bg audio is
        // handled by DownloadOriginalAudioJob dispatched from PrepareInstantDubJob.
        if ($waveIndex > 0) {
            $this->dispatchBgAudioForRange(
                $sessionId,
                $waveIndex,
                (float) $segments[0]['start'],
                (float) end($segments)['end'],
            );
        }

        Log::info("[DUB] [{$title}] Wave {$waveIndex}: " . count($segments) . " segments ("
            . ($microCount ? "{$microCount} micro + " : '') . "{$totalBatches} batches) dispatched ({$translateFrom}→{$language})", [
            'session' => $sessionId,
            'offset'  => $globalOffset,
        ]);
    }

    /**
     * Dispatch DownloadAudioChunkJob for bg audio chunks overlapping this wave's time range.
     * For HLS sources, reuses the audio segments already stored in Redis.
     * For YouTube, DownloadYouTubeWindowJob windows are already dispatched — the window
     * job itself handles the chunk jobs.
     */
    private function dispatchBgAudioForRange(string $sessionId, int $waveIndex, float $rangeStart, float $rangeEnd): void
    {
        $session = DubSession::get($sessionId);
        if (!$session) {
            return;
        }

        $videoUrl = $session['video_url'] ?? '';

        // YouTube: bg audio is already handled by DownloadYouTubeWindowJob
        // which dispatches DownloadAudioChunkJob for each 30s slice automatically.
        if (str_contains($videoUrl, 'youtube.com') || str_contains($videoUrl, 'youtu.be')) {
            return;
        }

        // HLS: dispatch chunk jobs for the time range
        $segmentsJson = Redis::get(DubSession::audioSegmentsKey($sessionId));
        if (!$segmentsJson) {
            return;
        }

        $dispatched = 0;
        $chunkStart = floor($rangeStart / self::BG_CHUNK_SECONDS) * self::BG_CHUNK_SECONDS;
        while ($chunkStart < $rangeEnd) {
            $chunkEnd = min($chunkStart + self::BG_CHUNK_SECONDS, $rangeEnd);
            $chunkIdx = (int) round($chunkStart / self::BG_CHUNK_SECONDS);

            // Skip chunks already dispatched by an earlier wave
            $bgChunkExists = Redis::hget(DubSession::bgChunksKey($sessionId), (string) $chunkIdx);
            if (!$bgChunkExists) {
                DownloadAudioChunkJob::dispatch($sessionId, $chunkIdx, $chunkStart, $chunkEnd)
                    ->onQueue('audio-downloads');
                $dispatched++;
            }

            $chunkStart += self::BG_CHUNK_SECONDS;
        }

        if ($dispatched > 0) {
            Log::info("[DUB] Wave {$waveIndex}: dispatched {$dispatched} bg audio chunks for " . round($rangeStart) . "-" . round($rangeEnd) . "s", [
                'session' => $sessionId,
            ]);
        }
    }
}
