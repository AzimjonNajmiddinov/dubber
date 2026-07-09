<?php

namespace App\Jobs;

use App\Jobs\DispatchWaveJob;
use App\Models\InstantDub;
use App\Services\SrtParser;
use App\Services\SubtitleFetcher;
use App\Support\DubSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PrepareInstantDubJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 2;

    public function __construct(
        public string  $sessionId,
        public string  $videoUrl,
        public string  $language,
        public string  $translateFrom,
        public string  $srt,
        public ?int    $cachedDubId = null,
        public ?string $audioUrl = null,
    ) {}

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId) ?? [];
        $title   = $session['title'] ?? 'Untitled';
        if (($session['tts_driver'] ?? null) !== 'edge' || !empty($session['force_voice']) || !empty($session['force_voice_id'])) {
            $this->updateSession([
                'tts_driver' => 'edge',
                'force_voice' => null,
                'force_voice_id' => null,
                'disable_prosody' => false,
            ]);
            $session['tts_driver'] = 'edge';
            unset($session['force_voice'], $session['force_voice_id']);
            $session['disable_prosody'] = false;
        }

        // Fast path: needs_retts — skip translation, re-TTS from saved DB segments
        if ($this->cachedDubId !== null) {
            $this->handleReTts($title);
            return;
        }

        // 1. Get subtitles — from SRT or fetch from HLS
        $srt = $this->srt;

        $detectedLang = null;
        $subtitles = new SubtitleFetcher($this->sessionId);

        if (trim($srt) === '' && str_contains($this->videoUrl, '.m3u8')) {
            $this->updateStatus('Fetching subtitles...');
            $hlsResult = $subtitles->fetchSubsFromHls($this->videoUrl);
            if (!$hlsResult) {
                $this->updateStatus('error', 'No subtitles found in HLS');
                return;
            }
            $srt = $hlsResult['srt'];
            $detectedLang = $hlsResult['language'];
        }

        // YouTube fallback: download auto-captions via yt-dlp
        if (trim($srt) === '' && (str_contains($this->videoUrl, 'youtube.com') || str_contains($this->videoUrl, 'youtu.be'))) {
            $this->updateStatus('Fetching YouTube subtitles...');
            $srt = $subtitles->fetchYouTubeSrt($this->videoUrl);
        }

        if (trim($srt) === '') {
            $this->updateStatus('error', 'No subtitles available');
            return;
        }

        // Override translateFrom with auto-detected language when set to 'auto'
        if ($detectedLang && ($this->translateFrom === 'auto' || $this->translateFrom === '')) {
            $this->translateFrom = $detectedLang;
            Log::info("[DUB] [{$title}] Auto-detected subtitle language: {$detectedLang}", ['session' => $this->sessionId]);
        }

        $this->updateSession([
            'translate_from' => $this->translateFrom,
        ]);

        // 2. Parse SRT
        $allSegments = SrtParser::parse($srt);

        if (empty($allSegments)) {
            $this->updateStatus('error', 'No segments found');
            return;
        }

        // Build full raw dialogue for GPT context — includes [music], [laughing], annotations, everything
        $fullDialogue = [];
        foreach ($allSegments as $i => $seg) {
            $fullDialogue[] = ($i + 1) . '. ' . $seg['text'];
        }
        $fullDialogueText = implode("\n", $fullDialogue);

        // Store expected audio duration from last subtitle so DownloadOriginalAudioJob
        // can detect CDN-truncated downloads and fall back to yt-dlp before dispatching chunks.
        if (!empty($allSegments)) {
            $lastSeg = end($allSegments);
            $this->updateSession(['expected_duration' => (float) ($lastSeg['end'] ?? 0)]);
        }

        // 3. Filter to speakable segments
        $needsTranslation = $this->translateFrom && $this->translateFrom !== $this->language;

        $segments = array_values(array_filter($allSegments, function ($seg) {
            $clean = preg_replace('/\[[^\]]*\]/', '', $seg['text']);
            $clean = preg_replace('/[-♪\s]+/', '', $clean);
            return $clean !== '';
        }));

        // Clean bracket annotations for TTS (GPT sees the raw version)
        foreach ($segments as &$seg) {
            $seg['raw_text'] = $seg['text'];
            $clean = preg_replace('/\{\\\\[^}]*\}\s*/', '', $seg['text']); // SSA/ASS tags like {\an8}
            $clean = preg_replace('/\[[^\]]*\]\s*/', '', $clean);
            $clean = preg_replace('/^-\s*/', '', $clean);
            $clean = preg_replace('/\s+-\s+/', ' ', $clean);
            $seg['text'] = trim($clean);
        }
        unset($seg);
        $segments = array_values(array_filter($segments, fn($s) => trim($s['text']) !== ''));
        if (empty($segments)) {
            $this->updateStatus('error', 'No speakable subtitle segments found');
            return;
        }

        $this->updateSession([
            'total_segments'     => count($segments),
            'status'             => 'processing',
            'hls_dub_start_time' => (float) ($segments[0]['start'] ?? 0.0),
            'translate_from'      => $this->translateFrom,
        ]);

        $this->storeSegmentPlan($segments, $allSegments, $fullDialogueText);

        // Dispatch background audio only after the speakable segment plan is stored.
        // Otherwise bg workers can race ahead, see "no expected speech", and mark
        // original-only chunks as dub_ready.
        DownloadOriginalAudioJob::dispatch($this->sessionId, $this->videoUrl, $this->audioUrl)
            ->onQueue('audio-downloads');

        // ── Wave-based dispatch ─────────────────────────────────────────────────
        // Split segments into time-based waves (5 minutes each).
        // Wave 0 dispatches immediately for instant start; remaining waves are
        // stored in Redis and dispatched progressively as playback progresses.
        $WAVE_DURATION = 300.0; // 5 minutes per wave
        $waves = [];
        foreach ($segments as $i => $seg) {
            $waveIdx = (int) floor((float) $seg['start'] / $WAVE_DURATION);
            $waves[$waveIdx][] = $seg;
        }
        // Re-key to sequential indices (a wave might have no segments if there's a long gap)
        $waves = array_values($waves);
        $totalWaves = count($waves);

        // Store wave metadata in session
        $this->updateSession([
            'total_waves'      => $totalWaves,
            'waves_dispatched' => 1, // wave 0 dispatches now
        ]);

        // Build voice map from ALL speakers (needed before any TTS)
        $allSpeakers = [];
        foreach ($segments as $seg) {
            $tag = $seg['speaker'] ?? 'M1';
            $allSpeakers[$tag] = true;
        }
        $this->buildVoiceMap($allSpeakers);

        // Store waves 1+ in Redis for later dispatch by DispatchWaveJob
        $globalOffset = count($waves[0] ?? []);
        for ($w = 1; $w < $totalWaves; $w++) {
            Redis::setex(
                DubSession::waveKey($this->sessionId, $w),
                DubSession::TTL,
                json_encode(array_values($waves[$w]))
            );
            // Track cumulative global offset for each wave
            Redis::setex(
                DubSession::waveKey($this->sessionId, $w) . ':offset',
                DubSession::TTL,
                $globalOffset
            );
            $globalOffset += count($waves[$w]);
        }
        // Initialize claimed-wave counter. Wave 0 is claimed now; later code
        // raises this when it queues more waves up front.
        Redis::setex(DubSession::wavesDispatchedKey($this->sessionId), DubSession::TTL, 1);

        // Store wave 0 progress tracking
        Redis::setex(
            DubSession::waveProgressKey($this->sessionId, 0),
            DubSession::TTL,
            json_encode(['total' => count($waves[0] ?? []), 'ready' => 0])
        );

        // ── Dispatch wave 0 (same micro-batch + batch logic as before) ──────────
        $wave0 = $waves[0] ?? [];

        if (!$needsTranslation) {
            // No translation — dispatch TTS directly for wave 0
            foreach ($wave0 as $i => $seg) {
                $text = trim($seg['text']);
                $text = trim(preg_replace('/\[[^\]]*\]\s*/', '', $text));
                $text = str_replace('`', '\'', $text);

                $slotEnd = isset($wave0[$i + 1]) ? $wave0[$i + 1]['start'] : null;
                // For last segment of wave 0, peek at wave 1's first segment
                if ($slotEnd === null && $totalWaves > 1 && !empty($waves[1])) {
                    $slotEnd = (float) $waves[1][0]['start'];
                }

                ProcessInstantDubSegmentJob::dispatch(
                    $this->sessionId, $i, $text,
                    $seg['start'], $seg['end'], $this->language,
                    $seg['speaker'] ?? 'M1',
                    $slotEnd,
                    null,
                    null,
                    0,
                )->onQueue('segment-generation');
            }

            // Dispatch remaining waves for no-translation path
            for ($w = 1; $w < $totalWaves; $w++) {
                $waveOffset = (int) Redis::get(DubSession::waveKey($this->sessionId, $w) . ':offset');
                DispatchWaveJob::dispatch(
                    $this->sessionId, $w, $this->language, $this->translateFrom, $waveOffset,
                )->onQueue('segment-generation')->delay(now()->addSeconds(15 * $w));
            }
            Redis::setex(DubSession::wavesDispatchedKey($this->sessionId), DubSession::TTL, $totalWaves);
            $this->updateSession(['waves_dispatched' => $totalWaves]);

            Log::info("[DUB] [{$title}] Prepared (no translation): " . count($segments) . " segments in {$totalWaves} waves", [
                'session' => $this->sessionId,
            ]);
            return;
        }

        // 4. Micro-batch: dispatch first 3 segments of wave 0 for fast translation → immediate TTS
        $microBatchSize = min(3, count($wave0));
        $microSegments = array_slice($wave0, 0, $microBatchSize);
        $remainingWave0 = array_slice($wave0, $microBatchSize);

        $nextSegmentStart = !empty($remainingWave0) ? (float) $remainingWave0[0]['start'] : null;

        TranslateInstantDubMicroBatchJob::dispatch(
            $this->sessionId,
            $microSegments,
            $this->language,
            $this->translateFrom,
            $nextSegmentStart,
        )->onQueue('segment-generation');

        // 5. Store remaining wave 0 segments in batches for translation
        $batches = array_chunk($remainingWave0, 15);
        $totalBatches = count($batches);
        foreach ($batches as $batchIdx => $batch) {
            $batchKey = DubSession::batchKey($this->sessionId, $batchIdx);
            Redis::setex($batchKey, DubSession::TTL, json_encode(array_values($batch)));
        }

        // 6. Dispatch wave 0 translation chain
        if ($totalBatches > 0) {
            Redis::setex("instant-dub:{$this->sessionId}:batches-remaining", DubSession::TTL, $totalBatches);

            TranslateInstantDubBatchJob::dispatch(
                $this->sessionId,
                0,
                $totalBatches,
                $this->language,
                $this->translateFrom,
                $microBatchSize,
            )->onQueue('segment-generation');
        }

        // 7. Schedule several waves ahead with a small stagger. Long movies must
        // keep processing beyond the initial switch runway; the waterfall trigger
        // still extends the pipeline once this lookahead is consumed.
        $initialWaveClaims = $this->initialTranslationWaveClaims($totalWaves);
        if ($initialWaveClaims > 1) {
            Redis::setex(DubSession::wavesDispatchedKey($this->sessionId), DubSession::TTL, $initialWaveClaims);
            $this->updateSession(['waves_dispatched' => $initialWaveClaims]);

            for ($w = 1; $w < $initialWaveClaims; $w++) {
                $waveOffset = (int) Redis::get(DubSession::waveKey($this->sessionId, $w) . ':offset');
                DispatchWaveJob::dispatch(
                    $this->sessionId, $w, $this->language, $this->translateFrom, $waveOffset,
                )->onQueue('segment-generation')->delay(now()->addSeconds(15 * $w));
            }
        }

        $wave0Count = count($wave0);
        $otherCount = count($segments) - $wave0Count;
        Log::info("[DUB] [{$title}] Prepared: wave 0 ({$wave0Count} segs: {$microBatchSize} micro + {$totalBatches} batches) + {$otherCount} in " . ($totalWaves - 1) . " future waves, {$this->translateFrom}->{$this->language}", [
            'session' => $this->sessionId,
        ]);
    }

    private function initialTranslationWaveClaims(int $totalWaves): int
    {
        if ($totalWaves <= 1) {
            return $totalWaves;
        }

        return min($totalWaves, max(2, (int) config('dubber.instant_dub.initial_wave_lookahead', 4)));
    }

    private function storeSegmentPlan(array $segments, array $allSegments, string $fullDialogueText): void
    {
        $speakable = [];
        foreach ($segments as $i => $seg) {
            $speakable[] = [
                'index' => $i,
                'start_time' => (float) ($seg['start'] ?? 0.0),
                'end_time' => (float) ($seg['end'] ?? 0.0),
                'text' => (string) ($seg['text'] ?? ''),
                'speaker' => (string) ($seg['speaker'] ?? 'M1'),
            ];
        }

        Redis::setex(DubSession::speakableSegmentsKey($this->sessionId), DubSession::TTL, json_encode($speakable));
        Redis::setex(DubSession::allSegmentsKey($this->sessionId), DubSession::TTL, json_encode($allSegments));
        Redis::setex(DubSession::fullDialogueKey($this->sessionId), DubSession::TTL, $fullDialogueText);
        DubSession::patch($this->sessionId, ['segment_plan_ready' => true]);
    }

    private function handleReTts(string $title): void
    {
        $dub = InstantDub::with('segments')->find($this->cachedDubId);
        if (!$dub) {
            $this->updateStatus('error', 'Cached dub not found');
            return;
        }

        $segments = $dub->segments->sortBy('segment_index')->values();
        $total = $segments->count();

        if ($total === 0) {
            $this->updateStatus('error', 'No segments in cached dub');
            return;
        }

        // Rebuild voice map from saved speaker tags
        $allSpeakers = [];
        foreach ($segments as $seg) {
            $allSpeakers[$seg->speaker] = true;
        }
        $this->buildVoiceMap($allSpeakers);

        $this->updateSession([
            'total_segments'     => $total,
            'status'             => 'processing',
            'hls_dub_start_time' => (float) ($segments->first()->start_time ?? 0.0),
            'translate_from'      => $this->translateFrom,
        ]);

        Redis::setex(
            DubSession::speakableSegmentsKey($this->sessionId),
            DubSession::TTL,
            json_encode($segments->map(fn($seg) => [
                'index' => (int) $seg->segment_index,
                'start_time' => (float) $seg->start_time,
                'end_time' => (float) $seg->end_time,
                'text' => (string) ($seg->translated_text ?? ''),
                'speaker' => (string) $seg->speaker,
            ])->values()->all())
        );
        DubSession::patch($this->sessionId, ['segment_plan_ready' => true]);

        // Download background audio (needed for remix)
        DownloadOriginalAudioJob::dispatch($this->sessionId, $this->videoUrl, $this->audioUrl)
            ->onQueue('audio-downloads');

        // Dispatch TTS for segments that need re-TTS
        $dispatched = 0;
        foreach ($segments as $i => $seg) {
            $text = trim($seg->translated_text ?? '');
            if ($text === '') continue;

            ProcessInstantDubSegmentJob::dispatch(
                $this->sessionId,
                $seg->segment_index,
                $text,
                $seg->start_time,
                $seg->end_time,
                $this->language,
                $seg->speaker,
                $seg->slot_end,
                $seg->source_text,
                null,
                0,
            )->onQueue('segment-generation');
            $dispatched++;
        }

        Log::info("[DUB] [{$title}] Re-TTS dispatched: {$dispatched} segments (cached_dub_id={$this->cachedDubId})", [
            'session' => $this->sessionId,
        ]);
    }

    private function buildVoiceMap(array $speakers): void
    {
        $driver = 'edge';
        $voiceMap = [];

        $variants = \App\Services\VoiceMapBuilder::variantsForDriver($driver, $this->language);
        $voiceMap = \App\Services\VoiceMapBuilder::assignSpeakers($voiceMap, $speakers, $variants);

        Redis::setex(DubSession::voicesKey($this->sessionId), DubSession::TTL, json_encode($voiceMap));

        if (count($speakers) === 1) {
            DubSession::patch($this->sessionId, ['disable_prosody' => true]);
        }

        Log::info("[DUB] Voice map built (driver={$driver}): " . implode(', ', array_keys($speakers)), [
            'session' => $this->sessionId,
        ]);
    }

    private function updateStatus(string $status, string $error = ''): void
    {
        $data = ['status' => $status];
        if ($error) $data['error'] = $error;
        DubSession::patch($this->sessionId, $data);
    }

    private function updateSession(array $data): void
    {
        DubSession::patch($this->sessionId, $data);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[DUB] PrepareInstantDubJob failed", [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);

        $this->updateStatus('error', 'Preparation failed: ' . Str::limit($exception->getMessage(), 100));
    }
}
