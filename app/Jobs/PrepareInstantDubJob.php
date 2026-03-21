<?php

namespace App\Jobs;

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

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(
        public string $sessionId,
        public string $videoUrl,
        public string $language,
        public string $translateFrom,
        public string $srt,
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
        DownloadOriginalAudioJob::dispatch($this->sessionId, $this->videoUrl)
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
            $clean = preg_replace('/\[[^\]]*\]\s*/', '', $seg['text']);
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

    private function buildVoiceMap(array $speakers): void
    {
        $variants = \App\Services\VoiceVariants::forLanguage($this->language);
        $maleVariants = $variants['male'];
        $femaleVariants = $variants['female'];
        $childVariants = $variants['child'];

        $voiceMap = [];
        $maleIdx = 0;
        $femaleIdx = 0;
        $childIdx = 0;

        foreach (array_keys($speakers) as $tag) {
            if (str_starts_with($tag, 'C')) {
                $voiceMap[$tag] = $childVariants[$childIdx % count($childVariants)];
                $childIdx++;
            } elseif (str_starts_with($tag, 'M')) {
                $voiceMap[$tag] = $maleVariants[$maleIdx % count($maleVariants)];
                $maleIdx++;
            } else {
                $voiceMap[$tag] = $femaleVariants[$femaleIdx % count($femaleVariants)];
                $femaleIdx++;
            }
        }

        $voiceKey = "instant-dub:{$this->sessionId}:voices";
        Redis::setex($voiceKey, 50400, json_encode($voiceMap));

        Log::info("[DUB] Voice map built: " . implode(', ', array_keys($speakers)), [
            'session' => $this->sessionId,
            'map' => $voiceMap,
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

            $langPriority = ['ru', 'uz', 'en', 'tr'];
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

            // Build priority-ordered candidates
            $candidates = [];
            foreach ($langPriority as $preferred) {
                foreach ($tracks as $track) {
                    if ($track['langCode'] === $preferred) {
                        $candidates[] = $track;
                        break; // one per language
                    }
                }
            }
            // Add any unmatched tracks at the end
            foreach ($tracks as $track) {
                $dominated = false;
                foreach ($candidates as $c) {
                    if ($c['uri'] === $track['uri']) { $dominated = true; break; }
                }
                if (!$dominated) $candidates[] = $track;
            }

            if (empty($candidates)) return null;

            // Fetch all candidate tracks in parallel-ish, compare cue counts
            // Always pick by priority, but skip tracks that have <50% cues vs the richest
            $fetched = [];
            foreach ($candidates as $i => $candidate) {
                $fetched[$i] = $this->fetchSubtitleTrack($candidate, $baseUrl, $resolve);
                if ($i >= 3) break; // max 4 tracks
            }

            $maxCues = max(array_column($fetched, 'cues'));
            $bestIdx = 0;

            // Walk priority order, pick first track with >= 50% of the richest
            foreach ($fetched as $i => $result) {
                if ($result['cues'] >= $maxCues * 0.5 && $result['srt'] !== '') {
                    $bestIdx = $i;
                    break;
                }
            }

            // If no track meets threshold, just use the richest
            if ($fetched[$bestIdx]['cues'] < $maxCues * 0.5) {
                foreach ($fetched as $i => $result) {
                    if ($result['cues'] === $maxCues && $result['srt'] !== '') {
                        $bestIdx = $i;
                        break;
                    }
                }
            }

            $bestResult = $fetched[$bestIdx];
            $selected = $candidates[$bestIdx];

            $cueSummary = [];
            foreach ($fetched as $i => $r) {
                $cueSummary[] = "{$candidates[$i]['langCode']}:{$r['cues']}";
            }
            Log::info("[DUB] Subtitle track selected: {$selected['langCode']} ({$bestResult['cues']} cues) from [" . implode(', ', $cueSummary) . "]", [
                'session' => $this->sessionId,
            ]);

            return $bestResult['srt'] ? ['srt' => $bestResult['srt'], 'language' => $selected['langCode']] : null;
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

    public function failed(\Throwable $exception): void
    {
        Log::error("[DUB] PrepareInstantDubJob failed", [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);

        $this->updateStatus('error', 'Preparation failed: ' . Str::limit($exception->getMessage(), 100));
    }
}
