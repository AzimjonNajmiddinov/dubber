<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\LocalTranslationClient;
use App\Support\DubSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class TranslateInstantDubMicroBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1;

    private ?string $lastAnthropicFailure = null;
    private ?string $lastOpenAiFailure = null;

    public function __construct(
        public string  $sessionId,
        public array   $segments,
        public string  $language,
        public string  $translateFrom,
        public ?float  $nextSegmentStart = null,
    ) {}

    public function handle(): void
    {
        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['status'] ?? '') === 'stopped') return;

        $title = $session['title'] ?? 'Untitled';
        $fullDialogueText = Redis::get(DubSession::fullDialogueKey($this->sessionId)) ?? '';

        try {
            $translated = $this->translateMicroBatch($this->segments, $fullDialogueText);
        } catch (\Throwable $e) {
            DubSession::patch($this->sessionId, [
                'status' => 'error',
                'error' => 'Translation failed: ' . Str::limit($e->getMessage(), 500),
            ]);
            Log::error("[DUB] [{$title}] Micro-batch translation failed; stopping session: " . Str::limit($e->getMessage(), 200), [
                'session' => $this->sessionId,
            ]);
            return;
        }

        // Merge voice map for these speakers
        $speakers = [];
        foreach ($translated as $seg) {
            $speakers[$seg['speaker'] ?? 'M1'] = true;
        }
        $this->mergeVoiceMap($speakers);

        // Dispatch TTS for micro-batch segments (global indices 0, 1, 2, ...).
        // Translation parsing has already rejected skipped or empty lines.
        foreach ($translated as $i => $seg) {
            $text = trim($seg['text']);
            $text = trim(preg_replace('/\[[^\]]*\]\s*/', '', $text));
            $text = str_replace('`', '\'', $text);
            // Strip *emphasis* markers — kept as metadata by translator but not speakable
            $text = preg_replace('/\*([^*]+)\*/', '$1', $text);

            $slotEnd = isset($translated[$i + 1])
                ? (float) $translated[$i + 1]['start']
                : $this->nextSegmentStart;

            ProcessInstantDubSegmentJob::dispatch(
                $this->sessionId, $i, $text,
                $seg['start'], $seg['end'], $this->language,
                $seg['speaker'] ?? 'M1',
                $slotEnd,
                $seg['source_text'] ?? ($this->segments[$i]['text'] ?? null),
                $seg['delivery'] ?? null,
                0,
            )->onQueue('segment-generation');
        }

        Log::info("[DUB] [{$title}] Micro-batch: " . count($translated) . " segments translated and dispatched for TTS", [
            'session' => $this->sessionId,
        ]);
    }

    private function translateMicroBatch(array $segments, string $fullDialogue): array
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

        $forceVoice = false;

        $lines = [];
        foreach ($segments as $i => $seg) {
            $duration = round($seg['end'] - $seg['start'], 1);
            $rawText = $seg['raw_text'] ?? $seg['text'];
            if ($forceVoice) {
                $lines[] = ($i + 1) . '. [' . $duration . 's] ' . $rawText;
            } else {
                $maxChars = (int) round($duration * 12);
                $lines[] = ($i + 1) . '. [' . $duration . 's, max ' . $maxChars . ' chars] ' . $rawText;
            }
        }

        $targetRules = $this->targetLanguageRules($toLang);
        $universalRules = "\nSOURCE HANDLING (works for ANY source language):\n"
            . "- Source language is {$fromLang}. If a line is in another language, silently detect that line's language and translate it too.\n"
            . "- If subtitles are romanized, badly OCR'd, or lightly mistranscribed, restore the intended spoken sentence from context before translating.\n"
            . "- Do not copy source-language words into {$toLang} unless they are names, brands, places, titles, or intentionally foreign words.\n"
            . "- Preserve meaning, intent, emotion, and who is speaking. Do not summarize. Do not add new facts.\n"
            . "\nTTS-READY OUTPUT:\n"
            . "- Output only words the actor should say. Remove [music], [laughing], captions, sound effects, speaker labels, HTML, hashtags, URLs, and subtitle artifacts.\n"
            . "- Expand numbers, dates, times, currency, units, and abbreviations into normal spoken {$toLang} words when possible.\n"
            . "- No Markdown, no asterisks, no emojis, no stage directions, no timing text, no original-language explanation.\n"
            . "- Keep punctuation simple for TTS: . , ! ? ... and dashes for pauses only.\n"
            . $targetRules;

        if ($forceVoice) {
            $systemPrompt = "You are a professional translator. Translate the following dialogue into {$toLang}.\n"
                . "\nCRITICAL: Translate EVERYTHING completely. Do NOT shorten, omit, or summarize any words. Timing is shown for context only — never cut content to fit the slot.\n"
                . "\nSCENE DIALOGUE (context):\n{$fullDialogue}\n"
                . $universalRules
                . "\nRULES:\n"
                . "1. Translate every word faithfully — omitting words is not allowed.\n"
                . "2. Keep names/titles recognizable, but transliterate them to the target language's normal pronunciation when needed for TTS.\n"
                . "3. Punctuation = emotion: ! anger, ... hesitation, — pause, ? question.\n"
                . "\n" . 'Format: "1. text {emotion|pace}"' . "\n"
                . "Append delivery hint: emotion=neutral/angry/happy/sad/fearful/excited/calm/whisper, pace=normal/fast/slow";
        } else {
            $systemPrompt = "You are a dubbing voice director writing dialogue for a film in {$toLang}. You watch the scene, understand the story and emotions, then write what the characters would ACTUALLY SAY in {$toLang} — not a translation, but a re-creation.\n"
                . "\nCRITICAL: Each line has a TIME SLOT [Ns]. Your text must be speakable within that duration. Short slot = concise. Long slot = natural phrasing. Never write more than fits.\n"
                . "\nSCENE DIALOGUE (context):\n{$fullDialogue}\n"
                . $universalRules
                . "\nRULES:\n"
                . "1. Read the scene. Understand WHY each character says what they say.\n"
                . "2. Translate the meaning first, then make it natural spoken {$toLang}; never leave untranslated source text behind.\n"
                . "3. Keep names/titles recognizable, but transliterate them to the target language's normal pronunciation when needed for TTS.\n"
                . "4. Punctuation = emotion: ! anger, ... hesitation, — pause, ? question.\n"
                . "\n" . 'Format: "1. text {emotion|pace}"' . "\n"
                . "Append delivery hint: emotion=neutral/angry/happy/sad/fearful/excited/calm/whisper, pace=normal/fast/slow";
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Translate:\n\n" . implode("\n", $lines)],
        ];

        $providerFailures = [];
        $localTranslator = app(LocalTranslationClient::class);
        if ($localTranslator->enabled()) {
            $result = $localTranslator->chat($messages, timeout: 60, maxTokens: 2048);
            if ($result !== null) {
                $parsed = $this->tryParseProviderTranslation('local translation', $segments, $result, $providerFailures);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        // Optional self-hosted Uzbek translator. If it is not configured, skip it
        // silently: this deployment uses Claude/OpenAI as the translation providers.
        $uzbekTranslatorUrl = config('services.uzbektranslator.url');
        if ($uzbekTranslatorUrl && $this->language === 'uz' && in_array($this->translateFrom, ['en', 'ru'])) {
            $uzResult = $this->callUzbekTranslator($segments);
            if ($uzResult !== null) {
                return $uzResult;
            }

            $providerFailures[] = 'uzbekTranslator returned no usable output';
        }

        if ($localTranslator->enabled() && !$localTranslator->allowPaidFallback()) {
            $reason = $providerFailures
                ? ': ' . implode('; ', $providerFailures)
                : '.';
            throw new \RuntimeException('Local translation returned no usable micro-batch output' . $reason);
        }

        // Try Claude
        $result = $this->callAnthropic($messages);
        if ($result !== null) {
            $parsed = $this->tryParseProviderTranslation('Claude', $segments, $result, $providerFailures);
            if ($parsed !== null) {
                return $parsed;
            }
        } else {
            $providerFailures[] = $this->lastAnthropicFailure
                ?? (config('services.anthropic.key') ? 'Claude returned no response' : 'Claude not configured');
        }

        // Fallback: GPT-4o
        $openaiKey = config('services.openai.key');
        if ($openaiKey) {
            try {
                $resp = Http::withToken($openaiKey)->timeout(30)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o',
                        'temperature' => 0.3,
                        'messages' => $messages,
                    ]);
                if ($resp->successful()) {
                    $parsed = $this->tryParseProviderTranslation(
                        'OpenAI',
                        $segments,
                        $resp->json('choices.0.message.content') ?? '',
                        $providerFailures
                    );
                    if ($parsed !== null) {
                        return $parsed;
                    }
                } else {
                    $this->lastOpenAiFailure = 'OpenAI HTTP ' . $resp->status() . ': ' . Str::limit($resp->body(), 180);
                    $providerFailures[] = $this->lastOpenAiFailure;
                }
            } catch (\Throwable $e) {
                $this->lastOpenAiFailure = 'OpenAI exception: ' . Str::limit($e->getMessage(), 180);
                $providerFailures[] = $this->lastOpenAiFailure;
            }
        } else {
            $providerFailures[] = 'OpenAI not configured';
        }

        $reason = $providerFailures
            ? ': ' . implode('; ', array_unique($providerFailures))
            : '.';
        throw new \RuntimeException('No translation provider returned usable micro-batch output' . $reason . '. Configure ANTHROPIC_API_KEY or OPENAI_API_KEY on the server, clear Laravel config cache, and restart queue workers.');
    }

    private function tryParseProviderTranslation(string $provider, array $segments, string $content, array &$providerFailures): ?array
    {
        try {
            return $this->parseTranslationResponse($segments, $content);
        } catch (\Throwable $e) {
            $providerFailures[] = "{$provider}: " . $e->getMessage();
            Log::warning("[DUB] Micro-batch {$provider} output rejected: " . $e->getMessage(), [
                'session' => $this->sessionId,
                'sample' => Str::limit($content, 500),
            ]);

            return null;
        }
    }

    /**
     * Translate the batch via the uzbekTranslator /translate_dub endpoint.
     * Sends all segments at once with timing, speaker, and scene context.
     * Returns translated batch, or null if the service is unavailable.
     */
    private function callUzbekTranslator(array $segments): ?array
    {
        $serviceUrl = config('services.uzbektranslator.url') ?: env('UZBEKTRANSLATOR_SERVICE_URL');
        if (!$serviceUrl) return null;

        // Build segment list with timing and speaker for the dub endpoint
        $dubSegments = [];
        foreach ($segments as $seg) {
            $rawText = trim($seg['raw_text'] ?? $seg['text']);
            if ($rawText === '') continue;
            $dubSegments[] = [
                'text'     => $rawText,
                'speaker'  => $seg['speaker'] ?? 'M1',
                'duration' => round((float) $seg['end'] - (float) $seg['start'], 2),
            ];
        }
        if (empty($dubSegments)) return null;

        // Use full dialogue as scene context (same as Claude path)
        $sceneContext = Redis::get(DubSession::fullDialogueKey($this->sessionId)) ?? '';

        try {
            $resp = Http::timeout(60)->post("{$serviceUrl}/translate_dub", [
                'segments'        => $dubSegments,
                'source_language' => $this->translateFrom,
                'scene_context'   => mb_substr($sceneContext, 0, 1000), // cap context length
            ]);

            if (!$resp->successful()) return null;

            $translations = $resp->json('translations');
            if (!is_array($translations) || count($translations) !== count($dubSegments)) {
                return null;
            }

            // Merge translated text back into original segment array
            $translated = $segments;
            $j = 0;
            foreach ($translated as $i => $seg) {
                $rawText = trim($seg['raw_text'] ?? $seg['text']);
                if ($rawText === '') continue;
                $t = $translations[$j++] ?? null;
                if ($t && !empty(trim($t['text'] ?? ''))) {
                    $translated[$i]['text']    = trim($t['text']);
                    $translated[$i]['speaker'] = $t['speaker'] ?? ($seg['speaker'] ?? 'M1');
                }
            }

            return $translated;
        } catch (\Throwable $e) {
            Log::warning("[DUB] uzbekTranslator /translate_dub error: " . $e->getMessage(), [
                'session' => $this->sessionId,
            ]);
            return null;
        }
    }

    private function callAnthropic(array $messages): ?string
    {
        $apiKey = config('services.anthropic.key');
        if (!$apiKey) {
            $this->lastAnthropicFailure = 'Claude not configured';
            return null;
        }

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
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-3-5-sonnet-latest',
                'max_tokens' => 1024,
                'system' => $system,
                'messages' => $anthropicMessages,
            ]);

            if ($response->successful()) {
                $content = trim($response->json('content.0.text') ?? '');
                if ($content !== '') {
                    return $content;
                }

                $this->lastAnthropicFailure = 'Claude returned empty response';
                return null;
            }

            $this->lastAnthropicFailure = 'Claude HTTP ' . $response->status() . ': ' . Str::limit($response->body(), 180);
        } catch (\Throwable $e) {
            $this->lastAnthropicFailure = 'Claude exception: ' . Str::limit($e->getMessage(), 180);
            Log::warning("[DUB] Micro-batch Anthropic error: " . $e->getMessage(), ['session' => $this->sessionId]);
        }

        return null;
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
                [$idx, $text] = $parsedLine;
                if (isset($batch[$idx])) {
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

        if (!empty($missing)) {
            throw new \RuntimeException('Translation response skipped line(s): ' . implode(', ', $missing));
        }

        $empty = [];
        foreach ($batch as $idx => $seg) {
            if (trim((string) ($seg['text'] ?? '')) === '') {
                $empty[] = $idx + 1;
            }
        }

        if (!empty($empty)) {
            throw new \RuntimeException('Translation response produced empty line(s): ' . implode(', ', $empty));
        }

        // Replace stray Cyrillic characters with Latin equivalents
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

        return [(int) $number - 1, trim($m[3])];
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

    private function extractDelivery(string $text, array &$seg): string
    {
        // Matches both {calm|slow} and {emotion:calm|pace:slow}
        if (preg_match('/\{(?:emotion:)?([a-z]+)\|(?:pace:)?([a-z]+)\}\s*$/i', $text, $m)) {
            $seg['delivery'] = strtolower($m[1]) . '|' . strtolower($m[2]);
            $text = trim(substr($text, 0, -strlen($m[0])));
        }
        return $text;
    }

    private function mergeVoiceMap(array $speakers): void
    {
        $voiceKey    = DubSession::voicesKey($this->sessionId);
        $lockKey     = DubSession::voicesLockKey($this->sessionId);
        $driver      = 'edge';
        $variants    = \App\Services\VoiceMapBuilder::variantsForDriver($driver, $this->language);

        $lock = Cache::lock($lockKey, 5);
        $lock->block(5, function () use ($voiceKey, $speakers, $variants) {
            $voiceMap = json_decode(Redis::get($voiceKey) ?? '{}', true) ?: [];
            $voiceMap = array_filter($voiceMap, fn ($entry) => !is_array($entry) || empty($entry['driver']) || $entry['driver'] === 'edge');
            $voiceMap = \App\Services\VoiceMapBuilder::assignSpeakers($voiceMap, $speakers, $variants);
            Redis::setex($voiceKey, DubSession::TTL, json_encode($voiceMap));
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[DUB] Micro-batch failed: " . $exception->getMessage(), [
            'session' => $this->sessionId,
        ]);
    }
}
