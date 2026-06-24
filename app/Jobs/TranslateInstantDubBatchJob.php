<?php

namespace App\Jobs;

use App\Services\AnthropicModelResolver;
use App\Services\LocalTranslationClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Support\DubSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class TranslateInstantDubBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 240;
    public int $tries = 1; // Retries handled internally; chain must not break

    private string $title = 'Untitled';
    private ?string $lastAnthropicFailure = null;

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
        $localTranslator = app(LocalTranslationClient::class);

        if ($localTranslator->enabled()) {
            $characterContext = $localTranslator->chat($analysisPrompt, timeout: 60, maxTokens: 2048) ?? '';
            if ($characterContext) {
                $this->extractAndStoreTitle($characterContext);
                Log::info("[DUB] [{$this->title}] Character analysis (local): " . Str::limit($characterContext, 200), ['session' => $this->sessionId]);
            }

            $translationResult = $localTranslator->chat($translationMessages, timeout: 90, maxTokens: 4096);
            if ($translationResult) {
                Redis::setex(DubSession::characterContextKey($this->sessionId), DubSession::TTL, $characterContext);
                $parsed = $this->tryParseProviderTranslation('local translation', $batch, $translationResult, $providerFailures);
                if ($parsed !== null) {
                    return $parsed;
                }
            }

            if (!$localTranslator->allowPaidFallback()) {
                Redis::setex(DubSession::characterContextKey($this->sessionId), DubSession::TTL, $characterContext);
                $reason = $providerFailures
                    ? ': ' . implode('; ', $providerFailures)
                    : '.';
                throw new \RuntimeException('Local translation returned no usable batch 0 output' . $reason);
            }
        }

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
        $localTranslator = app(LocalTranslationClient::class);
        $providerFailures = [];

        if ($localTranslator->enabled()) {
            $result = $localTranslator->chat($messages, timeout: 90, maxTokens: 4096);
            if ($result !== null) {
                Log::debug("[DUB] [{$this->title}] Batch {$this->batchIndex} local response: " . Str::limit($result, 300), [
                    'session' => $this->sessionId,
                ]);
                $parsed = $this->tryParseProviderTranslation('local translation', $batch, $result, $providerFailures);
                if ($parsed !== null) {
                    return $parsed;
                }
            }

            if (!$localTranslator->allowPaidFallback()) {
                $reason = $providerFailures
                    ? ': ' . implode('; ', $providerFailures)
                    : '.';
                throw new \RuntimeException("Local translation returned no usable output for batch {$this->batchIndex}{$reason}");
            }
        }

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

    private function callAnthropic(array $messages): ?string
    {
        $apiKey = config('services.anthropic.key');
        if (!$apiKey) {
            $this->lastAnthropicFailure = 'Claude not configured';
            return null;
        }

        // Convert OpenAI-style messages to Anthropic format
        $system = '';
        $anthropicMessages = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system = $msg['content'];
            } else {
                $anthropicMessages[] = $msg;
            }
        }

        $failures = [];
        foreach (AnthropicModelResolver::models() as $model) {
            try {
                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 4096,
                    'system' => $system,
                    'messages' => $anthropicMessages,
                ]);

                if ($response->successful()) {
                    $content = trim($response->json('content.0.text') ?? '');
                    if ($content !== '') {
                        return $content;
                    }

                    $failures[] = "Claude {$model} returned empty response";
                    continue;
                }

                $failure = 'Claude ' . $model . ' HTTP ' . $response->status() . ': ' . Str::limit($response->body(), 180);
                $failures[] = $failure;
                Log::warning("[DUB] Anthropic API error (batch {$this->batchIndex}, {$model}): HTTP " . $response->status(), [
                    'session' => $this->sessionId,
                    'body' => Str::limit($response->body(), 200),
                ]);

                if (!in_array($response->status(), [400, 404, 429, 529], true)) {
                    break;
                }
            } catch (\Throwable $e) {
                $failures[] = 'Claude ' . $model . ' exception: ' . Str::limit($e->getMessage(), 180);
                Log::warning("[DUB] Anthropic API exception (batch {$this->batchIndex}, {$model}): " . $e->getMessage(), [
                    'session' => $this->sessionId,
                ]);
            }
        }

        $this->lastAnthropicFailure = implode('; ', array_unique($failures)) ?: 'Claude returned no response';
        return null;
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

    private function tryParseProviderTranslation(string $provider, array $batch, string $content, array &$providerFailures): ?array
    {
        try {
            return $this->parseTranslationResponse($batch, $content);
        } catch (\Throwable $e) {
            $providerFailures[] = "{$provider}: " . $e->getMessage();
            Log::warning("[DUB] [{$this->title}] Batch {$this->batchIndex} {$provider} output rejected: " . $e->getMessage(), [
                'session' => $this->sessionId,
                'sample' => Str::limit($content, 500),
            ]);

            return null;
        }
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

    private function mergeVoiceMap(array $newSpeakers): void
    {
        $voiceKey = DubSession::voicesKey($this->sessionId);
        $lockKey  = DubSession::voicesLockKey($this->sessionId);
        $driver = 'edge';
        $variants = \App\Services\VoiceMapBuilder::variantsForDriver($driver, $this->language);

        $lock = Cache::lock($lockKey, 5);
        $voiceMap = [];
        $lock->block(5, function () use ($voiceKey, $newSpeakers, $variants, &$voiceMap) {
            $voiceMap = json_decode(Redis::get($voiceKey) ?? '{}', true) ?: [];
            $voiceMap = array_filter($voiceMap, fn ($entry) => !is_array($entry) || empty($entry['driver']) || $entry['driver'] === 'edge');
            $voiceMap = \App\Services\VoiceMapBuilder::assignSpeakers($voiceMap, $newSpeakers, $variants);
            Redis::setex($voiceKey, DubSession::TTL, json_encode($voiceMap));
        });

        Log::info("[DUB] [{$this->title}] Voice map merged (batch {$this->batchIndex}): new=" . implode(',', array_keys($newSpeakers)) . " total=" . implode(',', array_keys($voiceMap)), [
            'session' => $this->sessionId,
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

    private function parseTranslationResponse(array $batch, string $content): array
    {
        $translated = trim($content);
        $parsedCount = 0;
        $parsedIndexes = [];
        $sourceTexts = [];
        foreach ($batch as $idx => $seg) {
            $sourceTexts[$idx] = (string) ($seg['raw_text'] ?? ($seg['source_text'] ?? ($seg['text'] ?? '')));
        }

        foreach (preg_split('/\n+/', $translated) as $line) {
            $parsedLine = $this->parseNumberedTranslationLine($line);
            if ($parsedLine !== null) {
                [$number, $text] = $parsedLine;
                $idx = $this->resolveTranslationLineIndex($number, $batch);
                if ($idx !== null && isset($batch[$idx])) {
                    $batch[$idx]['speaker'] = 'M1';
                    $batch[$idx]['text']    = $this->sanitizeForTts(
                        $this->extractDelivery($text, $batch[$idx])
                    );
                    $parsedCount++;
                    $parsedIndexes[$idx] = true;
                }
            }
        }

        if ($parsedCount === 0) {
            throw new \RuntimeException('Translation response did not contain numbered lines.');
        }

        $missing = [];
        foreach ($batch as $idx => &$seg) {
            if (!isset($parsedIndexes[$idx])) {
                $missing[] = $idx + 1;
            }
        }
        unset($seg);

        $empty = [];
        foreach ($batch as $idx => $seg) {
            if (trim((string) ($seg['text'] ?? '')) === '') {
                $empty[] = $idx + 1;
            }
        }

        $this->fillUnusableTranslationLines($batch, $missing, $empty);

        // Post-process: replace any stray Cyrillic characters with Latin equivalents
        if ($this->language === 'uz') {
            $cyrToLat = [
                'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
                'ё' => 'yo', 'ж' => 'j', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
                'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
                'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ц' => 'ts',
                'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh', 'ъ' => '', 'ы' => 'i', 'ь' => '',
                'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
                'ў' => 'o\'', 'қ' => 'q', 'ғ' => 'g\'', 'ҳ' => 'h',
                'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E',
                'Ё' => 'Yo', 'Ж' => 'J', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K',
                'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R',
                'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F', 'Х' => 'X', 'Ц' => 'Ts',
                'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sh', 'Ъ' => '', 'Ы' => 'I', 'Ь' => '',
                'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
                'Ў' => 'O\'', 'Қ' => 'Q', 'Ғ' => 'G\'', 'Ҳ' => 'H',
            ];
            foreach ($batch as &$seg) {
                if (isset($seg['text']) && preg_match('/[а-яА-ЯёЁўқғҳЎҚҒҲ]/u', $seg['text'])) {
                    $seg['text'] = strtr($seg['text'], $cyrToLat);
                }
            }
            unset($seg);
        }

        $this->rejectBadTranslationOutput($batch, $sourceTexts);

        return $batch;
    }

    private function fillUnusableTranslationLines(array &$batch, array $missing, array $empty): void
    {
        $lineNumbers = array_values(array_unique(array_merge($missing, $empty)));
        if (empty($lineNumbers)) {
            return;
        }

        $maxSilentLines = max(1, (int) floor(count($batch) * 0.2));
        if (count($lineNumbers) > $maxSilentLines) {
            throw new \RuntimeException('Translation response skipped or emptied too many line(s): ' . implode(', ', $lineNumbers));
        }

        foreach ($lineNumbers as $lineNumber) {
            $idx = $lineNumber - 1;
            if (!isset($batch[$idx])) {
                continue;
            }

            $batch[$idx]['speaker'] = $batch[$idx]['speaker'] ?? 'M1';
            $batch[$idx]['text'] = '...';
            $batch[$idx]['delivery'] = 'neutral|normal';
            $batch[$idx]['translation_missing'] = true;
        }

        Log::warning("[DUB] [{$this->title}] Batch {$this->batchIndex} using silent placeholder for translation line(s): " . implode(', ', $lineNumbers), [
            'session' => $this->sessionId,
        ]);
    }

    private function parseNumberedTranslationLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '```')) {
            return null;
        }

        if (!preg_match('/^\s*(?:[-*•]\s*)?(?:\*\*)?(?:\[(\d+)\]|(\d+))(?:\*\*)?\s*(?:[.)\:：]|[-–—])?\s*(?:\*\*)?\s*(?:\[[MFC]\d+\]\s*)?(.+?)\s*$/u', $line, $m)) {
            return null;
        }

        $number = $m[1] !== '' ? $m[1] : $m[2];

        return [(int) $number, trim($m[3])];
    }

    private function resolveTranslationLineIndex(int $number, array $batch): ?int
    {
        $localIdx = $number - 1;
        if (array_key_exists($localIdx, $batch)) {
            return $localIdx;
        }

        foreach ($batch as $idx => $seg) {
            if (!isset($seg['index'])) {
                continue;
            }

            $segmentIndex = (int) $seg['index'];
            if ($number === $segmentIndex || $number === $segmentIndex + 1) {
                return $idx;
            }
        }

        $globalStart = $this->segmentOffset + ($this->batchIndex * 15);
        $globalIdx = $number - $globalStart - 1;

        return array_key_exists($globalIdx, $batch) ? $globalIdx : null;
    }

    private function rejectBadTranslationOutput(array $batch, array $sourceTexts): void
    {
        $explicitDifferentSource = $this->translateFrom && $this->translateFrom !== 'auto' && $this->translateFrom !== $this->language;
        $autoSource = !$this->translateFrom || $this->translateFrom === 'auto';
        if (!$explicitDifferentSource && !$autoSource) {
            return;
        }

        $copied = [];
        foreach ($batch as $idx => $seg) {
            $target = (string) ($seg['text'] ?? '');
            if (
                $this->looksLikeCopiedSource($sourceTexts[$idx] ?? '', $target)
                && ($explicitDifferentSource || $this->looksWrongLanguageForTarget($target))
            ) {
                $copied[] = $idx + 1;
            }
        }

        if (!empty($copied)) {
            throw new \RuntimeException('Translation response copied source text for line(s): ' . implode(', ', $copied));
        }
    }

    private function looksLikeCopiedSource(string $source, string $target): bool
    {
        $source = $this->normalizeForTranslationCompare($source);
        $target = $this->normalizeForTranslationCompare($target);
        $minLen = min(strlen($source), strlen($target));

        if ($minLen < 10) {
            return false;
        }

        if ($source === $target) {
            return true;
        }

        similar_text($source, $target, $similarity);

        return $similarity >= 88.0;
    }

    private function looksWrongLanguageForTarget(string $text): bool
    {
        if ($this->language !== 'uz') {
            return false;
        }

        $text = mb_strtolower($text, 'UTF-8');

        return (bool) preg_match('/\b(the|and|you|your|we|need|leave|right|now|what|where|when|why|how|hello|world|can|will|have|this|that|with|from|they|them|don\'t|doesn\'t|is|are)\b/u', $text)
            || (bool) preg_match('/\b(privet|spasibo|pozhaluysta|kak|dela|net|da|horosho|pochemu|chto|gde)\b/u', $text);
    }

    private function normalizeForTranslationCompare(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\{[^}]*}/u', ' ', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $text);

        return (string) $text;
    }

    private function targetLanguageRules(string $toLang): string
    {
        return match ($this->language) {
            'uz' => "\nTARGET LANGUAGE RULES — Uzbek Latin:\n"
                . "- Output must be ONLY Uzbek Latin. Never use Cyrillic. No mixed script.\n"
                . "- Use natural spoken Uzbek: qilyapman, ketyapman, bor, yo'q.\n"
                . "- Preserve verb-stem vowels: taniyman, taniysan, taniydi; never tanyman.\n"
                . "- Expand numbers/dates into spoken Uzbek words.\n"
                . "- Transliterate foreign names for Uzbek TTS: c→s/k, w→v, ph→f, th→t.\n",
            'ru' => "\nTARGET LANGUAGE RULES — Russian:\n"
                . "- Output must be natural spoken Russian in Cyrillic. Do not use Latin transliteration except unavoidable brand names.\n"
                . "- Match ты/Вы formality from the scene context.\n",
            'ar' => "\nTARGET LANGUAGE RULES — Arabic:\n"
                . "- Output natural spoken Arabic in Arabic script. Prefer clear MSA/neutral conversational phrasing suitable for TTS.\n",
            'zh' => "\nTARGET LANGUAGE RULES — Chinese:\n"
                . "- Output natural spoken Chinese using Chinese characters. Avoid pinyin except for foreign names that have no common Chinese form.\n",
            'ja' => "\nTARGET LANGUAGE RULES — Japanese:\n"
                . "- Output natural spoken Japanese using Japanese script. Use kana/kanji normally; avoid romaji.\n",
            'ko' => "\nTARGET LANGUAGE RULES — Korean:\n"
                . "- Output natural spoken Korean using Hangul. Avoid romanization.\n",
            default => "\nTARGET LANGUAGE RULES — {$toLang}:\n"
                . "- Output natural spoken {$toLang} in its normal script. Use romanization only if that is standard for the target language.\n",
        };
    }

    private function sanitizeForTts(string $text): string
    {
        $text = $this->scrubUtf8($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s*\{(?:emotion:)?[a-z]+\|(?:pace:)?[a-z]+\}\s*$/i', '', $text);
        $text = preg_replace('/^\s*(?:[-–—]\s*)?(?:[A-ZА-ЯЁЎҚҒҲ][\p{L}\p{N}_ -]{0,24}:)\s*/u', '', $text);
        $text = preg_replace('/\[[^\]]*]/u', '', $text);
        $text = preg_replace('/\((?:music|laughs?|laughing|sighs?|gasps?|coughs?|applause|door|phone|noise|silence|whispering|speaking|inaudible)[^)]*\)/iu', '', $text);
        $text = preg_replace('/[♪♫]+/u', '', $text);
        $text = preg_replace('/\*([^*]+)\*/u', '$1', $text);
        $text = preg_replace('/[`_#~<>]+/u', '', $text);
        $text = preg_replace('/https?:\/\/\S+/iu', '', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text, " \t\n\r\0\x0B\"“”«»");
    }

    private function scrubUtf8(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_scrub')) {
            return mb_scrub($text, 'UTF-8');
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);

        return $clean === false ? '' : $clean;
    }

    /** Extract {emotion|pace} delivery hint from end of translated text. Mutates $seg. */
    private function extractDelivery(string $text, array &$seg): string
    {
        $text = trim($text);
        // Matches both {calm|slow} and {emotion:calm|pace:slow}
        if (preg_match('/\{(?:emotion:)?([a-z]+)\|(?:pace:)?([a-z]+)\}\s*$/i', $text, $m)) {
            $seg['delivery'] = strtolower($m[1]) . '|' . strtolower($m[2]);
            $text = trim(substr($text, 0, -strlen($m[0])));
        }
        return $text;
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
