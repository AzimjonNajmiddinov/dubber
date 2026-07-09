<?php

namespace App\Jobs\Concerns;

use App\Services\AnthropicModelResolver;
use App\Support\DubSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Shared translation machinery for the instant-dub translation jobs
 * (TranslateInstantDubBatchJob + TranslateInstantDubMicroBatchJob):
 * Anthropic/OpenAI response parsing, TTS sanitization, Uzbek script
 * cleanup, delivery-hint extraction, and voice-map merging.
 *
 * Jobs customize behavior via the three hook methods below.
 */
trait ParsesInstantDubTranslation
{
    private ?string $lastAnthropicFailure = null;

    /** Log prefix identifying the job in shared log lines. */
    protected function translationLogPrefix(): string
    {
        return '[DUB]';
    }

    /** Global segment-number offset accepted in LLM output numbering. */
    protected function translationGlobalOffset(): int
    {
        return 0;
    }

    /** Max fraction of batch lines that may be silently replaced with placeholders. */
    protected function maxSilentLineRatio(): float
    {
        return 0.2;
    }

    private function callAnthropic(array $messages, int $timeout = 60, int $maxTokens = 4096): ?string
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
                ])->timeout($timeout)->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
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
                Log::warning($this->translationLogPrefix() . " Anthropic API error ({$model}): HTTP " . $response->status(), [
                    'session' => $this->sessionId,
                    'body' => Str::limit($response->body(), 200),
                ]);

                if (!in_array($response->status(), [400, 404, 429, 529], true)) {
                    break;
                }
            } catch (\Throwable $e) {
                $failures[] = 'Claude ' . $model . ' exception: ' . Str::limit($e->getMessage(), 180);
                Log::warning($this->translationLogPrefix() . " Anthropic API exception ({$model}): " . $e->getMessage(), [
                    'session' => $this->sessionId,
                ]);
            }
        }

        $this->lastAnthropicFailure = implode('; ', array_unique($failures)) ?: 'Claude returned no response';
        return null;
    }

    private function tryParseProviderTranslation(string $provider, array $batch, string $content, array &$providerFailures): ?array
    {
        try {
            return $this->parseTranslationResponse($batch, $content);
        } catch (\Throwable $e) {
            $providerFailures[] = "{$provider}: " . $e->getMessage();
            Log::warning($this->translationLogPrefix() . " {$provider} output rejected: " . $e->getMessage(), [
                'session' => $this->sessionId,
                'sample' => Str::limit($content, 500),
            ]);

            return null;
        }
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

        $maxSilentLines = max(1, (int) floor(count($batch) * $this->maxSilentLineRatio()));
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

        Log::warning($this->translationLogPrefix() . " using silent placeholder for translation line(s): " . implode(', ', $lineNumbers), [
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

        $globalStart = $this->translationGlobalOffset();
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

        Log::info($this->translationLogPrefix() . ' Voice map merged: new=' . implode(',', array_keys($newSpeakers)) . ' total=' . implode(',', array_keys($voiceMap)), [
            'session' => $this->sessionId,
        ]);
    }

}
