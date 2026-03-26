<?php

namespace App\Jobs;

use App\Services\ElevenLabs\ElevenLabsClient;
use App\Services\ElevenLabs\SpeakerSampleExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function __construct(
        public string $sessionId,
        public int    $batchIndex,
        public int    $totalBatches,
        public string $language,
        public string $translateFrom,
        public int    $segmentOffset = 0,
    ) {}

    public function handle(): void
    {
        // Check if session was stopped
        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;
        $session = json_decode($sessionJson, true);
        $this->title = $session['title'] ?? 'Untitled';

        if (($session['status'] ?? '') === 'stopped') {
            Log::info("[DUB] [{$this->title}] Batch {$this->batchIndex} translation stopped", ['session' => $this->sessionId]);
            return;
        }

        // Load full dialogue from Redis (stored once by PrepareInstantDubJob)
        $fullDialogueText = Redis::get("instant-dub:{$this->sessionId}:full-dialogue") ?? '';

        // Load batch from Redis
        $batchKey = "instant-dub:{$this->sessionId}:batch:{$this->batchIndex}";
        $batchJson = Redis::get($batchKey);
        if (!$batchJson) {
            Log::error("[DUB] [{$this->title}] Batch {$this->batchIndex} data missing from Redis", ['session' => $this->sessionId]);
            return;
        }
        $batch = json_decode($batchJson, true);

        $batchNum = $this->batchIndex + 1;
        $this->updateSession(['status' => 'Translating...', 'progress' => "Translating ({$batchNum}/{$this->totalBatches})..."]);

        try {
            // Translate
            if ($this->batchIndex === 0) {
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

            // Clone voices with ElevenLabs on batch 0 (first time speakers are identified)
            if ($this->batchIndex === 0 && config('dubber.tts.default') === 'elevenlabs') {
                $this->cloneVoicesWithElevenLabs($batch);
            }
        } catch (\Throwable $e) {
            // Translation failed — dispatch segments with original (untranslated) text
            // This is better than no audio at all
            Log::error("[DUB] [{$this->title}] Batch {$this->batchIndex} translation failed, using original text: " . Str::limit($e->getMessage(), 100), [
                'session' => $this->sessionId,
            ]);
            $this->updateSession(['last_warning' => "Batch {$batchNum} translation failed, using original text"]);
        }

        // Dispatch TTS for this batch's segments — ALWAYS runs, even if translation failed
        $this->updateSession(['progress' => "Generating audio ({$batchNum}/{$this->totalBatches})..."]);
        $globalOffset = $this->segmentOffset + $this->batchIndex * 15;

        // Peek at next batch's first segment to get slotEnd for this batch's last segment
        $nextBatchFirstStart = null;
        $nextBatchIdx = $this->batchIndex + 1;
        if ($nextBatchIdx < $this->totalBatches) {
            $nextBatchJson = Redis::get("instant-dub:{$this->sessionId}:batch:{$nextBatchIdx}");
            if ($nextBatchJson) {
                $nextBatch = json_decode($nextBatchJson, true);
                $nextBatchFirstStart = (float) ($nextBatch[0]['start'] ?? 0);
            }
        }

        foreach ($batch as $localIdx => $seg) {
            $text = trim($seg['text']);
            $text = trim(preg_replace('/\[[^\]]*\]\s*/', '', $text));
            $text = str_replace('`', '\'', $text);
            if ($text === '') continue;

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
            )->onQueue('segment-generation');
        }

        // Clean up batch key
        Redis::del($batchKey);

        // Chain next batch — ALWAYS chains, even if this batch had errors
        $nextBatch = $this->batchIndex + 1;
        if ($nextBatch < $this->totalBatches) {
            self::dispatch(
                $this->sessionId,
                $nextBatch,
                $this->totalBatches,
                $this->language,
                $this->translateFrom,
                $this->segmentOffset,
            )->onQueue('default');
        } else {
            // Last batch — clean up
            Redis::del(
                "instant-dub:{$this->sessionId}:full-dialogue",
                "instant-dub:{$this->sessionId}:all-segments",
            );
            Log::info("[DUB] [{$this->title}] All {$this->totalBatches} translation batches complete", [
                'session' => $this->sessionId,
            ]);
        }
    }

    private function translateBatchZero(array $batch, string $fullDialogueText): array
    {
        // Load all segments for character analysis
        $allSegmentsJson = Redis::get("instant-dub:{$this->sessionId}:all-segments");
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

        if ($anthropicKey) {
            // Fire both requests to Claude in parallel
            $responses = Http::pool(function ($pool) use ($anthropicKey, $analysisSystem, $analysisUserMessages, $translationSystem, $translationUserMessages) {
                $pool->as('analysis')
                    ->withHeaders([
                        'x-api-key' => $anthropicKey,
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ])
                    ->timeout(60)
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => 'claude-sonnet-4-6',
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
                        'model' => 'claude-sonnet-4-6',
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
        Redis::setex("instant-dub:{$this->sessionId}:character-context", 50400, $characterContext);

        // Use parallel translation result if available
        if ($translationResult) {
            return $this->parseTranslationResponse($batch, $translationResult);
        }

        // Fallback: GPT-4o for translation
        return $this->callOpenAiWithRetry($batch, $translationMessages);
    }

    private function translateBatchWithContext(array $batch, string $fullDialogueText): array
    {
        $characterContext = Redis::get("instant-dub:{$this->sessionId}:character-context") ?? '';
        $messages = $this->buildTranslationMessages($batch, $characterContext, $fullDialogueText);

        // Try Claude Sonnet first
        $result = $this->callAnthropic($messages);
        if ($result !== null) {
            Log::debug("[DUB] [{$this->title}] Batch {$this->batchIndex} Claude response: " . Str::limit($result, 300), [
                'session' => $this->sessionId,
            ]);
            return $this->parseTranslationResponse($batch, $result);
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
        if (!$apiKey) return null;

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

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => 4096,
                'system' => $system,
                'messages' => $anthropicMessages,
            ]);

            if ($response->successful()) {
                return trim($response->json('content.0.text') ?? '');
            }

            Log::warning("[DUB] Anthropic API error (batch {$this->batchIndex}): HTTP " . $response->status(), [
                'session' => $this->sessionId,
                'body' => Str::limit($response->body(), 200),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[DUB] Anthropic API exception (batch {$this->batchIndex}): " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
        }

        return null;
    }

    private function callOpenAiWithRetry(array $batch, array $messages): array
    {
        $apiKey = config('services.openai.key');
        if (!$apiKey) return $batch;

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

        return $batch;
    }

    private function cloneVoicesWithElevenLabs(array $batch): void
    {
        $apiKey = config('services.elevenlabs.api_key', '');
        if ($apiKey === '') return;

        // Get original audio path from session
        $sessionJson = Redis::get("instant-dub:{$this->sessionId}");
        if (!$sessionJson) return;
        $session = json_decode($sessionJson, true);
        $originalAudioPath = $session['original_audio_path'] ?? null;
        if (!$originalAudioPath || !file_exists($originalAudioPath)) return;

        // Load all segments for sample extraction (need original timing + speaker tags)
        $allSegmentsJson = Redis::get("instant-dub:{$this->sessionId}:all-segments");
        $allSegments = $allSegmentsJson ? json_decode($allSegmentsJson, true) : [];
        if (empty($allSegments)) return;

        // Map speaker tags from translated batch onto original segments by index
        $speakerMap = [];
        foreach ($batch as $seg) {
            $tag = $seg['speaker'] ?? 'M1';
            $speakerMap[$tag] = true;
        }

        // Assign speaker tags to all segments for extraction
        // Segments from batch have speakers identified by GPT/Claude
        $segmentsWithSpeakers = [];
        foreach ($allSegments as $seg) {
            $seg['speaker'] = $seg['speaker'] ?? 'M1';
            $segmentsWithSpeakers[] = $seg;
        }

        try {
            $extractor = new SpeakerSampleExtractor();
            $samples = $extractor->extractSamples($originalAudioPath, $segmentsWithSpeakers);

            if (empty($samples)) return;

            $client = new ElevenLabsClient($apiKey);
            $voiceKey = "instant-dub:{$this->sessionId}:voices";
            $voiceMap = json_decode(Redis::get($voiceKey), true) ?? [];
            $clonedVoiceIds = [];

            foreach ($samples as $tag => $samplePath) {
                try {
                    $voiceId = $client->addVoice("dub-{$this->sessionId}-{$tag}", [$samplePath]);
                    $voiceMap[$tag] = ['driver' => 'elevenlabs', 'voice_id' => $voiceId];
                    $clonedVoiceIds[] = $voiceId;
                    Log::info("[DUB] [{$this->title}] ElevenLabs voice cloned: {$tag} → {$voiceId}", [
                        'session' => $this->sessionId,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("[DUB] [{$this->title}] ElevenLabs clone failed for {$tag}, keeping fallback", [
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
            Log::warning("[DUB] [{$this->title}] ElevenLabs voice cloning failed entirely", [
                'session' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractAndStoreTitle(string $analysisText): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        $session = $sessionJson ? json_decode($sessionJson, true) : [];
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

        if ($changed && $sessionJson) {
            Redis::setex($sessionKey, 50400, json_encode($session));
        }
    }

    private function mergeVoiceMap(array $newSpeakers): void
    {
        $voiceKey = "instant-dub:{$this->sessionId}:voices";
        $lockKey = "instant-dub:{$this->sessionId}:voices-lock";
        $driver = config('dubber.tts.default', 'edge');

        if ($driver === 'aisha') {
            $variants = \App\Services\VoiceVariants::forAisha();
        } else {
            $variants = \App\Services\VoiceVariants::forLanguage($this->language);
        }
        $maleVariants = $variants['male'];
        $femaleVariants = $variants['female'];
        $childVariants = $variants['child'];

        // Use Redis lock to prevent race condition with micro-batch's mergeVoiceMap
        $lock = Cache::lock($lockKey, 5);
        $voiceMap = [];
        $lock->block(5, function () use ($voiceKey, $newSpeakers, $maleVariants, $femaleVariants, $childVariants, &$voiceMap) {
            $existingJson = Redis::get($voiceKey);
            $voiceMap = $existingJson ? json_decode($existingJson, true) : [];

            // Count already-assigned variants per gender
            $maleIdx = 0;
            $femaleIdx = 0;
            $childIdx = 0;
            foreach ($voiceMap as $tag => $voice) {
                if (str_starts_with($tag, 'C')) $childIdx++;
                elseif (str_starts_with($tag, 'M')) $maleIdx++;
                else $femaleIdx++;
            }

            // Only assign new speakers
            foreach (array_keys($newSpeakers) as $tag) {
                if (isset($voiceMap[$tag])) continue;

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

            Redis::setex($voiceKey, 50400, json_encode($voiceMap));
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
        ];
        $toLang = $langNames[$this->language] ?? $this->language;

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
                . "- Names and proper nouns: keep original, don't translate\n"
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

        $systemPrompt = "You are a professional dubbing voice director. You watch a film scene, deeply understand what is happening — the story, relationships, emotions, subtext — and then write the dialogue in {$toLang} exactly as actors would say it if this film was ORIGINALLY MADE in {$toLang}.\n"
            . "\nYou are NOT a translator. You are a WRITER who re-creates dialogue. The difference:\n"
            . "- Translator: converts words from one language to another (sounds foreign, unnatural)\n"
            . "- You: watch the scene, understand what the character MEANS and FEELS, then write what a {$toLang}-speaking person would ACTUALLY SAY in that moment\n"
            . "\nCRITICAL: Each line has a TIME SLOT [Ns]. The dubbed speech will be synthesized by TTS and must FIT within that exact duration. If the original line is 3 seconds, your {$toLang} version must also be speakable in ~3 seconds. This means:\n"
            . "- Short time slots → be concise, use shorter words\n"
            . "- Long time slots → you have room for natural phrasing\n"
            . "- NEVER write more text than can be spoken in the given time\n"
            . "- NEVER cut meaning to fit — rephrase more concisely instead\n"
            . $titleHint
            . "\nCHARACTER ANALYSIS:\n{$characterContext}\n"
            . "\nSCENE DIALOGUE (for understanding context — do NOT translate this literally):\n{$fullDialogue}\n"
            . "{$uzbekRules}\n"
            . "\nRULES:\n"
            . "1. Read the ENTIRE scene dialogue above first. Understand the story, who is talking to whom, what just happened, what is about to happen.\n"
            . "2. For each line: think about WHY the character says this. What do they want? How do they feel? Then write what a {$toLang} speaker would say in that exact emotional state.\n"
            . "3. Lines with annotations like [music], [laughing], [door opens] — use these to understand the mood but translate only the spoken words.\n"
            . "4. Keep the character's voice consistent — if someone speaks formally, keep formal. If street slang, use {$toLang} slang.\n"
            . "5. Emotional delivery through punctuation (TTS reads these):\n"
            . "   ! = shouting/emphasis, ... = hesitation/trailing off, — = pause/interruption, ? = question\n"
            . "6. Use the character analysis to assign speaker tags [M1], [F1], etc.\n"
            . "7. Cultural references: adapt to {$toLang} culture, don't translate literally. A joke must be funny in {$toLang}.\n"
            . "6. Preserve interruptions, hesitations, and conversational flow.\n"
            . "7. Cultural adaptation: if a joke, idiom, or reference won't land in {$toLang}, adapt it to an equivalent that carries the same meaning and humor — don't translate it literally.\n"
            . "\n" . 'Format: "1. [M1] translated text"' . "\n"
            . "Do not include timing info. Do not skip or merge lines. Keep exact numbering.";

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

        // Verify translation actually happened — if target is Uzbek (Latin),
        // check for Cyrillic characters which means text wasn't translated
        if ($this->language === 'uz') {
            $untranslated = 0;
            foreach ($batch as $seg) {
                if (preg_match('/[а-яА-ЯёЁ]{3,}/', $seg['text'] ?? '')) {
                    $untranslated++;
                }
            }
            if ($untranslated > count($batch) * 0.3) {
                Log::warning("[DUB] [{$this->title}] Batch {$this->batchIndex}: {$untranslated}/" . count($batch) . " segments still in Cyrillic — translation failed, retrying", [
                    'session' => $this->sessionId,
                ]);
                throw new \RuntimeException("Translation returned untranslated Cyrillic text ({$untranslated}/" . count($batch) . " segments)");
            }
        }

        return $batch;
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
        $title = 'Untitled';
        $sessionJson = Redis::get("instant-dub:{$this->sessionId}");
        $session = $sessionJson ? json_decode($sessionJson, true) : [];
        if ($session) {
            $title = $session['title'] ?? 'Untitled';
        }

        Log::error("[DUB] [{$title}] Batch {$this->batchIndex} failed permanently: " . $exception->getMessage(), [
            'session' => $this->sessionId,
        ]);

        // Generate background-only segments for failed batch so there are no gaps
        $batchKey = "instant-dub:{$this->sessionId}:batch:{$this->batchIndex}";
        $batchJson = Redis::get($batchKey);
        if ($batchJson) {
            $batch = json_decode($batchJson, true);
            $total = (int) ($session['total_segments'] ?? 0);

            foreach ($batch as $i => $seg) {
                $globalIdx = $this->segmentOffset + $i;
                $startTime = (float) ($seg['start'] ?? 0);
                $endTime = (float) ($seg['end'] ?? 0);

                // Compute slot end (next segment's start)
                $slotEnd = null;
                if (isset($batch[$i + 1])) {
                    $slotEnd = (float) $batch[$i + 1]['start'];
                } elseif ($globalIdx + 1 < $total) {
                    $nextBatchKey = "instant-dub:{$this->sessionId}:batch:" . ($this->batchIndex + 1);
                    $nextBatchJson = Redis::get($nextBatchKey);
                    if ($nextBatchJson) {
                        $nextBatch = json_decode($nextBatchJson, true);
                        $slotEnd = (float) ($nextBatch[0]['start'] ?? $endTime);
                    }
                }

                // Dispatch with empty text — ProcessInstantDubSegmentJob will
                // generate background-only audio when TTS produces nothing
                ProcessInstantDubSegmentJob::dispatch(
                    $this->sessionId,
                    $globalIdx,
                    '', // empty text = background-only
                    $startTime,
                    $endTime,
                    $this->language,
                    'M1',
                    $slotEnd,
                )->onQueue('segment-generation');
            }

            Log::info("[DUB] [{$title}] Dispatched " . count($batch) . " background-only segments for failed batch {$this->batchIndex}", [
                'session' => $this->sessionId,
            ]);
        }

        // Don't set status to 'error' — let remaining batches continue
        $this->updateSession([
            'last_warning' => "Batch {$this->batchIndex} failed: " . Str::limit($exception->getMessage(), 80),
        ]);

        // Chain next batch even on permanent failure — other batches may succeed
        $nextBatch = $this->batchIndex + 1;
        if ($nextBatch < $this->totalBatches) {
            self::dispatch(
                $this->sessionId,
                $nextBatch,
                $this->totalBatches,
                $this->language,
                $this->translateFrom,
                $this->segmentOffset,
            )->onQueue('default');
        } else {
            Redis::del(
                "instant-dub:{$this->sessionId}:full-dialogue",
                "instant-dub:{$this->sessionId}:all-segments",
            );
        }
    }
}
