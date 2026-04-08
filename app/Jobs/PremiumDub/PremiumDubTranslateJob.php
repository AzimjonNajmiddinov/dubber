<?php

namespace App\Jobs\PremiumDub;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PremiumDubTranslateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 2;

    private const BATCH_SIZE    = 20;  // segments to translate per call
    private const CONTEXT_PREV  = 5;   // already-translated segments shown as context
    private const CONTEXT_NEXT  = 5;   // upcoming original segments shown as context

    public function __construct(
        public string $dubId,
    ) {}

    public function handle(): void
    {
        $session      = $this->getSession();
        $segments     = $session['segments'] ?? [];
        $speakers     = $session['speakers_info'] ?? [];
        $language     = $session['language'] ?? 'uz';
        $translateFrom = $session['translate_from'] ?? $session['detected_language'] ?? 'auto';

        if (empty($segments)) {
            $this->updateStatus('error', 'No segments to translate');
            return;
        }

        $this->updateStatus('translating', 'Translating dialogue...');

        $langNames = [
            'uz' => 'Uzbek', 'ru' => 'Russian', 'en' => 'English', 'tr' => 'Turkish',
            'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'ar' => 'Arabic',
            'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean',
        ];
        $toLang = $langNames[$language] ?? $language;

        // Speaker context (gender/age for natural voice matching)
        $speakerContext = '';
        foreach ($speakers as $spk => $info) {
            $gender = $info['gender'] ?? 'unknown';
            $age    = $info['age_group'] ?? 'unknown';
            $speakerContext .= "{$spk}: {$gender}, {$age}\n";
        }

        $total              = count($segments);
        $translatedSegments = [];
        $batchNum           = 0;
        $totalBatches       = (int) ceil($total / self::BATCH_SIZE);

        for ($offset = 0; $offset < $total; $offset += self::BATCH_SIZE) {
            $batchNum++;
            $this->updateSession(['progress' => "Translating {$batchNum}/{$totalBatches}..."]);

            $batch = array_slice($segments, $offset, self::BATCH_SIZE, true);

            // --- Previous context: last N already-translated segments ---
            $prevContext = '';
            if (!empty($translatedSegments)) {
                $prevDone  = array_values($translatedSegments);
                $prevSlice = array_slice($prevDone, -self::CONTEXT_PREV);
                $lines     = [];
                foreach ($prevSlice as $s) {
                    $lines[] = '[' . ($s['speaker'] ?? '') . '] ' . $s['text'];
                }
                $prevContext = implode("\n", $lines);
            }

            // --- Next context: upcoming original segments (after this batch) ---
            $nextContext = '';
            $nextSlice   = array_slice($segments, $offset + self::BATCH_SIZE, self::CONTEXT_NEXT, true);
            if (!empty($nextSlice)) {
                $lines = [];
                foreach ($nextSlice as $s) {
                    $lines[] = '[' . ($s['speaker'] ?? '') . '] ' . $s['text'];
                }
                $nextContext = implode("\n", $lines);
            }

            // --- Lines to translate (duration hint, no char limit) ---
            $lines = [];
            foreach ($batch as $i => $seg) {
                $duration = round(($seg['end'] ?? 0) - ($seg['start'] ?? 0), 1);
                $lines[]  = ($i + 1) . '. [' . $duration . 's] [' . ($seg['speaker'] ?? 'SPEAKER_0') . '] ' . $seg['text'];
            }

            $systemPrompt = $this->buildSystemPrompt($toLang, $speakerContext, $prevContext, $nextContext);
            $userPrompt   = "Translate:\n\n" . implode("\n", $lines);

            $translated = $this->callTranslation($systemPrompt, $userPrompt);

            // Parse response
            foreach (preg_split('/\n+/', $translated) as $line) {
                if (preg_match('/^(\d+)\.\s*\[([^:\]]+)(?::(\w+))?\]\s*(.+)/', $line, $m)) {
                    $idx = (int) $m[1] - 1;
                    if (isset($segments[$idx])) {
                        $translatedSegments[$idx] = [
                            'start'         => $segments[$idx]['start'],
                            'end'           => $segments[$idx]['end'],
                            'original_text' => $segments[$idx]['text'],
                            'text'          => trim($m[4]),
                            'speaker'       => $m[2],
                            'emotion'       => $m[3] ?? 'neutral',
                        ];
                    }
                }
            }
        }

        // Fill any parse-missed segments with original text
        foreach ($segments as $i => $seg) {
            if (!isset($translatedSegments[$i])) {
                $translatedSegments[$i] = [
                    'start'         => $seg['start'],
                    'end'           => $seg['end'],
                    'original_text' => $seg['text'],
                    'text'          => $seg['text'],
                    'speaker'       => $seg['speaker'] ?? 'SPEAKER_0',
                    'emotion'       => 'neutral',
                ];
            }
        }

        ksort($translatedSegments);

        $this->updateSession([
            'translated_segments' => array_values($translatedSegments),
            'translation_ready'   => true,
        ]);

        Log::info("[PREMIUM] [{$this->dubId}] Translation complete: " . count($translatedSegments) . " segments");

        PremiumDubCloneAndSynthesizeJob::dispatch($this->dubId)->onQueue('default');
    }

    private function buildSystemPrompt(
        string $toLang,
        string $speakerContext,
        string $prevContext,
        string $nextContext,
    ): string {
        $prompt = "You are an expert film dubbing translator. Translate each line to natural spoken {$toLang}.\n";

        if ($speakerContext) {
            $prompt .= "\nSPEAKERS:\n{$speakerContext}";
        }

        if ($prevContext) {
            $prompt .= "\nPREVIOUS DIALOGUE (already translated — for continuity):\n{$prevContext}\n";
        }

        if ($nextContext) {
            $prompt .= "\nUPCOMING LINES (original — for context only, do NOT translate):\n{$nextContext}\n";
        }

        $prompt .= "\nRULES:\n"
            . "1. Each line shows its time slot in seconds [Xs] — translate so it can be spoken naturally in that time.\n"
            . "2. NEVER cut a sentence short or leave meaning incomplete to fit the slot.\n"
            . "3. Keep the emotional register: anger, fear, humor, love must come through.\n"
            . "4. Use punctuation expressively: ! for emphasis, ... for hesitation, — for pauses.\n"
            . "5. Adapt cultural references naturally to the target language.\n"
            . "6. Detect the EMOTION of each line.\n"
            . "\nEMOTION TAGS: neutral, happy, angry, sad, fearful, surprised, whispering, serious, sarcastic, excited\n"
            . "\n" . 'Output format (one line per segment, nothing else):'
            . "\n" . '"1. [SPEAKER_00:angry] Translated text here."'
            . "\n" . '"2. [SPEAKER_01:neutral] Another line."';

        return $prompt;
    }

    private function callTranslation(string $system, string $user): string
    {
        $anthropicKey = config('services.anthropic.key');
        if ($anthropicKey) {
            try {
                $response = Http::withHeaders([
                    'x-api-key'         => $anthropicKey,
                    'anthropic-version' => '2023-06-01',
                ])->timeout(90)->post('https://api.anthropic.com/v1/messages', [
                    'model'      => 'claude-sonnet-4-6',
                    'max_tokens' => 4096,
                    'system'     => $system,
                    'messages'   => [['role' => 'user', 'content' => $user]],
                ]);

                if ($response->successful()) {
                    return trim($response->json('content.0.text') ?? '');
                }
            } catch (\Throwable $e) {
                Log::warning("[PREMIUM] [{$this->dubId}] Claude failed, falling back to GPT: " . $e->getMessage());
            }
        }

        $openaiKey = config('services.openai.key');
        if ($openaiKey) {
            $response = Http::withToken($openaiKey)->timeout(90)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'       => 'gpt-4o',
                    'temperature' => 0.3,
                    'messages'    => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user',   'content' => $user],
                    ],
                ]);

            if ($response->successful()) {
                return trim($response->json('choices.0.message.content') ?? '');
            }
        }

        return '';
    }

    private function getSession(): array
    {
        $json = Redis::get("premium-dub:{$this->dubId}");
        return $json ? json_decode($json, true) : [];
    }

    private function updateStatus(string $status, string $progress = ''): void
    {
        $key     = "premium-dub:{$this->dubId}";
        $json    = Redis::get($key);
        $session = $json ? json_decode($json, true) : [];
        $session['status'] = $status;
        if ($progress) $session['progress'] = $progress;
        Redis::setex($key, 86400, json_encode($session));
    }

    private function updateSession(array $data): void
    {
        $key     = "premium-dub:{$this->dubId}";
        $json    = Redis::get($key);
        $session = $json ? json_decode($json, true) : [];
        Redis::setex($key, 86400, json_encode(array_merge($session, $data)));
    }

    public function failed(\Throwable $e): void
    {
        $this->updateStatus('error', 'Translation failed');
        Log::error("[PREMIUM] [{$this->dubId}] PremiumDubTranslateJob failed: " . $e->getMessage());
    }
}
