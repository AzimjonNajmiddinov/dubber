<?php

namespace App\Jobs;

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

class TranslateInstantDubMicroBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1;

    public function __construct(
        public string  $sessionId,
        public array   $segments,
        public string  $language,
        public string  $translateFrom,
        public ?float  $nextSegmentStart = null,
    ) {}

    public function handle(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;

        $session = json_decode($sessionJson, true);
        if (($session['status'] ?? '') === 'stopped') return;

        $title = $session['title'] ?? 'Untitled';
        $fullDialogueText = Redis::get("instant-dub:{$this->sessionId}:full-dialogue") ?? '';

        try {
            $translated = $this->translateMicroBatch($this->segments, $fullDialogueText);
        } catch (\Throwable $e) {
            Log::warning("[DUB] [{$title}] Micro-batch translation failed, using original text: " . Str::limit($e->getMessage(), 100), [
                'session' => $this->sessionId,
            ]);
            $translated = $this->segments;
        }

        // Merge voice map for these speakers
        $speakers = [];
        foreach ($translated as $seg) {
            $speakers[$seg['speaker'] ?? 'M1'] = true;
        }
        $this->mergeVoiceMap($speakers);

        // Dispatch TTS for micro-batch segments (global indices 0, 1, 2, ...)
        foreach ($translated as $i => $seg) {
            $text = trim($seg['text']);
            $text = trim(preg_replace('/\[[^\]]*\]\s*/', '', $text));
            $text = str_replace('`', '\'', $text);
            if ($text === '') continue;

            $slotEnd = isset($translated[$i + 1])
                ? (float) $translated[$i + 1]['start']
                : $this->nextSegmentStart;

            ProcessInstantDubSegmentJob::dispatch(
                $this->sessionId, $i, $text,
                $seg['start'], $seg['end'], $this->language,
                $seg['speaker'] ?? 'M1',
                $slotEnd,
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
        ];
        $toLang = $langNames[$this->language] ?? $this->language;

        $lines = [];
        foreach ($segments as $i => $seg) {
            $duration = round($seg['end'] - $seg['start'], 1);
            $maxChars = (int) round($duration * 12);
            $rawText = $seg['raw_text'] ?? $seg['text'];
            $lines[] = ($i + 1) . '. [' . $duration . 's, max ' . $maxChars . ' chars] ' . $rawText;
        }

        $uzbekRules = '';
        if ($this->language === 'uz') {
            $uzbekRules = "\nUZBEK RULES: Use natural spoken Uzbek. Colloquial forms (qilyapman not qilayotirman). Keep names untranslated. Match emotional register. SPELLING: fe'l negizi unli bilan tugasa, shaxs qo'shimchasi qo'shganda u unli tushib qolmaydi (tani→taniyman, NOT tanyman).\n";
        }

        $systemPrompt = "You are a dubbing voice director writing dialogue for a film in {$toLang}. You watch the scene, understand the story and emotions, then write what the characters would ACTUALLY SAY in {$toLang} — not a translation, but a re-creation.\n"
            . "\nCRITICAL: Each line has a TIME SLOT [Ns]. Your text must be speakable within that duration. Short slot = concise. Long slot = natural phrasing. Never write more than fits.\n"
            . "\nSCENE DIALOGUE (context):\n{$fullDialogue}\n"
            . $uzbekRules
            . "\nRULES:\n"
            . "1. Read the scene. Understand WHY each character says what they say.\n"
            . "2. Write what a {$toLang} speaker would ACTUALLY SAY in that moment — not a word-for-word translation.\n"
            . "3. Assign speaker tags [M1], [F1] based on gender clues.\n"
            . "4. Strip annotations [music], [laughing] — write only spoken words.\n"
            . "5. Punctuation = emotion: ! anger, ... hesitation, — pause, ? question.\n"
            . "\n" . 'Format: "1. [M1] text"';

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Translate:\n\n" . implode("\n", $lines)],
        ];

        // Try Claude first
        $result = $this->callAnthropic($messages);
        if ($result !== null) {
            return $this->parseTranslationResponse($segments, $result);
        }

        // Fallback: GPT-4o
        $openaiKey = config('services.openai.key');
        if ($openaiKey) {
            $resp = Http::withToken($openaiKey)->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o',
                    'temperature' => 0.3,
                    'messages' => $messages,
                ]);
            if ($resp->successful()) {
                return $this->parseTranslationResponse($segments, $resp->json('choices.0.message.content') ?? '');
            }
        }

        return $segments;
    }

    private function callAnthropic(array $messages): ?string
    {
        $apiKey = config('services.anthropic.key');
        if (!$apiKey) return null;

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
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => 1024,
                'system' => $system,
                'messages' => $anthropicMessages,
            ]);

            if ($response->successful()) {
                return trim($response->json('content.0.text') ?? '');
            }
        } catch (\Throwable $e) {
            Log::warning("[DUB] Micro-batch Anthropic error: " . $e->getMessage(), ['session' => $this->sessionId]);
        }

        return null;
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

    private function mergeVoiceMap(array $speakers): void
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

        // Use Redis lock to prevent race condition with batch 0's mergeVoiceMap
        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 5);
        $lock->block(5, function () use ($voiceKey, $speakers, $maleVariants, $femaleVariants, $childVariants) {
            $existingJson = Redis::get($voiceKey);
            $voiceMap = $existingJson ? json_decode($existingJson, true) : [];

            $maleIdx = $femaleIdx = $childIdx = 0;
            foreach ($voiceMap as $tag => $voice) {
                if (str_starts_with($tag, 'C')) $childIdx++;
                elseif (str_starts_with($tag, 'M')) $maleIdx++;
                else $femaleIdx++;
            }

            foreach (array_keys($speakers) as $tag) {
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
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[DUB] Micro-batch failed: " . $exception->getMessage(), [
            'session' => $this->sessionId,
        ]);
    }
}
