<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\Concerns\ParsesInstantDubTranslation;
use App\Support\DubSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class TranslateInstantDubMicroBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use ParsesInstantDubTranslation;

    public int $timeout = 60;
    public int $tries = 1;

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
        // Translation parsing has already converted tiny gaps into explicit silent placeholders.
        foreach ($translated as $i => $seg) {
            $text = trim($seg['text']);
            $text = trim(preg_replace('/\[[^\]]*\]\s*/', '', $text));
            $text = str_replace('`', '\'', $text);
            // Strip *emphasis* markers — kept as metadata by translator but not speakable
            $text = $this->scrubUtf8(preg_replace('/\*([^*]+)\*/', '$1', $text));
            $sourceText = $seg['source_text'] ?? ($this->segments[$i]['text'] ?? null);
            $sourceText = $sourceText !== null ? $this->scrubUtf8($sourceText) : null;

            $slotEnd = isset($translated[$i + 1])
                ? (float) $translated[$i + 1]['start']
                : $this->nextSegmentStart;

            ProcessInstantDubSegmentJob::dispatch(
                $this->sessionId, $i, $text,
                $seg['start'], $seg['end'], $this->language,
                $seg['speaker'] ?? 'M1',
                $slotEnd,
                $sourceText,
                $seg['delivery'] ?? null,
                0,
            )->onQueue('segment-generation');
        }

        Log::info("[DUB] [{$title}] Micro-batch: " . count($translated) . " segments translated and dispatched for TTS", [
            'session' => $this->sessionId,
        ]);
    }

    protected function translationLogPrefix(): string
    {
        return '[DUB] Micro-batch';
    }

    /** Micro-batch is tiny (~3 lines), so allow a higher silent-placeholder ratio. */
    protected function maxSilentLineRatio(): float
    {
        return 0.34;
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

        $lines = [];
        foreach ($segments as $i => $seg) {
            $duration = round($seg['end'] - $seg['start'], 1);
            $rawText = $seg['raw_text'] ?? $seg['text'];
            $maxChars = (int) round($duration * 12);
            $lines[] = ($i + 1) . '. [' . $duration . 's, max ' . $maxChars . ' chars] ' . $rawText;
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
            . "Append delivery hint: emotion=neutral/angry/happy/sad/fearful/excited/calm/whisper, pace=normal/fast/slow\n"
            . "Return ONLY the numbered translated lines. No analysis, no intro sentence, no explanation.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Translate:\n\n" . implode("\n", $lines)],
        ];

        $providerFailures = [];

        // Try Claude
        $result = $this->callAnthropic($messages, timeout: 30, maxTokens: 1024);
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

    public function failed(\Throwable $exception): void
    {
        Log::error("[DUB] Micro-batch failed: " . $exception->getMessage(), [
            'session' => $this->sessionId,
        ]);
    }
}
