<?php

namespace App\Jobs;

use App\Services\ElevenLabs\ElevenLabsClient;
use App\Services\ElevenLabs\SpeakerSampleExtractor;
use App\Services\SrtParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
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

        // 2b. Download original audio track for background mixing (20% volume)
        $this->updateSession(['progress' => 'Downloading background audio...']);
        $originalAudioPath = $this->downloadOriginalAudio();
        if ($originalAudioPath) {
            $this->updateSession(['original_audio_path' => $originalAudioPath]);
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
            $clean = preg_replace('/\[[^\]]*\]\s*/', '', $seg['text']);
            $clean = preg_replace('/^-\s*/', '', $clean);
            $clean = preg_replace('/\s+-\s+/', ' ', $clean);
            $seg['text'] = trim($clean);
        }
        unset($seg);
        $segments = array_values(array_filter($segments, fn($s) => trim($s['text']) !== ''));

        $totalBatches = (int) ceil(count($segments) / 15);
        $this->updateSession(['total_segments' => count($segments), 'status' => 'processing']);

        if (!$needsTranslation) {
            // No translation — build voice map and dispatch TTS directly
            $allSpeakers = [];
            foreach ($segments as $seg) {
                $tag = $seg['speaker'] ?? 'M1';
                $allSpeakers[$tag] = true;
            }
            $this->buildVoiceMap($allSpeakers);

            // Clone voices with ElevenLabs if driver is set to elevenlabs
            if ($originalAudioPath && config('dubber.tts.default') === 'elevenlabs') {
                $this->cloneVoicesWithElevenLabs(array_keys($allSpeakers), $segments, $originalAudioPath);
            }

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

        // 4. Store batches in Redis for per-batch translation jobs
        $batches = array_chunk($segments, 15);
        foreach ($batches as $batchIdx => $batch) {
            $batchKey = "instant-dub:{$this->sessionId}:batch:{$batchIdx}";
            Redis::setex($batchKey, 50400, json_encode(array_values($batch)));
        }

        // Store all segments and full dialogue in Redis (avoids duplicating in every job payload)
        $allSegmentsKey = "instant-dub:{$this->sessionId}:all-segments";
        Redis::setex($allSegmentsKey, 50400, json_encode($allSegments));
        Redis::setex("instant-dub:{$this->sessionId}:full-dialogue", 50400, $fullDialogueText);

        // 5. Dispatch first batch job — it chains the rest
        TranslateInstantDubBatchJob::dispatch(
            $this->sessionId,
            0,
            $totalBatches,
            $this->language,
            $this->translateFrom,
        )->onQueue('default');

        Log::info("[DUB] [{$title}] Prepared, translation chain started: " . count($segments) . " segments, {$totalBatches} batches, {$this->translateFrom}→{$this->language}", [
            'session' => $this->sessionId,
        ]);
    }

    private function buildVoiceMap(array $speakers): void
    {
        $maleVariants = [
            ['voice' => 'uz-UZ-SardorNeural', 'pitch' => '+0Hz',  'rate' => '+0%'],
            ['voice' => 'uz-UZ-SardorNeural', 'pitch' => '-8Hz',  'rate' => '-5%'],
            ['voice' => 'uz-UZ-SardorNeural', 'pitch' => '+6Hz',  'rate' => '+5%'],
            ['voice' => 'uz-UZ-SardorNeural', 'pitch' => '-15Hz', 'rate' => '-8%'],
        ];
        $femaleVariants = [
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '+0Hz',  'rate' => '+0%'],
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '-6Hz',  'rate' => '-5%'],
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '+8Hz',  'rate' => '+5%'],
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '-12Hz', 'rate' => '-8%'],
        ];
        $childVariants = [
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '+15Hz', 'rate' => '+10%'],
            ['voice' => 'uz-UZ-SardorNeural',  'pitch' => '+12Hz', 'rate' => '+8%'],
        ];

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

            // Parse all subtitle tracks: find each EXT-X-MEDIA line with TYPE=SUBTITLES
            preg_match_all('/^#EXT-X-MEDIA:.*TYPE=SUBTITLES.*$/m', $master, $subLines);
            $tracks = [];
            foreach ($subLines[0] ?? [] as $line) {
                $lang = preg_match('/LANGUAGE="([^"]*)"/', $line, $lm) ? $lm[1] : 'unknown';
                $uri = preg_match('/URI="([^"]+)"/', $line, $um) ? $um[1] : null;
                if ($uri) $tracks[] = ['lang' => $lang, 'uri' => $uri];
            }

            if (empty($tracks)) {
                // Fallback: try simpler match for any subtitle URI
                if (!preg_match('/TYPE=SUBTITLES.*?URI="([^"]+)"/', $master, $m)) return null;
                $tracks = [['lang' => 'unknown', 'uri' => $m[1]]];
            }

            // Priority: ru > uz > en/tr > first track
            // Russian→Uzbek translation is highest quality, prefer it over raw Uzbek subs
            $langPriority = ['ru', 'uz', 'en', 'tr'];
            $langPatterns = [
                'ru' => ['ru', 'rus', 'russian'],
                'uz' => ['uz', 'uzb', 'uzbek'],
                'en' => ['en', 'eng', 'english'],
                'tr' => ['tr', 'tur', 'turkish'],
            ];

            $selectedUri = $tracks[0]['uri'];
            $detectedLang = 'unknown';

            // Try to detect language of first track as fallback
            $firstLang = strtolower($tracks[0]['lang']);
            foreach ($langPatterns as $code => $patterns) {
                foreach ($patterns as $p) {
                    if (str_contains($firstLang, $p)) {
                        $detectedLang = $code;
                        break 2;
                    }
                }
            }

            // Now pick best track by priority
            foreach ($langPriority as $preferred) {
                foreach ($tracks as $track) {
                    $lang = strtolower($track['lang']);
                    $matched = false;
                    foreach ($langPatterns[$preferred] as $p) {
                        if (str_contains($lang, $p)) { $matched = true; break; }
                    }
                    if ($matched) {
                        $selectedUri = $track['uri'];
                        $detectedLang = $preferred;
                        break 2;
                    }
                }
            }

            Log::info("[DUB] Subtitle track selected: {$detectedLang}", [
                'session' => $this->sessionId,
                'uri' => $selectedUri,
                'available' => array_map(fn($t) => $t['lang'], $tracks),
            ]);

            $query = parse_url($url, PHP_URL_QUERY);
            $resolve = function ($base, $rel) use ($url, $query) {
                if (str_starts_with($rel, 'http')) return $rel;
                $r = rtrim($base, '/') . '/' . $rel;
                return $query ? "{$r}?{$query}" : $r;
            };

            $subsUrl = $resolve($baseUrl, $selectedUri);
            $subsResp = Http::timeout(10)->get($subsUrl);
            if ($subsResp->failed()) return null;

            $subsBase = preg_replace('#/[^/]+$#', '/', $subsUrl);
            preg_match_all('/^(\S+\.vtt)$/m', $subsResp->body(), $vttFiles);
            if (empty($vttFiles[1])) return null;

            // Fetch VTT segments concurrently via pool
            $allVtt = '';
            $pool = Http::pool(function ($pool) use ($vttFiles, $subsBase, $resolve) {
                foreach ($vttFiles[1] as $i => $vttFile) {
                    $pool->as((string) $i)->timeout(8)->get($resolve($subsBase, $vttFile));
                }
            });

            foreach ($pool as $resp) {
                if ($resp instanceof \Illuminate\Http\Client\Response && $resp->successful()) {
                    $allVtt .= "\n" . $resp->body();
                }
            }

            // Parse VTT → SRT (inline for speed)
            preg_match_all(
                '/(\d+)\n(\d{2}:\d{2}:\d{2}\.\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}\.\d{3})\n((?:(?!\n\n|\nWEBVTT).)+)/s',
                $allVtt, $matches, PREG_SET_ORDER
            );

            $seen = [];
            $srt = '';
            $num = 0;
            foreach ($matches as $m) {
                $key = "{$m[1]}|{$m[2]}|{$m[3]}";
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $text = trim($m[4]);
                if ($text === '' || preg_match('/^\[.*\]$/', $text) || preg_match('/^♪/', $text)) continue;
                $num++;
                $srt .= "{$num}\n" . str_replace('.', ',', $m[2]) . ' --> ' . str_replace('.', ',', $m[3]) . "\n{$text}\n\n";
            }

            return $srt ? ['srt' => $srt, 'language' => $detectedLang] : null;
        } catch (\Throwable $e) {
            Log::error("[DUB] HLS sub fetch failed", ['session' => $this->sessionId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function downloadOriginalAudio(): ?string
    {
        $videoUrl = $this->videoUrl;
        if (!str_contains($videoUrl, '.m3u8')) return null;

        try {
            $masterResp = Http::timeout(10)->get($videoUrl);
            if ($masterResp->failed()) return null;

            $master = $masterResp->body();
            $urlWithoutQuery = strtok($videoUrl, '?');
            $baseUrl = preg_replace('#/[^/]+$#', '/', $urlWithoutQuery);
            $query = parse_url($videoUrl, PHP_URL_QUERY) ?? '';

            // Find first audio track with URI
            preg_match_all('/^#EXT-X-MEDIA:.*TYPE=AUDIO.*$/m', $master, $audioLines);
            $audioUri = null;
            foreach ($audioLines[0] ?? [] as $line) {
                if (preg_match('/URI="([^"]+)"/', $line, $m)) {
                    $audioUri = $m[1];
                    break;
                }
            }

            if (!$audioUri) return null;

            // Resolve to absolute URL
            $audioPlaylistUrl = str_starts_with($audioUri, 'http') ? $audioUri : $baseUrl . $audioUri;
            if ($query) $audioPlaylistUrl .= (str_contains($audioPlaylistUrl, '?') ? '&' : '?') . $query;

            // Fetch audio playlist and rewrite segment URIs to absolute (CDN may require auth tokens)
            $audioResp = Http::timeout(10)->get($audioPlaylistUrl);
            if ($audioResp->failed()) return null;

            $audioPlaylist = $audioResp->body();
            $audioBase = preg_replace('#/[^/]+$#', '/', strtok($audioPlaylistUrl, '?'));

            $rewritten = '';
            foreach (explode("\n", $audioPlaylist) as $pLine) {
                $trimmed = trim($pLine);
                if ($trimmed !== '' && !str_starts_with($trimmed, '#')) {
                    if (!str_starts_with($trimmed, 'http')) {
                        $trimmed = $audioBase . $trimmed;
                    }
                    if ($query && !str_contains($trimmed, '?')) {
                        $trimmed .= '?' . $query;
                    }
                    $rewritten .= $trimmed . "\n";
                } else {
                    $rewritten .= $pLine . "\n";
                }
            }

            // Save rewritten playlist and download via ffmpeg
            $tmpDir = storage_path("app/instant-dub/{$this->sessionId}");
            @mkdir($tmpDir, 0755, true);
            $localPlaylist = "{$tmpDir}/audio_playlist.m3u8";
            $outputPath = "{$tmpDir}/original_audio.m4a";

            file_put_contents($localPlaylist, $rewritten);

            $result = Process::timeout(300)->run([
                'ffmpeg', '-y',
                '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
                '-i', $localPlaylist,
                '-vn', '-ac', '1', '-ar', '44100',
                '-c:a', 'aac', '-b:a', '96k',
                $outputPath,
            ]);

            @unlink($localPlaylist);

            if ($result->successful() && file_exists($outputPath) && filesize($outputPath) > 1000) {
                Log::info("[DUB] Original audio downloaded (" . round(filesize($outputPath) / 1024) . " KB)", [
                    'session' => $this->sessionId,
                ]);
                return $outputPath;
            }

            Log::warning("[DUB] Original audio download failed", [
                'session' => $this->sessionId,
                'error' => Str::limit($result->errorOutput(), 300),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[DUB] Original audio download error", [
                'session' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function cloneVoicesWithElevenLabs(array $speakers, array $segments, string $originalAudioPath): void
    {
        $apiKey = config('services.elevenlabs.api_key', '');
        if ($apiKey === '') return;

        try {
            $extractor = new SpeakerSampleExtractor();
            $samples = $extractor->extractSamples($originalAudioPath, $segments);

            if (empty($samples)) {
                Log::info("[DUB] No speaker samples extracted, skipping ElevenLabs cloning", ['session' => $this->sessionId]);
                return;
            }

            $client = new ElevenLabsClient($apiKey);
            $voiceKey = "instant-dub:{$this->sessionId}:voices";
            $voiceMap = json_decode(Redis::get($voiceKey), true) ?? [];
            $clonedVoiceIds = [];

            foreach ($samples as $tag => $samplePath) {
                try {
                    $voiceId = $client->addVoice("dub-{$this->sessionId}-{$tag}", [$samplePath]);
                    $voiceMap[$tag] = ['driver' => 'elevenlabs', 'voice_id' => $voiceId];
                    $clonedVoiceIds[] = $voiceId;
                    Log::info("[DUB] ElevenLabs voice cloned: {$tag} → {$voiceId}", [
                        'session' => $this->sessionId,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("[DUB] ElevenLabs clone failed for {$tag}, keeping fallback", [
                        'session' => $this->sessionId,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    @unlink($samplePath);
                }
            }

            if (!empty($clonedVoiceIds)) {
                Redis::setex($voiceKey, 50400, json_encode($voiceMap));
                Redis::setex("instant-dub:{$this->sessionId}:elevenlabs-voices", 50400, json_encode($clonedVoiceIds));
            }
        } catch (\Throwable $e) {
            Log::warning("[DUB] ElevenLabs voice cloning failed entirely", [
                'session' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
        }
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
