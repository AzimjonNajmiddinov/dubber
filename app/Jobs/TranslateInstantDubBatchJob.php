<?php

namespace App\Jobs;

use App\Services\ElevenLabs\ElevenLabsClient;
use App\Services\ElevenLabs\SpeakerSampleExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $globalOffset = $this->batchIndex * 15;

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

        $analysisPrompt = $this->buildAnalysisPrompt($allSegments);
        $translationMessages = $this->buildTranslationMessages($batch, '', $fullDialogueText);

        $characterContext = '';

        // Try Claude Sonnet first (parallel: analysis + translation)
        $anthropicKey = config('services.anthropic.key');
        if ($anthropicKey) {
            $results = $this->callAnthropicParallel($analysisPrompt, $translationMessages, $batch);

            if ($results['analysis'] !== null) {
                $characterContext = $results['analysis'];
                Log::info("[DUB] [{$this->title}] Character analysis (Claude): " . Str::limit($characterContext, 200), ['session' => $this->sessionId]);
            }

            if ($results['translation'] !== null) {
                $batch = $this->parseTranslationResponse($batch, $results['translation']);
                // Store character context for subsequent batches
                Redis::setex("instant-dub:{$this->sessionId}:character-context", 50400, $characterContext);
                return $batch;
            }

            Log::warning("[DUB] [{$this->title}] Claude failed for batch 0, falling back to GPT-4o", ['session' => $this->sessionId]);
        }

        // Fallback: GPT-4o parallel
        $openaiKey = config('services.openai.key');
        if ($openaiKey) {
            $pool = Http::pool(function ($pool) use ($openaiKey, $analysisPrompt, $translationMessages) {
                $pool->as('analysis')->withToken($openaiKey)->timeout(45)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o',
                        'temperature' => 0.1,
                        'messages' => $analysisPrompt,
                    ]);
                $pool->as('translation')->withToken($openaiKey)->timeout(60)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o',
                        'temperature' => 0.3,
                        'messages' => $translationMessages,
                    ]);
            });

            if (isset($pool['analysis']) && $pool['analysis'] instanceof \Illuminate\Http\Client\Response && $pool['analysis']->successful()) {
                $characterContext = trim($pool['analysis']->json('choices.0.message.content') ?? '');
                Log::info("[DUB] [{$this->title}] Character analysis (GPT): " . Str::limit($characterContext, 200), ['session' => $this->sessionId]);
            }

            if (isset($pool['translation']) && $pool['translation'] instanceof \Illuminate\Http\Client\Response && $pool['translation']->successful()) {
                $batch = $this->parseTranslationResponse($batch, $pool['translation']->json('choices.0.message.content') ?? '');
            } else {
                Log::warning("[DUB] [{$this->title}] Batch 0 GPT translation also failed", ['session' => $this->sessionId]);
            }
        }

        // Store character context for subsequent batches
        Redis::setex("instant-dub:{$this->sessionId}:character-context", 50400, $characterContext);
        return $batch;
    }

    private function translateBatchWithContext(array $batch, string $fullDialogueText): array
    {
        $characterContext = Redis::get("instant-dub:{$this->sessionId}:character-context") ?? '';
        $messages = $this->buildTranslationMessages($batch, $characterContext, $fullDialogueText);

        // Try Claude Sonnet first
        $result = $this->callAnthropic($messages);
        if ($result !== null) {
            return $this->parseTranslationResponse($batch, $result);
        }

        // Fallback: GPT-4o with retry
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
                'model' => 'claude-sonnet-4-6-latest',
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

    private function callAnthropicParallel(array $analysisPrompt, array $translationMessages, array $batch): array
    {
        $apiKey = config('services.anthropic.key');
        $results = ['analysis' => null, 'translation' => null];
        if (!$apiKey) return $results;

        // Extract system prompts
        $analysisSystem = '';
        $analysisUserMessages = [];
        foreach ($analysisPrompt as $msg) {
            if ($msg['role'] === 'system') $analysisSystem = $msg['content'];
            else $analysisUserMessages[] = $msg;
        }

        $translationSystem = '';
        $translationUserMessages = [];
        foreach ($translationMessages as $msg) {
            if ($msg['role'] === 'system') $translationSystem = $msg['content'];
            else $translationUserMessages[] = $msg;
        }

        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ];

        try {
            $pool = Http::pool(function ($pool) use ($headers, $analysisSystem, $analysisUserMessages, $translationSystem, $translationUserMessages) {
                $pool->as('analysis')
                    ->withHeaders($headers)
                    ->timeout(60)
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => 'claude-sonnet-4-6-latest',
                        'max_tokens' => 4096,
                        'system' => $analysisSystem,
                        'messages' => $analysisUserMessages,
                    ]);
                $pool->as('translation')
                    ->withHeaders($headers)
                    ->timeout(90)
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => 'claude-sonnet-4-6-latest',
                        'max_tokens' => 4096,
                        'system' => $translationSystem,
                        'messages' => $translationUserMessages,
                    ]);
            });

            if (isset($pool['analysis']) && $pool['analysis'] instanceof \Illuminate\Http\Client\Response && $pool['analysis']->successful()) {
                $results['analysis'] = trim($pool['analysis']->json('content.0.text') ?? '');
            }

            if (isset($pool['translation']) && $pool['translation'] instanceof \Illuminate\Http\Client\Response && $pool['translation']->successful()) {
                $results['translation'] = trim($pool['translation']->json('content.0.text') ?? '');
            }
        } catch (\Throwable $e) {
            Log::warning("[DUB] Anthropic parallel call failed: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
        }

        return $results;
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

    private function mergeVoiceMap(array $newSpeakers): void
    {
        $voiceKey = "instant-dub:{$this->sessionId}:voices";
        $driver = config('dubber.tts.default', 'edge');

        if ($driver === 'aisha') {
            // AISHA has 2 voices + 3 moods — use mood to differentiate speakers
            $maleVariants = [
                ['voice' => 'jaxongir', 'mood' => 'neutral'],
                ['voice' => 'jaxongir', 'mood' => 'happy'],
                ['voice' => 'jaxongir', 'mood' => 'sad'],
            ];
            $femaleVariants = [
                ['voice' => 'gulnoza', 'mood' => 'neutral'],
                ['voice' => 'gulnoza', 'mood' => 'happy'],
                ['voice' => 'gulnoza', 'mood' => 'sad'],
            ];
            $childVariants = [
                ['voice' => 'gulnoza', 'mood' => 'happy'],
                ['voice' => 'jaxongir', 'mood' => 'happy'],
            ];
        } else {
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
        }

        // Read existing voice map
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
            ? "\nFILM/SERIES TITLE: \"{$this->title}\" — use your knowledge of this title to:\n"
            . "- Identify the exact scene based on the dialogue lines and their order in the plot timeline\n"
            . "- Know which characters appear in this scene and who says what\n"
            . "- Use character names, genders, ages, and relationships from your knowledge of the film\n"
            . "- Match dialogue lines to the correct characters based on plot context, not just grammar\n"
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
            . "CHARACTERS:\n"
            . "M1: [name/role], [age category], [relationship to others]\n"
            . "F1: [name/role], [age category], [relationship to others]\n"
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
                . '  * Look at CHARACTER ANALYSIS above for age and relationships' . "\n"
                . '  * Elderly/parent → child/young person: always "sen" (-san, -ding, -yapsanmi)' . "\n"
                . '  * Young person → elderly/parent: always "Siz" (-siz, -dingiz, -yapsizmi)' . "\n"
                . '  * Same-age close friends: "sen"' . "\n"
                . '  * Same-age strangers/formal: "Siz"' . "\n"
                . '  * Child → parent: "Siz" (respectful)' . "\n"
                . '  * Husband ↔ wife: usually "sen" (intimate)' . "\n"
                . '  * Boss → employee: can be "sen"; employee → boss: "Siz"' . "\n"
                . $fromLangHint . "\n"
                . "- STYLE — spoken Uzbek, like real people talk:\n"
                . '  * Use colloquial forms: "qilyapman" not "qilayotirman", "ketyapman" not "ketayotirman"' . "\n"
                . '  * Use "bor" not "mavjud", "yo\'q" not "mavjud emas"' . "\n"
                . '  * Contractions: "nimaga" not "nima uchun" (when casual)' . "\n"
                . '  * Emotional words: "voy!" (surprise), "ey!" (calling), "qo\'ying!" (stop it!)' . "\n"
                . "- Names and proper nouns: keep original, don't translate\n"
                . "- Keep emotional register: anger, love, fear, humor must come through";
        }

        $titleHint = ($this->title && $this->title !== 'Untitled')
            ? "\nFILM/SERIES TITLE: \"{$this->title}\" — you know this title. Use your knowledge of the plot, characters, their relationships, and the tone of the story to produce accurate, contextually appropriate translations.\n"
            : '';

        $systemPrompt = "You are an expert film/series dubbing translator. Your translations will be spoken aloud by TTS voice actors, so they must sound like natural spoken {$toLang} dialogue — not written subtitles.\n"
            . $titleHint
            . "\nCHARACTER ANALYSIS:\n{$characterContext}\n"
            . "\nFULL DIALOGUE (for context — do NOT translate this, only use for understanding the scene):\n{$fullDialogue}\n"
            . "{$uzbekRules}\n"
            . "\nTRANSLATION RULES:\n"
            . "1. Each line has [Ns, max M chars]. Try to stay within the character limit, but NEVER sacrifice meaning to fit — keeping the full meaning is more important than the length limit.\n"
            . "2. Lines may contain annotations like [music], [laughing], [whispering], [door opens] etc. — use these to understand the scene mood and context, but translate ONLY the spoken dialogue part. Do not include the annotations in your translation.\n"
            . "3. Translate meaning, not words. Rephrase freely to sound natural in {$toLang}. NEVER drop phrases, skip meaning, or oversimplify — every idea in the original must appear in the translation.\n"
            . "4. Keep the emotional register: if someone is angry, scared, joking, whispering — the translation must convey that.\n"
            . "5. Use the character analysis above to assign the correct speaker tag [M1], [F1], etc. to each line.\n"
            . "6. Preserve interruptions, hesitations, and conversational flow.\n"
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
        if ($sessionJson) {
            $title = json_decode($sessionJson, true)['title'] ?? 'Untitled';
        }

        Log::error("[DUB] [{$title}] Batch {$this->batchIndex} failed permanently: " . $exception->getMessage(), [
            'session' => $this->sessionId,
        ]);

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
            )->onQueue('default');
        } else {
            Redis::del(
                "instant-dub:{$this->sessionId}:full-dialogue",
                "instant-dub:{$this->sessionId}:all-segments",
            );
        }
    }
}
