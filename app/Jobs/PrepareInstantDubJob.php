<?php

namespace App\Jobs;

use App\Models\InstantDub;
use App\Services\SrtParser;
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

    public int $timeout = 300;
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
        $sessionKey = "instant-dub:{$this->sessionId}";

        // Read title from session for logging context
        $title = 'Untitled';
        $sessionJson = Redis::get($sessionKey);
        if ($sessionJson) {
            $title = json_decode($sessionJson, true)['title'] ?? 'Untitled';
        }

        // Pre-register force_voice with MMS once (single-job context = no race condition).
        // Stores force_voice_id in session so all segment workers use the same voice ID.
        $session = $sessionJson ? json_decode($sessionJson, true) : [];
        $forceVoice = $session['force_voice'] ?? null;
        if ($forceVoice && empty($session['force_voice_id'])) {
            $fvGender = str_starts_with($forceVoice, 'F') ? 'female'
                      : (str_starts_with($forceVoice, 'C') ? 'child' : 'male');
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

        $this->updateSession(['total_segments' => count($segments), 'status' => 'processing']);

        if (!$needsTranslation) {
            // No translation — build voice map and dispatch TTS directly
            $allSpeakers = [];
            foreach ($segments as $seg) {
                $tag = $seg['speaker'] ?? 'M1';
                $allSpeakers[$tag] = true;
            }
            $this->buildVoiceMap($allSpeakers);

            $dispatched = 0;
            foreach ($segments as $i => $seg) {
                $text = trim($seg['text']);
                $text = trim(preg_replace('/\[[^\]]*\]\s*/', '', $text));
                $text = str_replace('`', '\'', $text);
                if ($text === '') continue;

                $slotEnd = isset($segments[$i + 1]) ? $segments[$i + 1]['start'] : null;

                ProcessInstantDubSegmentJob::dispatch(
                    $this->sessionId, $i, $text,
                    $seg['start'], $seg['end'], $this->language,
                    $seg['speaker'] ?? 'M1',
                    $slotEnd,
                )->onQueue('segment-generation');
                $dispatched++;
            }

            Log::info("[DUB] [{$title}] Prepared (no translation), {$dispatched} segments dispatched", [
                'session' => $this->sessionId,
            ]);
            return;
        }

        // Store all segments and full dialogue in Redis (avoids duplicating in every job payload)
        $allSegmentsKey = "instant-dub:{$this->sessionId}:all-segments";
        Redis::setex($allSegmentsKey, 50400, json_encode($allSegments));
        Redis::setex("instant-dub:{$this->sessionId}:full-dialogue", 50400, $fullDialogueText);

        // 4. Micro-batch: dispatch first 3 segments for fast translation → immediate TTS
        $microBatchSize = min(3, count($segments));
        $microSegments = array_slice($segments, 0, $microBatchSize);
        $remainingSegments = array_slice($segments, $microBatchSize);

        $nextSegmentStart = !empty($remainingSegments) ? (float) $remainingSegments[0]['start'] : null;

        TranslateInstantDubMicroBatchJob::dispatch(
            $this->sessionId,
            $microSegments,
            $this->language,
            $this->translateFrom,
            $nextSegmentStart,
        )->onQueue('default');

        // 5. Store remaining segments in batches for full translation chain
        $batches = array_chunk($remainingSegments, 15);
        $totalBatches = count($batches);
        foreach ($batches as $batchIdx => $batch) {
            $batchKey = "instant-dub:{$this->sessionId}:batch:{$batchIdx}";
            Redis::setex($batchKey, 50400, json_encode(array_values($batch)));
        }

        // 6. Dispatch full translation chain (batch 0 starts from segment offset after micro-batch)
        if ($totalBatches > 0) {
            TranslateInstantDubBatchJob::dispatch(
                $this->sessionId,
                0,
                $totalBatches,
                $this->language,
                $this->translateFrom,
                $microBatchSize,
            )->onQueue('default');
        }

        Log::info("[DUB] [{$title}] Prepared: {$microBatchSize} micro-batch + " . count($remainingSegments) . " remaining in {$totalBatches} batches, {$this->translateFrom}→{$this->language}", [
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

        $this->updateSession(['total_segments' => $total, 'status' => 'processing']);

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
        $sessionJson = Redis::get("instant-dub:{$this->sessionId}");
        $session     = $sessionJson ? json_decode($sessionJson, true) : [];
        $forceVoice  = $session['force_voice'] ?? null;

        // Flow 3 (Chrome Extension): user picked a specific voice — assign it to ALL speakers.
        // Gender-based cycling (old approach) is DISABLED when force_voice is set.
        if ($forceVoice) {
            $gender = str_starts_with($forceVoice, 'F') ? 'female'
                    : (str_starts_with($forceVoice, 'C') ? 'child' : 'male');
            $voiceMap = [];
            foreach (array_keys($speakers) as $tag) {
                $voiceMap[$tag] = ['driver' => 'mms', 'gender' => $gender, 'pool_name' => $forceVoice, 'tau' => 1.0, 'seed' => 42];
            }
            $voiceKey = "instant-dub:{$this->sessionId}:voices";
            Redis::setex($voiceKey, 50400, json_encode($voiceMap));
            Log::info("[DUB] Voice map built (force_voice={$forceVoice}): " . implode(', ', array_keys($speakers)), [
                'session' => $this->sessionId,
            ]);
            return;
        }

        // 1. Load saved voice map from DB (admin may have customised it)
        $voiceMap = [];
        if ($this->cachedDubId) {
            $saved = \App\Models\InstantDubVoiceMap::where('instant_dub_id', $this->cachedDubId)->get();
            foreach ($saved as $vm) {
                $voiceMap[$vm->speaker_tag] = is_array($vm->voice_config)
                    ? $vm->voice_config
                    : json_decode($vm->voice_config, true);
            }
        }

        // 2. For any speaker not in saved map, assign a default based on driver
        $driver = $session['tts_driver'] ?? config('dubber.tts.default', 'edge');

        if ($driver === 'mms') {
            $maleFiles   = glob(storage_path('app/voice-pool/male/*.{wav,mp3,m4a}'), GLOB_BRACE) ?: [];
            $femaleFiles = glob(storage_path('app/voice-pool/female/*.{wav,mp3,m4a}'), GLOB_BRACE) ?: [];
            $childFiles  = glob(storage_path('app/voice-pool/child/*.{wav,mp3,m4a}'), GLOB_BRACE) ?: $maleFiles;
            $toVariant = fn($f, $g) => ['driver' => 'mms', 'gender' => $g, 'pool_name' => pathinfo($f, PATHINFO_FILENAME)];
            $maleVariants   = array_map(fn($f) => $toVariant($f, 'male'),   $maleFiles);
            $femaleVariants = array_map(fn($f) => $toVariant($f, 'female'), $femaleFiles);
            $childVariants  = array_map(fn($f) => $toVariant($f, 'child'),  $childFiles);
        } else {
            $variants = \App\Services\VoiceVariants::forLanguage($this->language);
            $maleVariants = $variants['male']; $femaleVariants = $variants['female']; $childVariants = $variants['child'];
        }

        $maleIdx = $femaleIdx = $childIdx = 0;
        foreach (array_keys($speakers) as $tag) {
            if (isset($voiceMap[$tag])) continue; // already assigned from DB

            if (str_starts_with($tag, 'C') && !empty($childVariants)) {
                $voiceMap[$tag] = $childVariants[$childIdx % count($childVariants)];
                $childIdx++;
            } elseif (str_starts_with($tag, 'M') && !empty($maleVariants)) {
                $voiceMap[$tag] = $maleVariants[$maleIdx % count($maleVariants)];
                $maleIdx++;
            } elseif (!empty($femaleVariants)) {
                $voiceMap[$tag] = $femaleVariants[$femaleIdx % count($femaleVariants)];
                $femaleIdx++;
            }
        }

        $voiceKey = "instant-dub:{$this->sessionId}:voices";
        Redis::setex($voiceKey, 50400, json_encode($voiceMap));

        if (count($speakers) === 1) {
            $session['disable_prosody'] = true;
            Redis::setex("instant-dub:{$this->sessionId}", 50400, json_encode($session));
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

            // Fetch all tracks — for duplicate languages, keep the one with most cues
            $byLang = [];
            foreach ($tracks as $track) {
                $lang = $track['langCode'];
                $result = $this->fetchSubtitleTrack($track, $baseUrl, $resolve);
                if ($result['cues'] > 0 && $result['srt'] !== '') {
                    if (!isset($byLang[$lang]) || $result['cues'] > $byLang[$lang]['cues']) {
                        $byLang[$lang] = $result;
                    }
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
        if ($subsResp->failed()) return ['srt' => '', 'cues' => 0];

        $subsBase = preg_replace('#/[^/]+$#', '/', $subsUrl);
        preg_match_all('/^(\S+\.vtt)$/m', $subsResp->body(), $vttFiles);
        if (empty($vttFiles[1])) return ['srt' => '', 'cues' => 0];

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

        return ['srt' => $srt, 'cues' => $num];
    }

    private function updateStatus(string $status, string $error = ''): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $json = Redis::get($sessionKey);
        if (!$json) return;
        $session = json_decode($json, true);
        $session['status'] = $status;
        if ($error) $session['error'] = $error;
        Redis::setex($sessionKey, 50400, json_encode($session));
    }

    private function updateSession(array $data): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $json = Redis::get($sessionKey);
        if (!$json) return;
        $session = json_decode($json, true);
        Redis::setex($sessionKey, 50400, json_encode(array_merge($session, $data)));
    }

    private function fetchYouTubeSrt(string $url): string
    {
        try {
            $tmpDir = sys_get_temp_dir() . '/yt_subs_' . $this->sessionId;
            @mkdir($tmpDir, 0755, true);

            // Try manual subs first, then auto-generated
            foreach (['--write-subs', '--write-auto-subs'] as $subFlag) {
                $result = \Illuminate\Support\Facades\Process::timeout(60)->run([
                    'yt-dlp',
                    $subFlag,
                    '--skip-download',
                    '--sub-langs', 'en,ru,uz',
                    '--sub-format', 'vtt',
                    '--convert-subs', 'srt',
                    '-o', $tmpDir . '/sub',
                    '--no-playlist',
                    '--quiet',
                    $url,
                ]);

                // Find any .srt file written
                $files = glob($tmpDir . '/*.srt') ?: [];
                if (!empty($files)) {
                    $srt = file_get_contents($files[0]);
                    array_map('unlink', glob($tmpDir . '/*'));
                    @rmdir($tmpDir);
                    Log::info("[DUB] YouTube SRT fetched via yt-dlp ({$subFlag})", ['session' => $this->sessionId]);
                    return $srt ?: '';
                }
            }

            @rmdir($tmpDir);
        } catch (\Throwable $e) {
            Log::warning("[DUB] YouTube SRT fetch failed: " . $e->getMessage(), ['session' => $this->sessionId]);
        }

        return '';
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
