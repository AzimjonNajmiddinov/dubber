<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

class InstantDubHlsReadiness
{
    private const OVERLAP_EPSILON = 0.05;

    public static function requiredSwitchBufferSeconds(array $session): float
    {
        $duration = (float) ($session['video_duration'] ?? 0.0);
        $totalBg = (int) ($session['total_bg_chunks'] ?? 0);
        $estimatedDuration = max($duration, $totalBg * 30.0);
        $dubStart = max(0.0, (float) ($session['hls_dub_start_time'] ?? 0.0));
        $createdAt = isset($session['created_at']) ? strtotime((string) $session['created_at']) : false;
        $elapsed = $createdAt ? max(0.0, (float) (time() - $createdAt)) : 0.0;
        $viewerPositionAfterDubStart = max(0.0, $elapsed - $dubStart);

        if ($estimatedDuration >= 7200.0) {
            return max(240.0, $viewerPositionAfterDubStart + 120.0);
        }

        if ($estimatedDuration >= 3600.0) {
            return max(180.0, $viewerPositionAfterDubStart + 90.0);
        }

        return max(120.0, $viewerPositionAfterDubStart + 60.0);
    }

    public static function chunkMixCoverage(string $sessionId, array $session, float $start, float $end): array
    {
        $expected = self::speechSegmentsForRange($sessionId, $start, $end);
        if ($expected === null) {
            return [
                'can_mix' => false,
                'expected_speech' => 1,
                'ready_speech' => 0,
                'missing_indexes' => [],
                'ready_chunks' => [],
                'missing_segment_plan' => true,
            ];
        }

        if (empty($expected)) {
            return [
                'can_mix' => true,
                'expected_speech' => 0,
                'ready_speech' => 0,
                'missing_indexes' => [],
                'ready_chunks' => [],
            ];
        }

        $ready = 0;
        $missing = [];
        $readyChunks = [];
        $chunksByIndex = self::processedChunksByIndex($sessionId, $session, $expected);

        foreach ($expected as $segment) {
            $idx = $segment['index'] ?? null;
            $chunk = $idx !== null ? ($chunksByIndex[$idx] ?? null) : self::findProcessedChunkByTime($chunksByIndex, $segment);

            if (self::processedChunkHasTts($chunk)) {
                $ready++;
                $readyChunks[] = $chunk;
            } else {
                $missing[] = $idx;
            }
        }

        return [
            'can_mix' => $ready >= count($expected),
            'expected_speech' => count($expected),
            'ready_speech' => $ready,
            'missing_indexes' => array_values(array_filter($missing, fn($idx) => $idx !== null)),
            'ready_chunks' => $readyChunks,
        ];
    }

    public static function markDubChunkReady(string $sessionId, int $bgIdx, array $coverage, int $ttsInputs): void
    {
        $bg = self::bgChunk($sessionId, $bgIdx);
        if (!$bg) {
            return;
        }

        $bg['dub_ready'] = true;
        $bg['dub_mixed_at'] = now()->timestamp;
        $bg['dub_tts_inputs'] = $ttsInputs;
        $bg['dub_expected_speech'] = (int) ($coverage['expected_speech'] ?? 0);
        $bg['dub_ready_speech'] = (int) ($coverage['ready_speech'] ?? 0);
        $bg['dub_missing_speech'] = [];
        $bg['dub_missing_segment_plan'] = false;

        DubSession::mergeBgChunk($sessionId, $bgIdx, $bg);
    }

    public static function markDubChunkWaiting(string $sessionId, int $bgIdx, array $coverage): void
    {
        $bg = self::bgChunk($sessionId, $bgIdx);
        if (!$bg) {
            return;
        }

        DubSession::mergeBgChunkIfNotDubReady($sessionId, $bgIdx, [
            'dub_ready' => false,
            'dub_expected_speech' => (int) ($coverage['expected_speech'] ?? 0),
            'dub_ready_speech' => (int) ($coverage['ready_speech'] ?? 0),
            'dub_missing_speech' => $coverage['missing_indexes'] ?? [],
            'dub_missing_segment_plan' => !empty($coverage['missing_segment_plan']),
        ]);
    }

    public static function chunkHasVerifiedDub(string $sessionId, array $session, int $bgIdx, ?array $bg = null, ?string $aacDir = null): bool
    {
        $bg ??= self::bgChunk($sessionId, $bgIdx);
        if (!$bg || empty($bg['dub_ready'])) {
            return false;
        }

        $aacDir ??= DubSession::aacDir($sessionId, $session);
        $file = self::dubChunkPath($aacDir, $bgIdx);

        return file_exists($file) && filesize($file) > 10;
    }

    public static function dubChunkPath(string $aacDir, int $bgIdx): string
    {
        return "{$aacDir}/bg-{$bgIdx}.ts";
    }

    public static function readyWindow(string $sessionId, array $session, ?string $aacDir = null): array
    {
        $dubStart = max(0.0, (float) ($session['hls_dub_start_time'] ?? 0.0));
        $requiredSeconds = self::requiredSwitchBufferSeconds($session);
        $totalBg = (int) ($session['total_bg_chunks'] ?? 0);
        $aacDir ??= DubSession::aacDir($sessionId, $session);

        $bgHashData = Redis::hgetall(DubSession::bgChunksKey($sessionId)) ?? [];
        ksort($bgHashData, SORT_NUMERIC);

        $readySeconds = 0.0;
        $lastIdx = null;
        $firstIdx = null;
        $continuousUntil = $dubStart;
        [$sourceReady, $sourceReadyUntil] = self::sourceReadyBeforeDubStart($bgHashData, $dubStart);

        foreach ($bgHashData as $bgIdx => $bgJson) {
            $bgIdx = (int) $bgIdx;
            $bg = json_decode($bgJson, true) ?: [];
            $start = (float) ($bg['start'] ?? ($bgIdx * 30.0));
            $end = (float) ($bg['end'] ?? (($bgIdx + 1) * 30.0));

            if ($end <= $dubStart + self::OVERLAP_EPSILON) {
                continue;
            }

            if ($lastIdx === null) {
                if ($start > $dubStart + self::OVERLAP_EPSILON) {
                    break;
                }
                $firstIdx = $bgIdx;
            } elseif ($bgIdx !== $lastIdx + 1) {
                break;
            }

            if (!self::chunkHasVerifiedDub($sessionId, $session, $bgIdx, $bg, $aacDir)) {
                break;
            }

            $readySeconds += max(0.0, $end - max($start, $dubStart));
            $continuousUntil = $end;
            $lastIdx = $bgIdx;

            // Keep scanning past the switch threshold so callers can also know
            // whether the entire post-intro dub timeline is complete.
        }

        $postDubComplete = $totalBg > 0 && $lastIdx !== null && $lastIdx >= $totalBg - 1;
        $complete = $sourceReady && $postDubComplete;

        return [
            'ready' => $sourceReady && ($readySeconds >= $requiredSeconds || $complete),
            'required_seconds' => $requiredSeconds,
            'ready_seconds' => $readySeconds,
            'first_ready_bg_idx' => $firstIdx,
            'last_ready_bg_idx' => $lastIdx,
            'continuous_until' => $continuousUntil,
            'source_ready' => $sourceReady,
            'source_ready_until' => $sourceReadyUntil,
            'complete' => $complete,
        ];
    }

    private static function sourceReadyBeforeDubStart(array $bgHashData, float $dubStart): array
    {
        if ($dubStart <= self::OVERLAP_EPSILON) {
            return [true, 0.0];
        }

        $expectedStart = 0.0;
        $readyUntil = 0.0;

        foreach ($bgHashData as $bgJson) {
            $bg = json_decode($bgJson, true) ?: [];
            $start = (float) ($bg['start'] ?? $expectedStart);
            $end = (float) ($bg['end'] ?? $start);

            if ($end <= self::OVERLAP_EPSILON) {
                continue;
            }

            if ($start > $expectedStart + self::OVERLAP_EPSILON) {
                return [false, $readyUntil];
            }

            $rawFile = $bg['path'] ?? null;
            if (!$rawFile || !file_exists($rawFile) || filesize($rawFile) <= 10) {
                return [false, $readyUntil];
            }

            $readyUntil = max($readyUntil, $end);
            if ($readyUntil >= $dubStart - self::OVERLAP_EPSILON) {
                return [true, $readyUntil];
            }

            $expectedStart = $end;
        }

        return [false, $readyUntil];
    }

    private static function bgChunk(string $sessionId, int $bgIdx): ?array
    {
        $json = Redis::hget(DubSession::bgChunksKey($sessionId), (string) $bgIdx);
        return $json ? json_decode($json, true) : null;
    }

    private static function speechSegmentsForRange(string $sessionId, float $start, float $end): ?array
    {
        $json = Redis::get(DubSession::speakableSegmentsKey($sessionId));
        if (!$json) {
            $json = Redis::get(DubSession::allSegmentsKey($sessionId));
        }
        if (!$json) {
            return null;
        }

        $segments = json_decode($json, true) ?: [];
        $result = [];

        foreach ($segments as $i => $segment) {
            $text = trim((string) ($segment['text'] ?? ''));
            $clean = preg_replace('/\[[^\]]*\]/', '', $text);
            $clean = preg_replace('/[-♪\s]+/', '', (string) $clean);
            if ($clean === '') {
                continue;
            }

            $segStart = (float) ($segment['start_time'] ?? ($segment['start'] ?? 0.0));
            $segEnd = (float) ($segment['end_time'] ?? ($segment['end'] ?? 0.0));
            if ($segStart < $end - self::OVERLAP_EPSILON && $segEnd > $start + self::OVERLAP_EPSILON) {
                $segment['index'] = isset($segment['index']) ? (int) $segment['index'] : (is_int($i) ? $i : null);
                $segment['start_time'] = $segStart;
                $segment['end_time'] = $segEnd;
                $result[] = $segment;
            }
        }

        return $result;
    }

    private static function processedChunksByIndex(string $sessionId, array $session, array $expected): array
    {
        $indexes = [];
        foreach ($expected as $segment) {
            if (isset($segment['index'])) {
                $indexes[] = (int) $segment['index'];
            }
        }

        if (empty($indexes)) {
            $total = (int) ($session['total_segments'] ?? 0);
            if ($total <= 0) {
                return [];
            }
            $indexes = range(0, $total - 1);
        }

        $indexes = array_values(array_unique($indexes));
        $keys = array_map(fn($idx) => DubSession::chunkKey($sessionId, $idx), $indexes);
        $values = !empty($keys) ? Redis::mget($keys) : [];
        $chunks = [];

        foreach ($values as $offset => $json) {
            if (!$json) {
                continue;
            }
            $chunks[$indexes[$offset]] = json_decode($json, true) ?: [];
        }

        return $chunks;
    }

    private static function findProcessedChunkByTime(array $chunksByIndex, array $segment): ?array
    {
        $start = (float) ($segment['start_time'] ?? 0.0);
        $end = (float) ($segment['end_time'] ?? 0.0);

        foreach ($chunksByIndex as $chunk) {
            $chunkStart = (float) ($chunk['start_time'] ?? -1.0);
            $chunkEnd = (float) ($chunk['end_time'] ?? -1.0);
            if (abs($chunkStart - $start) < 0.05 && abs($chunkEnd - $end) < 0.05) {
                return $chunk;
            }
        }

        return null;
    }

    private static function processedChunkHasTts(?array $chunk): bool
    {
        if (!$chunk || trim((string) ($chunk['text'] ?? '')) === '') {
            return false;
        }

        $audioPath = $chunk['audio_path'] ?? null;

        return $audioPath && file_exists($audioPath) && filesize($audioPath) > 100;
    }
}
