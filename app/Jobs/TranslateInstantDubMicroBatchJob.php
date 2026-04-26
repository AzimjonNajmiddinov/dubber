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
                $this->segments[$i]['text'] ?? null,
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
            $uzbekRules = "\nUZBEK RULES: Use natural spoken Uzbek. Colloquial forms (qilyapman not qilayotirman). Keep names untranslated. Match emotional register. SCRIPT: ONLY Latin alphabet — NEVER use Cyrillic (а,б,в...), no mixed scripts. SPELLING: fe'l negizi unli bilan tugasa, shaxs qo'shimchasi qo'shganda u unli tushib qolmaydi (tani→taniyman, NOT tanyman).\n";
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

        // Try uzbekTranslator local service first (only uz target, en/ru source)
        if ($this->language === 'uz' && in_array($this->translateFrom, ['en', 'ru'])) {
            $uzResult = $this->callUzbekTranslator($segments);
            if ($uzResult !== null) {
                return $uzResult;
            }
        }

        // Try Claude
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
        $sceneContext = Redis::get("instant-dub:{$this->sessionId}:full-dialogue") ?? '';

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

        return $batch;
    }

    private function mergeVoiceMap(array $speakers): void
    {
        $voiceKey = "instant-dub:{$this->sessionId}:voices";
        $lockKey = "instant-dub:{$this->sessionId}:voices-lock";
        $sessionJson = Redis::get("instant-dub:{$this->sessionId}");
        $sessionData = $sessionJson ? json_decode($sessionJson, true) : [];
        $forceVoice = $sessionData['force_voice'] ?? null;
        $driver = $sessionData['tts_driver'] ?? config('dubber.tts.default', 'edge');

        // Flow 3: force_voice set — assign same voice to all speakers
        if ($forceVoice) {
            $gender = str_starts_with($forceVoice, 'F') ? 'female'
                    : (str_starts_with($forceVoice, 'C') ? 'child' : 'male');
            $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 5);
            $lock->block(5, function () use ($voiceKey, $speakers, $forceVoice, $gender) {
                $voiceMap = json_decode(Redis::get($voiceKey) ?? '{}', true) ?: [];
                foreach (array_keys($speakers) as $tag) {
                    $voiceMap[$tag] = ['driver' => 'mms', 'gender' => $gender, 'pool_name' => $forceVoice, 'tau' => 1.0, 'seed' => 42];
                }
                Redis::setex($voiceKey, 50400, json_encode($voiceMap));
            });
            return;
        }

        if ($driver === 'mms') {
            $maleVariants   = $this->mmsPoolVariants('male');
            $femaleVariants = $this->mmsPoolVariants('female');
            $childVariants  = $this->mmsPoolVariants('child') ?: $this->mmsPoolVariants('male');
        } elseif ($driver === 'aisha') {
            $variants = \App\Services\VoiceVariants::forAisha();
            $maleVariants = $variants['male']; $femaleVariants = $variants['female']; $childVariants = $variants['child'];
        } else {
            $variants = \App\Services\VoiceVariants::forLanguage($this->language);
            $maleVariants = $variants['male']; $femaleVariants = $variants['female']; $childVariants = $variants['child'];
        }

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

    private function mmsPoolVariants(string $gender): array
    {
        $dir   = storage_path("app/voice-pool/{$gender}");
        $files = is_dir($dir) ? glob("{$dir}/*.{wav,mp3,m4a}", GLOB_BRACE) : [];
        return array_map(fn($f) => [
            'driver'    => 'mms',
            'gender'    => $gender,
            'pool_name' => pathinfo($f, PATHINFO_FILENAME),
        ], $files);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[DUB] Micro-batch failed: " . $exception->getMessage(), [
            'session' => $this->sessionId,
        ]);
    }
}
