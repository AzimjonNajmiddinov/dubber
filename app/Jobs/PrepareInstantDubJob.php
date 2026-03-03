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

    public int $timeout = 600;
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

        // 3. Translate and dispatch TTS per batch — first TTS starts while rest translates
        // If subtitle language matches dub language (e.g. Uzbek→Uzbek), skip translation entirely
        $needsTranslation = $this->translateFrom && $this->translateFrom !== $this->language;
        $dispatched = 0;
        $allSpeakers = [];

        // Filter to speakable segments for TTS dispatch
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

        // Update total count so UI can show progress
        $this->updateSession(['total_segments' => count($segments), 'status' => 'processing']);

        $batches = array_chunk($segments, 15, true);
        $characterContext = '';

        foreach ($batches as $batchIdx => $batch) {
            if (!$needsTranslation) {
                // No translation — dispatch TTS directly
            } elseif ($batchIdx === 0) {
                // FIRST BATCH: translate + analyze characters IN PARALLEL
                // Translate batch 1 without character context (faster start)
                // Character analysis runs simultaneously for batches 2+
                $this->updateStatus('Translating...');

                $apiKey = config('services.openai.key');
                if ($apiKey) {
                    $analysisPrompt = $this->buildAnalysisPrompt($allSegments);
                    $translationMessages = $this->buildTranslationMessages($batch, '', $fullDialogueText);

                    $pool = Http::pool(function ($pool) use ($apiKey, $analysisPrompt, $translationMessages) {
                        $pool->as('analysis')->withToken($apiKey)->timeout(45)
                            ->post('https://api.openai.com/v1/chat/completions', [
                                'model' => 'gpt-4o',
                                'temperature' => 0.1,
                                'messages' => $analysisPrompt,
                            ]);
                        $pool->as('translation')->withToken($apiKey)->timeout(60)
                            ->post('https://api.openai.com/v1/chat/completions', [
                                'model' => 'gpt-4o',
                                'temperature' => 0.3,
                                'messages' => $translationMessages,
                            ]);
                    });

                    // Process character analysis result
                    if (isset($pool['analysis']) && $pool['analysis'] instanceof \Illuminate\Http\Client\Response && $pool['analysis']->successful()) {
                        $characterContext = trim($pool['analysis']->json('choices.0.message.content') ?? '');
                        Log::info('Character analysis', ['session' => $this->sessionId, 'result' => $characterContext]);
                    }

                    // Process translation result
                    if (isset($pool['translation']) && $pool['translation'] instanceof \Illuminate\Http\Client\Response && $pool['translation']->successful()) {
                        $batch = $this->parseTranslationResponse($batch, $pool['translation']->json('choices.0.message.content') ?? '');
                    } else {
                        Log::warning('Batch 0 translation failed', [
                            'session' => $this->sessionId,
                            'status' => isset($pool['translation']) && $pool['translation'] instanceof \Illuminate\Http\Client\Response ? $pool['translation']->status() : 'no response',
                        ]);
                    }
                }
            } else {
                // BATCHES 2+: translate with character context
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
                // Strip bracket annotations GPT may have kept (e.g. [narrator], [music])
                $text = trim(preg_replace('/\[[^\]]*\]\s*/', '', $text));
                // Normalize backtick → apostrophe (GPT sometimes uses ` in Uzbek words like o`zbek)
                $text = str_replace('`', '\'', $text);
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

    private function buildAnalysisPrompt(array $segments): array
    {
        $lines = [];
        foreach ($segments as $i => $seg) {
            $lines[] = ($i + 1) . '. ' . $seg['text'];
        }

        $sourceLangRules = '';
        if ($this->translateFrom === 'ru') {
            $sourceLangRules = <<<'RULES'

RUSSIAN GENDER DETECTION — use these clues to determine speaker gender:
- Past tense verb endings: -л (male: сказал, пошёл, знал), -ла (female: сказала, пошла, знала)
- Short adjective forms: рад/готов/должен (male), рада/готова/должна (female)
- Self-references: "я сам" (male), "я сама" (female)
- Russian names: masculine (Андрей, Сергей, Дмитрий, Алексей), feminine (Мария, Анна, Елена, Наталья)
- Patronymics: -ович/-евич (addressing male), -овна/-евна (addressing female)
- Diminutives: -ка, -очка, -енька often for females; -ик, -чик often for males

RUSSIAN FORMALITY DETECTION — maps to Uzbek sen/siz:
- "ты" forms (говоришь, идёшь, -ешь/-ишь endings) = informal → the listener is younger or close
- "Вы" forms (говорите, идёте, -ете/-ите endings) = formal → the listener is older or respected
- This tells you the RELATIONSHIP: if speaker uses "ты", they are senior to or close peers with the listener
RULES;
        } elseif ($this->translateFrom === 'en') {
            $sourceLangRules = <<<'RULES'

ENGLISH GENDER DETECTION — use these clues:
- Pronouns used about the speaker by others: "he/him/his" (male), "she/her" (female)
- Names: gendered names (John=male, Mary=female)
- Terms of address: "sir/mister/Mr." (male), "ma'am/miss/Mrs./Ms." (female)
- Family roles: "father/son/brother/husband" (male), "mother/daughter/sister/wife" (female)
- Vocal descriptions in stage directions: "he said", "she whispered"
RULES;
        }

        $prompt = <<<PROMPT
You are analyzing a film/series dialogue to identify speakers. This is CRITICAL for voice dubbing — wrong gender = wrong voice actor.

{$sourceLangRules}

TASK: Analyze every line carefully. Determine:
1. How many distinct speakers are in this dialogue
2. Each speaker's GENDER (from grammatical clues, names, context — see rules above)
3. Each speaker's approximate AGE (child, young ~15-25, adult ~25-50, elderly ~50+)
4. Relationships between speakers (parent-child, friends, spouses, boss-employee, etc.)
5. Which lines each speaker says

IMPORTANT:
- Do NOT guess gender randomly. If a line has "-ла" ending (Russian), it's FEMALE. If "-л" ending, it's MALE.
- Look at consecutive lines — dialogues alternate between speakers. If line 1 asks a question and line 2 answers, they are usually different speakers.
- A dash "-" at the start of a line often indicates a different speaker from the previous line.
- If someone is addressed by name, that person is the LISTENER, not the speaker.

Format your response EXACTLY like this:
CHARACTERS:
M1: [name/role], [age category], [relationship to others]
F1: [name/role], [age category], [relationship to others]

LINES:
1-3,7,12: M1
4-6,8-9: F1
PROMPT;

        return [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => implode("\n", $lines)],
        ];
    }

    private function buildTranslationMessages(array $batch, string $characterContext, string $fullDialogue): array
    {
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
            $rawText = $seg['raw_text'] ?? $seg['text'];
            $lines[] = ($i + 1) . '. [' . $duration . 's, max ' . $maxChars . ' chars] ' . $rawText;
        }

        $uzbekRules = '';
        $fromLangHint = '';
        if ($this->language === 'uz') {
            if ($this->translateFrom === 'ru') {
                $fromLangHint = <<<'HINT'

RUSSIAN→UZBEK MAPPING:
- Russian "ты" (informal) → Uzbek "sen": speaker is older/senior or they are close friends
- Russian "Вы" (formal) → Uzbek "Siz": speaker is younger or it's a formal setting
- Keep this consistent: if character A uses "ты" to B in Russian, A must use "sen" to B in Uzbek throughout
HINT;
            }

            $uzbekRules = <<<UZ

UZBEK LANGUAGE RULES (CRITICAL):
- SEN/SIZ — this is the #1 priority, getting it wrong ruins the dub:
  * Look at CHARACTER ANALYSIS above for age and relationships
  * Elderly/parent → child/young person: always "sen" (-san, -ding, -yapsanmi)
  * Young person → elderly/parent: always "Siz" (-siz, -dingiz, -yapsizmi)
  * Same-age close friends: "sen"
  * Same-age strangers/formal: "Siz"
  * Child → parent: "Siz" (respectful)
  * Husband ↔ wife: usually "sen" (intimate)
  * Boss → employee: can be "sen"; employee → boss: "Siz"
{$fromLangHint}
- STYLE — spoken Uzbek, like real people talk:
  * Use colloquial forms: "qilyapman" not "qilayotirman", "ketyapman" not "ketayotirman"
  * Use "bor" not "mavjud", "yo'q" not "mavjud emas"
  * Contractions: "nimaga" not "nima uchun" (when casual)
  * Emotional words: "voy!" (surprise), "ey!" (calling), "qo'ying!" (stop it!)
- Names and proper nouns: keep original, don't translate
- Keep emotional register: anger, love, fear, humor must come through
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
2. Lines may contain annotations like [music], [laughing], [whispering], [door opens] etc. — use these to understand the scene mood and context, but translate ONLY the spoken dialogue part. Do not include the annotations in your translation.
3. Translate meaning, not words. Rephrase freely to sound natural in {$toLang}.
4. Keep the emotional register: if someone is angry, scared, joking, whispering — the translation must convey that.
5. Use the character analysis above to assign the correct speaker tag [M1], [F1], etc. to each line.
6. Preserve interruptions, hesitations, and conversational flow.

Format: "1. [M1] translated text"
Do not include timing info. Do not skip or merge lines. Keep exact numbering.
PROMPT;

        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Translate ONLY these lines:\n\n" . implode("\n", $lines)],
        ];
    }

    private function parseTranslationResponse(array $batch, string $content): array
    {
        $translated = trim($content);
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
        return $batch;
    }

    private function translateBatch(array $batch, string $characterContext, string $fullDialogue): array
    {
        $apiKey = config('services.openai.key');
        if (!$apiKey) return $batch;

        $messages = $this->buildTranslationMessages($batch, $characterContext, $fullDialogue);

        // Try up to 2 times on failure
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::withToken($apiKey)
                    ->timeout(90)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o',
                        'temperature' => 0.3,
                        'messages' => $messages,
                    ]);

                if ($response->successful()) {
                    return $this->parseTranslationResponse($batch, $response->json('choices.0.message.content') ?? '');
                }

                Log::warning('Batch translation API error', [
                    'session' => $this->sessionId,
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 200),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Batch translation failed', [
                    'session' => $this->sessionId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < 2) {
                sleep(2);
            }
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
