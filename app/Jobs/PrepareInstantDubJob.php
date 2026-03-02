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
            Log::info('Auto-detected subtitle language', ['language' => $detectedLang, 'session' => $this->sessionId]);
        }

        // 2. Parse SRT
        $segments = SrtParser::parse($srt);

        // Filter sound effects
        $segments = array_values(array_filter($segments, function ($seg) {
            $clean = preg_replace('/\[[^\]]*\]/', '', $seg['text']);
            $clean = preg_replace('/[-♪\s]+/', '', $clean);
            return $clean !== '';
        }));

        if (empty($segments)) {
            $this->updateStatus('error', 'No speakable segments');
            return;
        }

        // Clean bracket annotations
        foreach ($segments as &$seg) {
            $clean = preg_replace('/\[[^\]]*\]\s*/', '', $seg['text']);
            $clean = preg_replace('/^-\s*/', '', $clean);
            $clean = preg_replace('/\s+-\s+/', ' ', $clean);
            $seg['text'] = trim($clean);
        }
        unset($seg);
        $segments = array_values(array_filter($segments, fn($s) => trim($s['text']) !== ''));

        // Update total count so UI can show progress
        $this->updateSession(['total_segments' => count($segments), 'status' => 'processing']);

        // 3. Translate and dispatch TTS per batch — first TTS starts while rest translates
        // If subtitle language matches dub language (e.g. Uzbek→Uzbek), skip translation entirely
        $needsTranslation = $this->translateFrom && $this->translateFrom !== $this->language;
        $dispatched = 0;
        $allSpeakers = [];

        // Pass 1: Analyze characters from full dialogue (gender, age, relationships)
        $characterContext = '';
        if ($needsTranslation) {
            $this->updateStatus('Analyzing characters...');
            $characterContext = $this->analyzeCharacters($segments);
        }

        // Build full dialogue text for context in each batch
        $fullDialogue = [];
        foreach ($segments as $i => $seg) {
            $fullDialogue[] = ($i + 1) . '. ' . $seg['text'];
        }
        $fullDialogueText = implode("\n", $fullDialogue);

        // Pass 2: Translate in batches with full context
        $batches = array_chunk($segments, 15, true);
        foreach ($batches as $batch) {
            if ($needsTranslation) {
                $batch = $this->translateBatch($batch, $characterContext, $fullDialogueText);
            }

            // Collect speakers and update voice map incrementally
            foreach ($batch as $seg) {
                $tag = $seg['speaker'] ?? 'M1';
                $allSpeakers[$tag] = true;
            }
            $this->buildVoiceMap($allSpeakers);

            // Dispatch TTS immediately for this batch
            foreach ($batch as $i => $seg) {
                $text = trim($seg['text']);
                if ($text === '') continue;

                ProcessInstantDubSegmentJob::dispatch(
                    $this->sessionId, $i, $text,
                    $seg['start'], $seg['end'], $this->language,
                    $seg['speaker'] ?? 'M1',
                )->onQueue('segment-generation');
                $dispatched++;
            }
        }

        Log::info('Instant dub prepared', [
            'session' => $this->sessionId,
            'segments' => $dispatched,
            'needsTranslation' => $needsTranslation,
            'translateFrom' => $this->translateFrom,
            'detectedLang' => $detectedLang,
        ]);
    }

    private function analyzeCharacters(array $segments): string
    {
        $apiKey = config('services.openai.key');
        if (!$apiKey) return '';

        $lines = [];
        foreach ($segments as $i => $seg) {
            $lines[] = ($i + 1) . '. ' . $seg['text'];
        }

        $prompt = <<<'PROMPT'
Analyze this dialogue from a film/series. Identify ALL distinct speakers.

For each speaker provide:
- Tag: M1, M2 (male), F1, F2 (female), C1 (child)
- Who they are (name if mentioned, or role like "old man", "young woman", "boy")
- Approximate age category: child, young, adult, elderly
- Their relationships to other speakers

Then list which speaker says which line numbers.

Format your response EXACTLY like this:
CHARACTERS:
M1: Akbar, elderly man, father
F1: Nilufar, young woman, daughter of M1
M2: Jasur, young man, friend
C1: Ali, child, son of F1

LINES:
1-3,7,12: M1
4-6,8-9: F1
10-11: M2
13-15: C1
PROMPT;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o',
                    'temperature' => 0.2,
                    'messages' => [
                        ['role' => 'system', 'content' => $prompt],
                        ['role' => 'user', 'content' => implode("\n", $lines)],
                    ],
                ]);

            if ($response->successful()) {
                $result = trim($response->json('choices.0.message.content') ?? '');
                Log::info('Character analysis', ['session' => $this->sessionId, 'result' => $result]);
                return $result;
            }
        } catch (\Throwable $e) {
            Log::warning('Character analysis failed', ['error' => $e->getMessage()]);
        }

        return '';
    }

    private function translateBatch(array $batch, string $characterContext, string $fullDialogue): array
    {
        $apiKey = config('services.openai.key');
        if (!$apiKey) return $batch;

        $langNames = [
            'uz' => 'Uzbek', 'ru' => 'Russian', 'en' => 'English', 'tr' => 'Turkish',
            'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'ar' => 'Arabic',
            'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean',
        ];
        $toLang = $langNames[$this->language] ?? $this->language;

        $lines = [];
        foreach ($batch as $i => $seg) {
            $duration = round($seg['end'] - $seg['start'], 1);
            $maxChars = (int) round($duration * 12);
            $lines[] = ($i + 1) . '. [' . $duration . 's, max ' . $maxChars . ' chars] ' . $seg['text'];
        }

        $uzbekRules = '';
        if ($this->language === 'uz') {
            $uzbekRules = <<<'UZ'

UZBEK LANGUAGE RULES (CRITICAL):
- Address forms based on speaker→listener relationship:
  * Elderly/adult speaking to a child or much younger person → use "sen" (informal you), verb endings: -san, -ding, etc.
  * Young person speaking to elderly/adult → use "Siz" (formal you), verb endings: -siz, -dingiz, etc.
  * Peers of similar age → "sen" if close friends/family, "siz" if formal/strangers
  * Child to parent → "siz" or affectionate forms
- Use natural spoken Uzbek, not bookish/literary style
- Contractions and colloquial forms are preferred (e.g. "qilyapman" not "qilayotirman")
- Keep emotional tone: anger, tenderness, humor should come through in word choice
- Names and proper nouns: keep original, don't translate
UZ;
        }

        $systemPrompt = <<<PROMPT
You are an expert film/series dubbing translator. Your translations will be spoken aloud by TTS voice actors, so they must sound like natural spoken {$toLang} dialogue — not written subtitles.

CHARACTER ANALYSIS:
{$characterContext}

FULL DIALOGUE (for context — do NOT translate this, only use for understanding the scene):
{$fullDialogue}
{$uzbekRules}

TRANSLATION RULES:
1. Each line has [Ns, max M chars]. Translation MUST fit within that character limit — it will be spoken in that time slot.
2. Translate meaning, not words. Rephrase freely to sound natural in {$toLang}.
3. Keep the emotional register: if someone is angry, scared, joking — the translation must convey that.
4. Use the character analysis above to assign the correct speaker tag [M1], [F1], etc. to each line.
5. Preserve interruptions, hesitations, and conversational flow.

Format: "1. [M1] translated text"
Do not include timing info. Do not skip or merge lines. Keep exact numbering.
PROMPT;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o',
                    'temperature' => 0.3,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => "Translate ONLY these lines:\n\n" . implode("\n", $lines)],
                    ],
                ]);

            if ($response->successful()) {
                $translated = trim($response->json('choices.0.message.content') ?? '');
                foreach (preg_split('/\n+/', $translated) as $line) {
                    if (preg_match('/^(\d+)\.\s*\[([MFC]\d+)\]\s*(.+)/', $line, $lm)) {
                        $idx = (int) $lm[1] - 1;
                        if (isset($batch[$idx])) {
                            $batch[$idx]['speaker'] = $lm[2];
                            $batch[$idx]['text'] = trim($lm[3]);
                        }
                    } elseif (preg_match('/^(\d+)\.\s*(.+)/', $line, $lm)) {
                        $idx = (int) $lm[1] - 1;
                        if (isset($batch[$idx])) {
                            $batch[$idx]['text'] = trim($lm[2]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Batch translation failed', ['error' => $e->getMessage()]);
        }

        return $batch;
    }

    private function buildVoiceMap(array $speakers): void
    {
        // Edge-tts voices with pitch variants for speaker differentiation.
        // SardorNeural = male base, MadinaNeural = female base.
        // Pitch: edge-tts --pitch=+/-NHz shifts vocal tone.
        $maleVariants = [
            ['voice' => 'uz-UZ-SardorNeural', 'pitch' => '+0Hz',  'rate' => '+0%'],
            ['voice' => 'uz-UZ-SardorNeural', 'pitch' => '-8Hz',  'rate' => '-5%'],  // deeper, slower
            ['voice' => 'uz-UZ-SardorNeural', 'pitch' => '+6Hz',  'rate' => '+5%'],  // higher, faster
            ['voice' => 'uz-UZ-SardorNeural', 'pitch' => '-15Hz', 'rate' => '-8%'],  // much deeper
        ];
        $femaleVariants = [
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '+0Hz',  'rate' => '+0%'],
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '-6Hz',  'rate' => '-5%'],  // deeper
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '+8Hz',  'rate' => '+5%'],  // higher
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '-12Hz', 'rate' => '-8%'],  // much deeper
        ];

        // Child voices: higher pitch for younger sound
        $childVariants = [
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '+15Hz', 'rate' => '+10%'],  // high, fast
            ['voice' => 'uz-UZ-SardorNeural',  'pitch' => '+12Hz', 'rate' => '+8%'],   // boy
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

        Log::info('Voice map built', [
            'session' => $this->sessionId,
            'speakers' => array_keys($speakers),
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

            // Priority: uz > ru > first track
            $selectedUri = $tracks[0]['uri'];
            $detectedLang = 'unknown';

            foreach ($tracks as $track) {
                $lang = strtolower($track['lang']);
                if (str_contains($lang, 'uz')) {
                    $selectedUri = $track['uri'];
                    $detectedLang = 'uz';
                    break;
                }
            }

            if ($detectedLang === 'unknown') {
                foreach ($tracks as $track) {
                    $lang = strtolower($track['lang']);
                    if (str_contains($lang, 'ru')) {
                        $selectedUri = $track['uri'];
                        $detectedLang = 'ru';
                        break;
                    }
                }
            }

            Log::info('Subtitle track selected', [
                'language' => $detectedLang,
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
            Log::error('HLS sub fetch failed', ['error' => $e->getMessage()]);
            return null;
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
}
