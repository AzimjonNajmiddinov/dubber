<?php

namespace App\Jobs;

use App\Jobs\Concerns\ParsesInstantDubTranslation;
use App\Services\AnthropicModelResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Support\DubSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class TranslateInstantDubBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use ParsesInstantDubTranslation;

    public int $timeout = 240;
    public int $tries = 1; // Retries handled internally; chain must not break

    private string $title = 'Untitled';

    public function __construct(
        public string $sessionId,
        public int    $batchIndex,
        public int    $totalBatches,
        public string $language,
        public string $translateFrom,
        public int    $segmentOffset = 0,
        public int    $waveIndex = 0,
    ) {}

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session) return;
        $this->title = $session['title'] ?? 'Untitled';

        if (($session['status'] ?? '') === 'stopped') {
            Log::info("[DUB] [{$this->title}] Batch {$this->batchIndex} translation stopped", ['session' => $this->sessionId]);
            return;
        }

        $fullDialogueText = Redis::get(DubSession::fullDialogueKey($this->sessionId)) ?? '';

        $batchKey = $this->batchKey($this->batchIndex);
        $batchJson = Redis::get($batchKey);
        if (!$batchJson) {
            Log::error("[DUB] [{$this->title}] Batch {$this->batchIndex} data missing from Redis", ['session' => $this->sessionId]);
            return;
        }
        $batch = json_decode($batchJson, true);

        $batchNum = $this->batchIndex + 1;
        $this->updateSession(['status' => 'Translating...', 'progress' => "Translating ({$batchNum}/{$this->totalBatches})..."]);

        // Preserve original source text before translation overwrites $seg['text']
        foreach ($batch as &$seg) {
            $seg['source_text'] = $seg['text'];
        }
        unset($seg);

        try {
            // Translate
            if ($this->waveIndex === 0 && $this->batchIndex === 0) {
                $batch = $this->translateBatchZero($batch, $fullDialogueText);
            } else {
                $batch = $this->translateBatchWithContext($batch, $fullDialogueText);
            }

            // Merge voice map (additive — don't overwrite existing speakers)
            $speakers = [];
            foreach ($batch as $seg) {
                $tag = $seg['speaker'] ?? 'M1';
                $speakers[$tag] = true;
            }
            $this->mergeVoiceMap($speakers);

        } catch (\Throwable $e) {
            $this->updateSession([
                'status' => 'error',
                'error' => 'Translation failed: ' . Str::limit($e->getMessage(), 500),
            ]);
            Log::error("[DUB] [{$this->title}] Batch {$this->batchIndex} translation failed; stopping session: " . Str::limit($e->getMessage(), 200), [
                'session' => $this->sessionId,
            ]);
            return;
        }

        // Dispatch TTS only after every line has translated speech or an explicit silent placeholder.
        // Post-process: merge rare speakers into nearest common speaker
        $batch = $this->mergeRareSpeakers($batch);

        $this->updateSession(['progress' => "Generating audio ({$batchNum}/{$this->totalBatches})..."]);
        $globalOffset = $this->segmentOffset + $this->batchIndex * 15;

        // Peek at next batch's first segment start time for slotEnd of this batch's last segment.
        // With parallel dispatch, next batch key may already be consumed — fall back to allSegments.
        $nextBatchFirstStart = null;
        $nextBatchIdx = $this->batchIndex + 1;
        if ($nextBatchIdx < $this->totalBatches) {
            $nextBatchJson = Redis::get($this->batchKey($nextBatchIdx));
            if ($nextBatchJson) {
                $nb = json_decode($nextBatchJson, true);
                $nextBatchFirstStart = (float) ($nb[0]['start'] ?? 0);
            } else {
                $allSegsJson = Redis::get(DubSession::speakableSegmentsKey($this->sessionId));
                if ($allSegsJson) {
                    $allSegs = json_decode($allSegsJson, true);
                    $nextGlobalIdx = $this->segmentOffset + ($nextBatchIdx * 15);
                    $nextBatchFirstStart = (float) ($allSegs[$nextGlobalIdx]['start_time'] ?? 0) ?: null;
                }
            }
        }

        foreach ($batch as $localIdx => $seg) {
            $text = trim($seg['text']);
            $text = trim(preg_replace('/\[[^\]]*\]\s*/', '', $text));
            $text = $this->scrubUtf8(str_replace('`', '\'', $text));
            $sourceText = isset($seg['source_text']) ? $this->scrubUtf8($seg['source_text']) : null;

            // slotEnd = next segment's start (within batch or from next batch)
            $slotEnd = isset($batch[$localIdx + 1])
                ? (float) $batch[$localIdx + 1]['start']
                : $nextBatchFirstStart; // null for last segment of last batch

            ProcessInstantDubSegmentJob::dispatch(
                $this->sessionId,
                $globalOffset + $localIdx,
                $text,
                $seg['start'],
                $seg['end'],
                $this->language,
                $seg['speaker'] ?? 'M1',
                $slotEnd,
                $sourceText,
                $seg['delivery'] ?? null,
                $this->waveIndex,
            )->onQueue('segment-generation');
        }

        // Clean up batch key
        Redis::del($batchKey);

        if ($this->batchIndex === 0 && $this->totalBatches > 1) {
            // Batch 0 has written character context to Redis — dispatch ALL remaining
            // batches in parallel now. Sequential chaining would add N×5s latency.
            for ($i = 1; $i < $this->totalBatches; $i++) {
                self::dispatch(
                    $this->sessionId, $i, $this->totalBatches,
                    $this->language, $this->translateFrom, $this->segmentOffset, $this->waveIndex,
                )->onQueue('segment-generation');
            }
        }

        // Atomic counter: last batch to finish does cleanup (non-deterministic with parallel dispatch)
        $remaining = Redis::decr($this->batchesRemainingKey());
        if ($remaining <= 0) {
            Redis::del($this->batchesRemainingKey());
            Log::info("[DUB] [{$this->title}] Wave {$this->waveIndex} translation batches complete", [
                'session' => $this->sessionId,
            ]);
        }
    }

    protected function translationLogPrefix(): string
    {
        return "[DUB] [{$this->title}] Batch {$this->batchIndex}";
    }

    protected function translationGlobalOffset(): int
    {
        return $this->segmentOffset + ($this->batchIndex * 15);
    }

    private function batchKey(int $batchIndex): string
    {
        if ($this->waveIndex > 0) {
            return "instant-dub:{$this->sessionId}:w{$this->waveIndex}:batch:{$batchIndex}";
        }

        return DubSession::batchKey($this->sessionId, $batchIndex);
    }

    private function batchesRemainingKey(): string
    {
        if ($this->waveIndex > 0) {
            return "instant-dub:{$this->sessionId}:w{$this->waveIndex}:batches-remaining";
        }

        return "instant-dub:{$this->sessionId}:batches-remaining";
    }

    private function translateBatchZero(array $batch, string $fullDialogueText): array
    {
        // Load all segments for character analysis
        $allSegmentsJson = Redis::get(DubSession::allSegmentsKey($this->sessionId));
        $allSegments = $allSegmentsJson ? json_decode($allSegmentsJson, true) : [];

        // Build prompts for both analysis and translation
        $analysisPrompt = $this->buildAnalysisPrompt($allSegments);
        // Translate WITHOUT character context (will be available for batch 1+)
        $translationMessages = $this->buildTranslationMessages($batch, '', $fullDialogueText);

        // Run analysis + translation in PARALLEL via Http::pool()
        $anthropicKey = config('services.anthropic.key');
        $openaiKey = config('services.openai.key');

        $analysisSystem = '';
        $analysisUserMessages = [];
        $translationSystem = '';
        $translationUserMessages = [];

        foreach ($analysisPrompt as $msg) {
            if ($msg['role'] === 'system') $analysisSystem = $msg['content'];
            else $analysisUserMessages[] = $msg;
        }
        foreach ($translationMessages as $msg) {
            if ($msg['role'] === 'system') $translationSystem = $msg['content'];
            else $translationUserMessages[] = $msg;
        }

        $characterContext = '';
        $translationResult = null;
        $providerFailures = [];

        if ($anthropicKey) {
            $anthropicModel = AnthropicModelResolver::primary();
            // Fire both requests to Claude in parallel
            $responses = Http::pool(function ($pool) use ($anthropicKey, $anthropicModel, $analysisSystem, $analysisUserMessages, $translationSystem, $translationUserMessages) {
                $pool->as('analysis')
                    ->withHeaders([
                        'x-api-key' => $anthropicKey,
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ])
                    ->timeout(60)
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => $anthropicModel,
                        'max_tokens' => 4096,
                        'system' => $analysisSystem,
                        'messages' => $analysisUserMessages,
                    ]);

                $pool->as('translation')
                    ->withHeaders([
                        'x-api-key' => $anthropicKey,
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ])
                    ->timeout(60)
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => $anthropicModel,
                        'max_tokens' => 4096,
                        'system' => $translationSystem,
                        'messages' => $translationUserMessages,
                    ]);
            });

            // Process analysis result
            if (isset($responses['analysis']) && $responses['analysis'] instanceof \Illuminate\Http\Client\Response && $responses['analysis']->successful()) {
                $characterContext = trim($responses['analysis']->json('content.0.text') ?? '');
                $this->extractAndStoreTitle($characterContext);
                Log::info("[DUB] [{$this->title}] Character analysis (Claude, parallel): " . Str::limit($characterContext, 200), ['session' => $this->sessionId]);
            }

            // Process translation result
            if (isset($responses['translation']) && $responses['translation'] instanceof \Illuminate\Http\Client\Response && $responses['translation']->successful()) {
                $translationResult = trim($responses['translation']->json('content.0.text') ?? '');
            }
        }

        if (!$characterContext && $anthropicKey) {
            $characterContext = $this->callAnthropic($analysisPrompt) ?? '';
            if ($characterContext !== '') {
                $this->extractAndStoreTitle($characterContext);
                Log::info("[DUB] [{$this->title}] Character analysis (Claude fallback): " . Str::limit($characterContext, 200), ['session' => $this->sessionId]);
            }
        }

        // Fallback: GPT for analysis if Claude failed
        if (!$characterContext && $openaiKey) {
            try {
                $resp = Http::withToken($openaiKey)->timeout(45)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o',
                        'temperature' => 0.1,
                        'messages' => $analysisPrompt,
                    ]);
                if ($resp->successful()) {
                    $characterContext = trim($resp->json('choices.0.message.content') ?? '');
                    $this->extractAndStoreTitle($characterContext);
                    Log::info("[DUB] [{$this->title}] Character analysis (GPT fallback): " . Str::limit($characterContext, 200), ['session' => $this->sessionId]);
                }
            } catch (\Throwable $e) {
                Log::warning("[DUB] [{$this->title}] GPT analysis failed: " . $e->getMessage(), ['session' => $this->sessionId]);
            }
        }

        // Store character context for subsequent batches
        Redis::setex(DubSession::characterContextKey($this->sessionId), DubSession::TTL, $characterContext);

        // Use parallel translation result if available
        if ($translationResult) {
            $parsed = $this->tryParseProviderTranslation('Claude', $batch, $translationResult, $providerFailures);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        if ($anthropicKey) {
            $translationResult = $this->callAnthropic($translationMessages);
            if ($translationResult) {
                $parsed = $this->tryParseProviderTranslation('Claude', $batch, $translationResult, $providerFailures);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        // Fallback: GPT-4o for translation
        return $this->callOpenAiWithRetry($batch, $translationMessages);
    }

    private function translateBatchWithContext(array $batch, string $fullDialogueText): array
    {
        $characterContext = Redis::get(DubSession::characterContextKey($this->sessionId)) ?? '';
        $messages = $this->buildTranslationMessages($batch, $characterContext, $fullDialogueText);
        $providerFailures = [];

        // Try Claude Sonnet first
        $result = $this->callAnthropic($messages);
        if ($result !== null) {
            Log::debug("[DUB] [{$this->title}] Batch {$this->batchIndex} Claude response: " . Str::limit($result, 300), [
                'session' => $this->sessionId,
            ]);
            $parsed = $this->tryParseProviderTranslation('Claude', $batch, $result, $providerFailures);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        // Fallback: GPT-4o with retry
        Log::warning("[DUB] [{$this->title}] Batch {$this->batchIndex} Claude returned null, falling back to GPT", [
            'session' => $this->sessionId,
        ]);
        return $this->callOpenAiWithRetry($batch, $messages);
    }

    private function callOpenAiWithRetry(array $batch, array $messages): array
    {
        $apiKey = config('services.openai.key');
        if (!$apiKey) {
            $reason = $this->lastAnthropicFailure
                ? ' Last provider failure: ' . $this->lastAnthropicFailure
                : '';
            throw new \RuntimeException('OpenAI API key missing and no translation provider succeeded.' . $reason . ' Configure ANTHROPIC_API_KEY or OPENAI_API_KEY on the server, clear Laravel config cache, and restart queue workers.');
        }

        for ($attempt = 1; $attempt <= 4; $attempt++) {
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

                Log::warning("[DUB] Batch {$this->batchIndex} translation API error (attempt {$attempt}): HTTP " . $response->status(), [
                    'session' => $this->sessionId,
                    'body' => Str::limit($response->body(), 200),
                ]);

                if ($response->status() === 429) {
                    $wait = min(2 ** $attempt, 15);
                    $this->updateSession(['last_warning' => "OpenAI rate limited, retrying in {$wait}s... (attempt {$attempt}/4)"]);
                    sleep($wait);
                    continue;
                }

                $this->updateSession(['last_warning' => "Translation API error {$response->status()}, retrying..."]);
            } catch (\Throwable $e) {
                Log::warning("[DUB] Batch {$this->batchIndex} translation failed (attempt {$attempt}): " . $e->getMessage(), [
                    'session' => $this->sessionId,
                ]);
                $this->updateSession(['last_warning' => "Translation error: " . Str::limit($e->getMessage(), 100)]);
            }

            if ($attempt < 4) {
                sleep(2);
            }
        }

        throw new \RuntimeException('OpenAI translation failed after retries.');
    }

    private function extractAndStoreTitle(string $analysisText): void
    {
        $session = DubSession::get($this->sessionId) ?? [];
        $changed = false;

        // Extract title
        if (preg_match('/^TITLE:\s*(.+)/m', $analysisText, $m)) {
            $detected = trim($m[1]);
            if ($detected && strtolower($detected) !== 'unknown' && $this->title === 'Untitled') {
                $this->title = $detected;
                $session['title'] = $detected;
                $changed = true;
                Log::info("[DUB] [{$this->title}] Auto-detected title from dialogue", ['session' => $this->sessionId]);
            }
        }

        // Extract speaker genders from CHARACTERS section (M1: name, F1: name, etc.)
        $speakers = [];
        if (preg_match_all('/^([MFC]\d+):\s*(.+)/m', $analysisText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tag = $match[1];
                $description = trim($match[2]);
                $gender = match (true) {
                    str_starts_with($tag, 'M') => 'male',
                    str_starts_with($tag, 'F') => 'female',
                    str_starts_with($tag, 'C') => 'child',
                    default => 'unknown',
                };
                $speakers[$tag] = [
                    'gender' => $gender,
                    'description' => $description,
                ];
            }
        }

        if (!empty($speakers)) {
            $session['speakers'] = $speakers;
            $changed = true;
            Log::info("[DUB] [{$this->title}] Extracted " . count($speakers) . " speaker profiles: " . implode(', ', array_keys($speakers)), [
                'session' => $this->sessionId,
            ]);
        }

        if ($changed) {
            DubSession::save($this->sessionId, $session);
        }
    }

    /**
     * Merge rare speakers (≤2 segments) into the most common speaker of same gender.
     * Prevents 2-person dialogues from getting 4+ different voices.
     */
    private function mergeRareSpeakers(array $batch): array
    {
        // Count segments per speaker across ALL session chunks (not just this batch)
        $allCounts = [];
        $s = DubSession::get($this->sessionId);
        $total = $s ? (int) ($s['total_segments'] ?? 0) : 0;

        // Count from already-processed chunks
        for ($i = 0; $i < $total; $i++) {
            $chunkJson = Redis::get(DubSession::chunkKey($this->sessionId, $i));
            if ($chunkJson) {
                $chunk = json_decode($chunkJson, true);
                $tag = $chunk['speaker'] ?? 'M1';
                $allCounts[$tag] = ($allCounts[$tag] ?? 0) + 1;
            }
        }
        // Count from current batch
        foreach ($batch as $seg) {
            $tag = $seg['speaker'] ?? 'M1';
            $allCounts[$tag] = ($allCounts[$tag] ?? 0) + 1;
        }

        if (count($allCounts) <= 2) return $batch; // 2 speakers or less — no merging needed

        // Find the most common male and female speakers
        $maleCounts = [];
        $femaleCounts = [];
        foreach ($allCounts as $tag => $count) {
            if (str_starts_with($tag, 'F')) {
                $femaleCounts[$tag] = $count;
            } else {
                $maleCounts[$tag] = $count;
            }
        }
        arsort($maleCounts);
        arsort($femaleCounts);

        $topMale = $maleCounts ? array_key_first($maleCounts) : 'M1';
        $topFemale = $femaleCounts ? array_key_first($femaleCounts) : 'F1';

        // Merge: speakers with ≤2 segments → top speaker of same gender
        $mergeMap = [];
        $threshold = max(2, (int) ($total * 0.05)); // ≤2 or ≤5% of total
        foreach ($allCounts as $tag => $count) {
            if ($count <= $threshold) {
                $target = str_starts_with($tag, 'F') ? $topFemale : $topMale;
                if ($tag !== $target) {
                    $mergeMap[$tag] = $target;
                }
            }
        }

        if (empty($mergeMap)) return $batch;

        Log::info("[DUB] [{$this->title}] Merging rare speakers: " . implode(', ', array_map(fn($from, $to) => "{$from}→{$to}", array_keys($mergeMap), $mergeMap)), [
            'session' => $this->sessionId,
        ]);

        foreach ($batch as &$seg) {
            $tag = $seg['speaker'] ?? 'M1';
            if (isset($mergeMap[$tag])) {
                $seg['speaker'] = $mergeMap[$tag];
            }
        }
        unset($seg);

        return $batch;
    }

    private function buildAnalysisPrompt(array $segments): array
    {
        $lines = [];
        foreach ($segments as $i => $seg) {
            $lines[] = ($i + 1) . '. ' . $seg['text'];
        }

        $sourceLangRules = '';
        if ($this->translateFrom === 'ru') {
            $sourceLangRules = "\n"
                . "RUSSIAN GENDER DETECTION — use these clues to determine speaker gender:\n"
                . "- Past tense verb endings: -л (male: сказал, пошёл, знал), -ла (female: сказала, пошла, знала)\n"
                . "- Short adjective forms: рад/готов/должен (male), рада/готова/должна (female)\n"
                . '- Self-references: "я сам" (male), "я сама" (female)' . "\n"
                . "- Russian names: masculine (Андрей, Сергей, Дмитрий, Алексей), feminine (Мария, Анна, Елена, Наталья)\n"
                . "- Patronymics: -ович/-евич (addressing male), -овна/-евна (addressing female)\n"
                . "- Diminutives: -ка, -очка, -енька often for females; -ик, -чик often for males\n"
                . "\n"
                . "RUSSIAN FORMALITY DETECTION — maps to Uzbek sen/siz:\n"
                . '- "ты" forms (говоришь, идёшь, -ешь/-ишь endings) = informal → the listener is younger or close' . "\n"
                . '- "Вы" forms (говорите, идёте, -ете/-ите endings) = formal → the listener is older or respected' . "\n"
                . '- This tells you the RELATIONSHIP: if speaker uses "ты", they are senior to or close peers with the listener';
        } elseif ($this->translateFrom === 'en') {
            $sourceLangRules = "\n"
                . "ENGLISH GENDER DETECTION — use these clues:\n"
                . '- Pronouns used about the speaker by others: "he/him/his" (male), "she/her" (female)' . "\n"
                . "- Names: gendered names (John=male, Mary=female)\n"
                . '- Terms of address: "sir/mister/Mr." (male), "ma\'am/miss/Mrs./Ms." (female)' . "\n"
                . '- Family roles: "father/son/brother/husband" (male), "mother/daughter/sister/wife" (female)' . "\n"
                . '- Vocal descriptions in stage directions: "he said", "she whispered"';
        }

        $titleHint = ($this->title && $this->title !== 'Untitled')
            ? "\nFILM/SERIES TITLE: \"{$this->title}\"\n"
            . "\nBEFORE analyzing the dialogue, recall everything you know about this film/series:\n"
            . "- Full plot summary and timeline of events\n"
            . "- ALL characters: their names, genders, ages, relationships, personalities\n"
            . "- The emotional arc of the story — what happens in each act\n"
            . "- Key scenes and who appears in them\n"
            . "- How characters speak — formal/informal, their speech patterns, catchphrases\n"
            . "\nNow match this dialogue to the EXACT SCENE in the plot timeline. Based on the dialogue content and order, identify:\n"
            . "- Which scene this is (beginning, middle, climax, etc.)\n"
            . "- Which characters are present in THIS specific scene\n"
            . "- Who says each line based on plot context, character personality, and story logic\n"
            : '';

        $prompt = "You are analyzing a film/series dialogue to identify speakers. This is CRITICAL for voice dubbing — wrong gender = wrong voice actor.\n"
            . $titleHint
            . "\n{$sourceLangRules}\n"
            . "\nTASK: Analyze every line carefully. Determine:\n"
            . "1. How many distinct speakers are in this dialogue\n"
            . "2. Each speaker's GENDER (from grammatical clues, names, context — see rules above)\n"
            . "3. Each speaker's approximate AGE (child, young ~15-25, adult ~25-50, elderly ~50+)\n"
            . "4. Relationships between speakers (parent-child, friends, spouses, boss-employee, etc.)\n"
            . "5. Which lines each speaker says\n"
            . "\nIMPORTANT:\n"
            . '- Do NOT guess gender randomly. If a line has "-ла" ending (Russian), it\'s FEMALE. If "-л" ending, it\'s MALE.' . "\n"
            . "- Look at consecutive lines — dialogues alternate between speakers. If line 1 asks a question and line 2 answers, they are usually different speakers.\n"
            . '- A dash "-" at the start of a line often indicates a different speaker from the previous line.' . "\n"
            . "- If someone is addressed by name, that person is the LISTENER, not the speaker.\n"
            . "\nFormat your response EXACTLY like this:\n"
            . "TITLE: [identified film/series title, or \"Unknown\" if you can't tell]\n"
            . "\nCHARACTERS:\n"
            . "M1: [name/role], [age category], [relationship to others]\n"
            . "F1: [name/role], [age category], [relationship to others]\n"
            . ($this->language === 'uz' ? (
                "\nSEN/SIZ MAP (for Uzbek dubbing — EVERY pair of characters must be listed):\n"
                . "M1→M2: sen (reason: close friends, same age)\n"
                . "M2→M1: sen (reason: close friends, same age)\n"
                . "F1→M1: Siz (reason: younger woman to older man)\n"
                . "M1→F1: sen (reason: older man to younger woman)\n"
                . "[list ALL pairs — this map will be strictly followed in translation]\n"
            ) : '')
            . "\nLINES:\n"
            . "1-3,7,12: M1\n"
            . "4-6,8-9: F1";

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
            'it' => 'Italian', 'pt' => 'Portuguese', 'hi' => 'Hindi', 'fa' => 'Persian',
            'uk' => 'Ukrainian', 'kk' => 'Kazakh', 'ky' => 'Kyrgyz', 'az' => 'Azerbaijani',
        ];
        $toLang = $langNames[$this->language] ?? $this->language;
        $fromLang = $this->translateFrom && $this->translateFrom !== 'auto'
            ? ($langNames[$this->translateFrom] ?? $this->translateFrom)
            : 'auto-detected / mixed';

        $lines = [];
        foreach ($batch as $i => $seg) {
            $duration = round($seg['end'] - $seg['start'], 1);
            $maxChars = (int) round($duration * 12);
            $rawText = $seg['raw_text'] ?? $seg['text'];
            $lines[] = ($i + 1) . '. [' . $duration . 's, max ' . $maxChars . ' chars] ' . $rawText;
        }

        // Trim full dialogue to a window around current batch to avoid token limits
        // For big movies (1000+ lines), sending all lines causes rate limiting
        $dialogueLines = explode("\n", $fullDialogue);
        if (count($dialogueLines) > 100) {
            $globalOffset = $this->segmentOffset + $this->batchIndex * 15;
            $windowStart = max(0, $globalOffset - 20);
            $windowEnd = min(count($dialogueLines), $globalOffset + 35);
            $trimmed = array_slice($dialogueLines, $windowStart, $windowEnd - $windowStart);
            $fullDialogue = "(...earlier dialogue omitted...)\n" . implode("\n", $trimmed) . "\n(...later dialogue omitted...)";
        }

        $uzbekRules = '';
        $fromLangHint = '';
        if ($this->language === 'uz') {
            if ($this->translateFrom === 'ru') {
                $fromLangHint = "\n"
                    . "RUSSIAN→UZBEK MAPPING:\n"
                    . '- Russian "ты" (informal) → Uzbek "sen": speaker is older/senior or they are close friends' . "\n"
                    . '- Russian "Вы" (formal) → Uzbek "Siz": speaker is younger or it\'s a formal setting' . "\n"
                    . '- Keep this consistent: if character A uses "ты" to B in Russian, A must use "sen" to B in Uzbek throughout';
            }

            $uzbekRules = "\n"
                . "UZBEK LANGUAGE RULES (CRITICAL):\n"
                . "- SEN/SIZ — this is the #1 priority, getting it wrong ruins the dub:\n"
                . '  * STRICTLY follow the SEN/SIZ MAP in CHARACTER ANALYSIS — it defines exactly who uses sen and who uses Siz to whom. NEVER deviate from it.' . "\n"
                . '  * If no map is available, look at CHARACTER ANALYSIS for age and relationships' . "\n"
                . '  * Elderly/parent → child/young person: always "sen" (-san, -ding, -yapsanmi)' . "\n"
                . '  * Young person → elderly/parent: always "Siz" (-siz, -dingiz, -yapsizmi)' . "\n"
                . '  * Same-age close friends: "sen"' . "\n"
                . '  * Same-age strangers/formal: "Siz"' . "\n"
                . '  * Child → parent: "Siz" (respectful)' . "\n"
                . '  * Husband ↔ wife: usually "sen" (intimate)' . "\n"
                . '  * Boss → employee: can be "sen"; employee → boss: "Siz"' . "\n"
                . $fromLangHint . "\n"
                . "- SCRIPT — FAQAT lotin alifbosi! Kirill harflar (а,б,в,г,д...) MUTLAQO ishlatilmasin. Agar manba matni Kirillda bo'lsa, lotinga o'giring. Aralash yozuv (masalan: \"taniyман\") QABUL QILINMAYDI.\n"
                . "- SPELLING — O'zbek fe'l qoidasi: fe'l negizi (verb stem) ga shaxs qo'shimchasi qo'shganda, negiz oxiridagi unli HECH QACHON tushib qolmaydi. Masalan: negiz \"tani-\" → \"taniyman\" (to'g'ri), \"tanyman\" (XATO). Bu qoida BARCHA fe'llarga tegishli. Agar negiz unli bilan tugasa, u unli saqlanadi.\n"
                . "- STYLE — spoken Uzbek, like real people talk:\n"
                . '  * Use colloquial forms: "qilyapman" not "qilayotirman", "ketyapman" not "ketayotirman"' . "\n"
                . '  * Use "bor" not "mavjud", "yo\'q" not "mavjud emas"' . "\n"
                . '  * Contractions: "nimaga" not "nima uchun" (when casual)' . "\n"
                . '  * Emotional words: "voy!" (surprise), "ey!" (calling), "qo\'ying!" (stop it!)' . "\n"
                . "- Names and proper nouns: write in Uzbek phonetics — keep the name, but transliterate the spelling:\n"
                . "  * c before e/i/y → s: Barcelona→Barselona, France→Fransiya, concert→konsert\n"
                . "  * c before a/o/u → k: Monaco→Monako, Cuba→Kuba, music→muzik\n"
                . "  * ch stays ch: Chicago→Chikago, Chelsea→Chelsi\n"
                . "  * w → v: Washington→Vashington, Wilson→Vilson\n"
                . "  * ph → f: Philip→Filip, Philadelphia→Filadelfiya\n"
                . "  * th → t: Thomas→Tomas, Thailand→Tailand\n"
                . "  * Uzbek/Arabic/Persian names stay unchanged (Toshkent, Samarqand, Muhammad...)\n"
                . "- Keep emotional register: anger, love, fear, humor must come through";
        }

        $titleHint = ($this->title && $this->title !== 'Untitled')
            ? "\nFILM/SERIES TITLE: \"{$this->title}\"\n"
            . "You KNOW this film. Before writing any dialogue, recall:\n"
            . "- The full plot, story arc, and what happens in this scene\n"
            . "- Each character's personality, speech style, emotional state at this point in the story\n"
            . "- The relationships and tensions between characters in this moment\n"
            . "Write the dialogue as if you've watched this film 10 times and know every character intimately.\n"
            : '';

        $universalRules = "\nSOURCE HANDLING (works for ANY source language):\n"
            . "- Source language is {$fromLang}. If a line is in another language, silently detect that line's language and translate it too.\n"
            . "- If subtitles are romanized, badly OCR'd, mistranscribed, or missing accents, restore the intended spoken sentence from context before translating.\n"
            . "- Do not copy source-language words into {$toLang} unless they are names, brands, places, titles, or intentionally foreign words.\n"
            . "- Preserve meaning, intent, emotion, and who is speaking. Do not summarize. Do not add new facts.\n"
            . "\nTTS-READY OUTPUT:\n"
            . "- Output only words the actor should say. Remove [music], [laughing], captions, sound effects, speaker labels, HTML, hashtags, URLs, and subtitle artifacts.\n"
            . "- Expand numbers, dates, times, currency, units, and abbreviations into normal spoken {$toLang} words when possible.\n"
            . "- No Markdown, no asterisks, no emojis, no stage directions, no timing text, no original-language explanation.\n"
            . "- Keep punctuation simple for TTS: . , ! ? ... and dashes for pauses only.\n"
            . $this->targetLanguageRules($toLang);

        $systemPrompt = "You are a professional film dubbing translator and voice director. First understand the source line, even if it is in an unexpected or mixed language; then write natural spoken {$toLang} that keeps the same meaning, intent, emotion, and timing.\n"
            . "\nYou are not doing literal subtitles. You are producing TTS-ready dubbing dialogue: accurate translation, natural speech, clean pronunciation, correct target script.\n"
            . "\nCRITICAL: Each line has a TIME SLOT [Ns]. The dubbed speech will be synthesized by TTS and must FIT within that exact duration. If the original line is 3 seconds, your {$toLang} version must also be speakable in ~3 seconds. This means:\n"
            . "- Short time slots → be concise, use shorter words\n"
            . "- Long time slots → you have room for natural phrasing\n"
            . "- NEVER write more text than can be spoken in the given time\n"
            . "- NEVER cut meaning to fit — rephrase more concisely instead\n"
            . $titleHint
            . "\nCHARACTER ANALYSIS:\n{$characterContext}\n"
            . "\nSCENE DIALOGUE (for understanding context — do NOT translate this literally):\n{$fullDialogue}\n"
            . $universalRules
            . "{$uzbekRules}\n"
            . "\nRULES:\n"
            . "1. Read the ENTIRE scene dialogue above first. Understand the story, who is talking to whom, what just happened, what is about to happen.\n"
            . "2. For each line: translate the meaning first, then make it natural spoken {$toLang}; never leave untranslated source text behind.\n"
            . "3. Keep names/titles recognizable, but transliterate them to the target language's normal pronunciation when needed for TTS.\n"
            . "4. Keep the character's voice consistent — if someone speaks formally, keep formal. If street slang, use {$toLang} slang.\n"
            . "5. Emotional delivery through punctuation (TTS reads these):\n"
            . "   ! = shouting/emphasis, ... = hesitation/trailing off, — = pause/interruption, ? = question\n"
            . "6. Cultural references: adapt to {$toLang} culture, don't translate literally. A joke must be funny in {$toLang}.\n"
            . "7. Preserve interruptions, hesitations, and conversational flow.\n"
            . "8. Cultural adaptation: if a joke, idiom, or reference won't land in {$toLang}, adapt it to an equivalent that carries the same meaning and humor — don't translate it literally.\n"
            . "\n" . 'Format: "1. translated text {emotion|pace}"' . "\n"
            . "After each line append a delivery hint in curly braces:\n"
            . "- emotion: neutral angry happy sad fearful excited calm whisper\n"
            . "- pace: normal fast slow\n"
            . "Example: \"3. Qo'ying! {angry|fast}\"\n"
            . "Do not include timing info. Do not skip or merge lines. Keep exact numbering.\n"
            . "Return ONLY the numbered translated lines. No analysis, no intro sentence, no explanation.";

        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Translate ONLY these lines:\n\n" . implode("\n", $lines)],
        ];
    }

    private function updateSession(array $data): void
    {
        DubSession::patch($this->sessionId, $data);
    }

    public function failed(\Throwable $exception): void
    {
        $session = DubSession::get($this->sessionId) ?? [];
        $title   = $session['title'] ?? 'Untitled';

        Log::error("[DUB] [{$title}] Batch {$this->batchIndex} failed permanently: " . $exception->getMessage(), [
            'session' => $this->sessionId,
        ]);

        $this->updateSession([
            'status' => 'error',
            'error' => 'Translation failed: ' . Str::limit($exception->getMessage(), 120),
        ]);
    }
}
