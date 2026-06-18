<?php

namespace App\Jobs;

use App\Jobs\DispatchWaveJob;
use App\Models\InstantDub;
use App\Services\SrtParser;
use App\Support\DubSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
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
        $forceVoice = $session['force_voice'] ?? null;
        // Chrome extension (force_voice) → disable prosody transfer so voice stays clean
        if ($forceVoice && empty($session['disable_prosody'])) {
            $this->updateSession(['disable_prosody' => true]);
        }

        $sessionDriver = $session['tts_driver'] ?? config('dubber.tts.default', 'edge');
        if ($forceVoice && empty($session['force_voice_id']) && $sessionDriver === 'mms') {
            $fvGender = \App\Services\VoiceMapBuilder::genderFromTag($forceVoice);
            $voiceFile = null;
            foreach (['wav', 'mp3', 'm4a'] as $ext) {
                $p = storage_path("app/voice-pool/{$fvGender}/{$forceVoice}.{$ext}");
                if (file_exists($p)) { $voiceFile = $p; break; }
            }
            if ($voiceFile) {
                try {
                    $client = new \App\Services\MmsTts\MmsTtsClient();
                    $name   = pathinfo($voiceFile, PATHINFO_FILENAME);
                    $cacheKey = 'voice-pool-id:mms:' . md5($voiceFile);
                    $voiceId  = Redis::get($cacheKey);
                    if (!$voiceId) {
                        $voiceId = $client->findVoiceByName("pool-{$name}") ?? $client->addVoice("pool-{$name}", [$voiceFile]);
                        Redis::setex($cacheKey, 604800, $voiceId);
                    }
                    $this->updateSession(['force_voice_id' => $voiceId]);
                    Log::info("[DUB] force_voice '{$forceVoice}' pre-registered: {$voiceId}", ['session' => $this->sessionId]);
                } catch (\Throwable $e) {
                    Log::warning("[DUB] force_voice pre-register failed: " . $e->getMessage(), ['session' => $this->sessionId]);
                }
            }
        }

        // Fast path: needs_retts — skip translation, re-TTS from saved DB segments
        if ($this->cachedDubId !== null) {
            $this->handleReTts($title);
            return;
        }

        // 1. Get subtitles — from SRT or fetch from HLS
        $srt = $this->srt;

        $detectedLang = null;

        if (trim($srt) === '' && str_contains($this->videoUrl, '.m3u8')) {
            $this->updateStatus('Fetching subtitles...');
            $hlsResult = $this->fetchSubsFromHls($this->videoUrl);
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
            $srt = $this->fetchYouTubeSrt($this->videoUrl);
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

        // 2b. Dispatch background audio download in parallel (non-blocking)
        // Must go on 'default' queue — NOT 'segment-generation' — so TTS jobs
        // (which are on segment-generation with higher priority) aren't blocked.
        DownloadOriginalAudioJob::dispatch($this->sessionId, $this->videoUrl, $this->audioUrl)
            ->onQueue('default');

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

        $this->updateSession([
            'total_segments'     => count($segments),
            'status'             => 'processing',
            'hls_dub_start_time' => (float) ($segments[0]['start'] ?? 0.0),
        ]);

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
        // Initialize dispatched counter (wave 0 is about to dispatch)
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
                )->onQueue('segment-generation');
            }

            // Dispatch remaining waves for no-translation path
            for ($w = 1; $w < $totalWaves; $w++) {
                $waveOffset = (int) Redis::get(DubSession::waveKey($this->sessionId, $w) . ':offset');
                DispatchWaveJob::dispatch(
                    $this->sessionId, $w, $this->language, $this->translateFrom, $waveOffset,
                )->onQueue('segment-generation')->delay(now()->addSeconds(15 * $w));
            }

            Log::info("[DUB] [{$title}] Prepared (no translation): " . count($segments) . " segments in {$totalWaves} waves", [
                'session' => $this->sessionId,
            ]);
            return;
        }

        // Store all segments and full dialogue in Redis (avoids duplicating in every job payload)
        $allSegmentsKey = DubSession::allSegmentsKey($this->sessionId);
        Redis::setex($allSegmentsKey, DubSession::TTL, json_encode($allSegments));
        Redis::setex(DubSession::fullDialogueKey($this->sessionId), DubSession::TTL, $fullDialogueText);

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

        // 7. Schedule wave 1 with a short delay (gives wave 0 micro-batch time to start TTS).
        //    Waves 2+ are dispatched automatically by ProcessInstantDubSegmentJob when
        //    the previous wave reaches 80% completion (waterfall trigger).
        if ($totalWaves > 1) {
            $wave1Offset = count($wave0);
            DispatchWaveJob::dispatch(
                $this->sessionId, 1, $this->language, $this->translateFrom, $wave1Offset,
            )->onQueue('segment-generation')->delay(now()->addSeconds(15));
        }

        $wave0Count = count($wave0);
        $otherCount = count($segments) - $wave0Count;
        Log::info("[DUB] [{$title}] Prepared: wave 0 ({$wave0Count} segs: {$microBatchSize} micro + {$totalBatches} batches) + {$otherCount} in " . ($totalWaves - 1) . " future waves, {$this->translateFrom}->{$this->language}", [
            'session' => $this->sessionId,
        ]);
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
        ]);

        // Download background audio (needed for remix)
        DownloadOriginalAudioJob::dispatch($this->sessionId, $this->videoUrl, $this->audioUrl)
            ->onQueue('default');

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
            )->onQueue('segment-generation');
            $dispatched++;
        }

        Log::info("[DUB] [{$title}] Re-TTS dispatched: {$dispatched} segments (cached_dub_id={$this->cachedDubId})", [
            'session' => $this->sessionId,
        ]);
    }

    private function buildVoiceMap(array $speakers): void
    {
        $session    = DubSession::get($this->sessionId) ?? [];
        $forceVoice = $session['force_voice'] ?? null;
        $driver     = $session['tts_driver'] ?? config('dubber.tts.default', 'edge');

        // Load saved voice map from DB (admin may have customised it)
        $voiceMap = [];
        if ($this->cachedDubId) {
            $saved = \App\Models\InstantDubVoiceMap::where('instant_dub_id', $this->cachedDubId)->get();
            foreach ($saved as $vm) {
                $voiceMap[$vm->speaker_tag] = is_array($vm->voice_config)
                    ? $vm->voice_config
                    : json_decode($vm->voice_config, true);
            }
        }

        if ($forceVoice) {
            $entry = \App\Services\VoiceMapBuilder::forceVoiceEntry($driver, $forceVoice);
            if ($entry) {
                foreach (array_keys($speakers) as $tag) {
                    $voiceMap[$tag] = $entry;
                }
                Redis::setex(DubSession::voicesKey($this->sessionId), DubSession::TTL, json_encode($voiceMap));
                Log::info("[DUB] Voice map built (force_voice={$forceVoice}, driver={$driver}): " . implode(', ', array_keys($speakers)), [
                    'session' => $this->sessionId,
                ]);
                return;
            }
        }

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

    private function fetchSubsFromHls(string $url): ?array
    {
        try {
            $masterResp = Http::timeout(10)->get($url);
            if ($masterResp->failed()) return null;

            $master = $masterResp->body();
            $baseUrl = preg_replace('#/[^/]+$#', '/', $url);
            $query = parse_url($url, PHP_URL_QUERY);
            $resolve = function ($base, $rel) use ($query) {
                if (str_starts_with($rel, 'http')) return $rel;
                $r = rtrim($base, '/') . '/' . $rel;
                return $query ? "{$r}?{$query}" : $r;
            };

            // Parse all subtitle tracks
            preg_match_all('/^#EXT-X-MEDIA:.*TYPE=SUBTITLES.*$/m', $master, $subLines);
            $tracks = [];
            foreach ($subLines[0] ?? [] as $line) {
                $lang = preg_match('/LANGUAGE="([^"]*)"/', $line, $lm) ? $lm[1] : 'unknown';
                $uri = preg_match('/URI="([^"]+)"/', $line, $um) ? $um[1] : null;
                if ($uri) $tracks[] = ['lang' => $lang, 'uri' => $uri];
            }

            if (empty($tracks)) {
                if (!preg_match('/TYPE=SUBTITLES.*?URI="([^"]+)"/', $master, $m)) return null;
                $tracks = [['lang' => 'unknown', 'uri' => $m[1]]];
            }

            // Timeslots from English (original timing), text from Russian (better translation)
            $langPriority = ['ru', 'uz', 'en', 'tr'];
            $timingPriority = ['en', 'ru', 'uz', 'tr'];
            $langPatterns = [
                'ru' => ['ru', 'rus', 'russian'],
                'uz' => ['uz', 'uzb', 'uzbek'],
                'en' => ['en', 'eng', 'english'],
                'tr' => ['tr', 'tur', 'turkish'],
            ];

            // Classify each track by language code
            foreach ($tracks as &$track) {
                $track['langCode'] = 'unknown';
                $rawLang = strtolower($track['lang']);
                foreach ($langPatterns as $code => $patterns) {
                    foreach ($patterns as $p) {
                        if (str_contains($rawLang, $p)) {
                            $track['langCode'] = $code;
                            break 2;
                        }
                    }
                }
            }
            unset($track);

            // Fetch only the tracks we actually need: best text (ru priority) + best timing (en priority).
            // Downloading all tracks is O(N_tracks × N_vtt_files) — far too slow for long films.
            $byLang = [];
            $textTarget   = null; // first langCode from $langPriority that exists in $tracks
            $timingTarget = null; // first langCode from $timingPriority that exists in $tracks

            foreach ($langPriority as $lc) {
                foreach ($tracks as $t) {
                    if ($t['langCode'] === $lc) { $textTarget = $lc; break 2; }
                }
            }
            foreach ($timingPriority as $lc) {
                foreach ($tracks as $t) {
                    if ($t['langCode'] === $lc) { $timingTarget = $lc; break 2; }
                }
            }

            // Collect candidate tracks: text + timing. Some HLS masters expose multiple
            // tracks for the same language (full, forced, SDH, broken CDN variant).
            // Fetch candidates per language and keep the largest usable one.
            $needed = array_values(array_unique(array_filter([$textTarget, $timingTarget])));

            foreach ($needed as $lang) {
                $candidates = array_values(array_filter(
                    $tracks,
                    fn($track) => ($track['langCode'] ?? 'unknown') === $lang
                ));

                $result = $this->fetchBestSubtitleTrack($candidates, $baseUrl, $resolve, $lang);
                if ($result['cues'] > 0 && $result['srt'] !== '') {
                    $byLang[$lang] = $result;
                }
            }

            // Fallback: if preferred language tracks were broken, try unknown tracks too.
            if (empty($byLang)) {
                $unknownCandidates = array_values(array_filter(
                    $tracks,
                    fn($track) => ($track['langCode'] ?? 'unknown') === 'unknown'
                ));

                $result = $this->fetchBestSubtitleTrack($unknownCandidates, $baseUrl, $resolve, 'unknown');
                if ($result['cues'] > 0 && $result['srt'] !== '') {
                    $byLang['unknown'] = $result;
                }
            }

            if (empty($byLang)) return null;

            $cueSummary = [];
            foreach ($byLang as $lang => $r) {
                $cueSummary[] = "{$lang}:{$r['cues']}";
            }

            // Pick TEXT track (for translation) — Russian preferred
            $textResult = null;
            $textLang = null;
            foreach ($langPriority as $lang) {
                if (isset($byLang[$lang])) {
                    $textResult = $byLang[$lang];
                    $textLang = $lang;
                    break;
                }
            }
            if (!$textResult) {
                $textLang = array_key_first($byLang);
                $textResult = $byLang[$textLang];
            }

            // Pick TIMING track (for timeslots) — English preferred
            $timingResult = null;
            $timingLang = null;
            foreach ($timingPriority as $lang) {
                if (isset($byLang[$lang])) {
                    $timingResult = $byLang[$lang];
                    $timingLang = $lang;
                    break;
                }
            }

            // If we have both EN timing and RU text, merge: EN timestamps + RU text
            if ($timingResult && $textResult && $timingLang !== $textLang) {
                $timingSegments = \App\Services\SrtParser::parse($timingResult['srt']);
                $textSegments = \App\Services\SrtParser::parse($textResult['srt']);

                if (count($timingSegments) > 0 && count($textSegments) > 0) {
                    // Build merged SRT: EN timestamps, RU text (matched by index)
                    $merged = '';
                    $count = min(count($timingSegments), count($textSegments));
                    for ($i = 0; $i < $count; $i++) {
                        $ts = $timingSegments[$i];
                        $txt = $textSegments[$i]['text'] ?? $ts['text'];
                        $startH = floor($ts['start'] / 3600);
                        $startM = floor(($ts['start'] % 3600) / 60);
                        $startS = fmod($ts['start'], 60);
                        $endH = floor($ts['end'] / 3600);
                        $endM = floor(($ts['end'] % 3600) / 60);
                        $endS = fmod($ts['end'], 60);
                        $merged .= ($i + 1) . "\n";
                        $merged .= sprintf("%02d:%02d:%06.3f --> %02d:%02d:%06.3f\n", $startH, $startM, $startS, $endH, $endM, $endS);
                        $merged .= $txt . "\n\n";
                    }

                    Log::info("[DUB] Subtitle tracks merged: timing={$timingLang} text={$textLang} ({$count} cues) from [" . implode(', ', $cueSummary) . "]", [
                        'session' => $this->sessionId,
                    ]);

                    return ['srt' => $merged, 'language' => $textLang];
                }
            }

            Log::info("[DUB] Subtitle track selected: {$textLang} ({$textResult['cues']} cues) from [" . implode(', ', $cueSummary) . "]", [
                'session' => $this->sessionId,
            ]);

            return ['srt' => $textResult['srt'], 'language' => $textLang];
        } catch (\Throwable $e) {
            Log::error("[DUB] HLS sub fetch failed", ['session' => $this->sessionId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchSubtitleTrack(array $track, string $baseUrl, callable $resolve): array
    {
        $subsUrl = $resolve($baseUrl, $track['uri']);
        $subsResp = Http::timeout(10)->get($subsUrl);
        if ($subsResp->failed()) return ['srt' => '', 'cues' => 0, 'bytes' => 0, 'uri' => $track['uri'] ?? ''];

        $subsBase = preg_replace('#/[^/]+$#', '/', $subsUrl);
        preg_match_all('/^(\S+\.vtt)$/m', $subsResp->body(), $vttFiles);
        if (empty($vttFiles[1])) return ['srt' => '', 'cues' => 0, 'bytes' => 0, 'uri' => $track['uri'] ?? ''];

        Log::debug("[DUB] VTT playlist: " . count($vttFiles[1]) . " files, first URL: " . $resolve($subsBase, $vttFiles[1][0]), [
            'session' => $this->sessionId,
        ]);

        // Download VTT segments in batches of 30 to avoid overwhelming CDN
        $allVtt = '';
        $failed = 0;
        $batches = array_chunk($vttFiles[1], 30);

        foreach ($batches as $batch) {
            $pool = Http::pool(function ($pool) use ($batch, $subsBase, $resolve) {
                foreach ($batch as $i => $vttFile) {
                    $pool->as((string) $i)->timeout(15)->get($resolve($subsBase, $vttFile));
                }
            });

            foreach ($pool as $resp) {
                if ($resp instanceof \Illuminate\Http\Client\Response && $resp->successful()) {
                    $allVtt .= "\n" . $resp->body();
                } else {
                    $failed++;
                }
            }
        }

        if ($failed > 0) {
            Log::debug("[DUB] VTT download: {$failed}/" . count($vttFiles[1]) . " segments failed", [
                'session' => $this->sessionId,
            ]);
        }

        Log::debug("[DUB] VTT content sample (" . strlen($allVtt) . " bytes): " . substr($allVtt, 0, 500), [
            'session' => $this->sessionId,
            'vtt_files' => count($vttFiles[1] ?? []),
        ]);

        // Match VTT cues: optional numeric ID, then timestamp --> timestamp, then text
        // Supports both "123\n00:00:01.000 --> ..." and "00:00:01.000 --> ..." formats
        preg_match_all(
            '/(?:^|\n)(?:\d+\n)?(\d{2}:\d{2}:\d{2}\.\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}\.\d{3})[^\n]*\n((?:(?!\n\n|\nWEBVTT|\n\d{2}:\d{2}:\d{2}\.\d{3}\s*-->).)+)/s',
            $allVtt, $matches, PREG_SET_ORDER
        );

        $seen = [];
        $srt = '';
        $num = 0;
        foreach ($matches as $m) {
            $key = "{$m[1]}|{$m[2]}";
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $text = trim($m[3]);
            // Strip VTT positioning tags like <c> and alignment tags
            $text = preg_replace('/<[^>]+>/', '', $text);
            // Strip SSA/ASS override tags like {\an8}
            $text = preg_replace('/\{\\\\[^}]*\}/', '', $text);
            $text = trim($text);
            if ($text === '' || preg_match('/^\[.*\]$/', $text) || preg_match('/^♪/', $text)) continue;
            $num++;
            $srt .= "{$num}\n" . str_replace('.', ',', $m[1]) . ' --> ' . str_replace('.', ',', $m[2]) . "\n{$text}\n\n";
        }

        return ['srt' => $srt, 'cues' => $num, 'bytes' => strlen($allVtt), 'uri' => $track['uri'] ?? ''];
    }

    private function fetchBestSubtitleTrack(array $candidates, string $baseUrl, callable $resolve, string $lang): array
    {
        $best = ['srt' => '', 'cues' => 0, 'bytes' => 0, 'uri' => ''];
        foreach ($candidates as $track) {
            $result = $this->fetchSubtitleTrack($track, $baseUrl, $resolve);
            if ($result['cues'] <= 0 || $result['srt'] === '') {
                continue;
            }

            $isBetter = $result['cues'] > $best['cues']
                || ($result['cues'] === $best['cues'] && ($result['bytes'] ?? 0) > ($best['bytes'] ?? 0));

            if ($isBetter) {
                $best = $result;
            }
        }

        if ($best['cues'] > 0) {
            Log::info("[DUB] Best subtitle track selected for {$lang}: {$best['cues']} cues, {$best['bytes']} bytes", [
                'session' => $this->sessionId,
                'uri' => $best['uri'],
                'candidates' => count($candidates),
            ]);
        }

        return $best;
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

    private function fetchYouTubeSrt(string $url): string
    {
        try {
            $tmpDir = sys_get_temp_dir() . '/yt_subs_' . $this->sessionId;
            @mkdir($tmpDir, 0755, true);

            // Try manual subs first (any language), then auto-generated (en/ru/uz only)
            $attempts = [
                ['--write-subs',      'all'],
                ['--write-auto-subs', 'en,ru,uz'],
            ];
            foreach ($attempts as [$subFlag, $subLangs]) {
                $result = \Illuminate\Support\Facades\Process::timeout(60)->run([
                    'yt-dlp',
                    $subFlag,
                    '--skip-download',
                    '--sub-langs', $subLangs,
                    '--sub-format', 'vtt',
                    '--convert-subs', 'srt',
                    '-o', $tmpDir . '/sub',
                    '--no-playlist',
                    '--quiet',
                    '--extractor-args', 'youtube:player_client=web_creator,mweb,ios',
                    $url,
                ]);

                // Find any .srt file written
                $files = glob($tmpDir . '/*.srt') ?: [];
                if (!empty($files)) {
                    usort($files, function ($a, $b) {
                        $cueDiff = $this->countSubtitleCues($b) <=> $this->countSubtitleCues($a);
                        return $cueDiff !== 0 ? $cueDiff : (filesize($b) ?: 0) <=> (filesize($a) ?: 0);
                    });
                    $selected = $files[0];
                    $srt = file_get_contents($selected);
                    $selectedCues = $this->countSubtitleCues($selected);
                    $selectedBytes = strlen($srt ?: '');
                    array_map('unlink', glob($tmpDir . '/*'));
                    @rmdir($tmpDir);
                    Log::info("[DUB] YouTube SRT fetched via yt-dlp ({$subFlag})", [
                        'session' => $this->sessionId,
                        'file' => basename($selected),
                        'cues' => $selectedCues,
                        'bytes' => $selectedBytes,
                        'candidates' => count($files),
                    ]);
                    return $srt ?: '';
                }
            }

            @rmdir($tmpDir);
        } catch (\Throwable $e) {
            Log::warning("[DUB] YouTube SRT fetch failed: " . $e->getMessage(), ['session' => $this->sessionId]);
        }

        return '';
    }

    private function countSubtitleCues(string $path): int
    {
        $content = is_file($path) ? (file_get_contents($path) ?: '') : '';
        return preg_match_all('/-->/u', $content);
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
