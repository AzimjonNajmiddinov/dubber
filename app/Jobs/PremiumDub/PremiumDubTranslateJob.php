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

    public function __construct(
        public string $dubId,
    ) {}

    public function handle(): void
    {
        $session = $this->getSession();
        $segments = $session['segments'] ?? [];
        $speakers = $session['speakers_info'] ?? [];
        $language = $session['language'] ?? 'uz';
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

        // Build full dialogue for context
        $fullDialogue = [];
        foreach ($segments as $i => $seg) {
            $fullDialogue[] = ($i + 1) . '. [' . ($seg['speaker'] ?? 'SPEAKER_0') . '] ' . $seg['text'];
        }
        $fullDialogueText = implode("\n", $fullDialogue);

        // Build speaker context
        $speakerContext = "SPEAKERS:\n";
        foreach ($speakers as $spk => $info) {
            $gender = $info['gender'] ?? 'unknown';
            $age = $info['age_group'] ?? 'unknown';
            $speakerContext .= "{$spk}: {$gender}, {$age}\n";
        }

        // Translate in batches of 20
        $batches = array_chunk($segments, 20, true);
        $translatedSegments = [];
        $batchNum = 0;

        foreach ($batches as $batch) {
            $batchNum++;
            $this->updateSession(['progress' => "Translating batch {$batchNum}/" . count($batches) . "..."]);

            $lines = [];
            foreach ($batch as $i => $seg) {
                $duration = round(($seg['end'] ?? 0) - ($seg['start'] ?? 0), 1);
                $maxChars = (int) round($duration * 12);
                $lines[] = ($i + 1) . '. [' . $duration . 's, max ' . $maxChars . ' chars] [' . ($seg['speaker'] ?? 'SPEAKER_0') . '] ' . $seg['text'];
            }

            $systemPrompt = "You are an expert film dubbing translator. Translate to natural spoken {$toLang}.\n"
                . "\n{$speakerContext}\n"
                . "\nFULL DIALOGUE (context):\n{$fullDialogueText}\n"
                . "\nRULES:\n"
                . "1. Char limits are soft — NEVER cut meaning or leave sentences incomplete.\n"
                . "2. Detect the EMOTION of each line from context and dialogue.\n"
                . "3. Keep emotional register: anger, love, fear, humor must come through.\n"
                . "4. Use punctuation expressively: ! for emphasis, ... for hesitation, — for pauses.\n"
                . "5. Adapt cultural references naturally.\n"
                . "\nEMOTION TAGS: neutral, happy, angry, sad, fearful, surprised, whispering, serious, sarcastic, excited\n"
                . "\n" . 'Format: "1. [SPEAKER_0:emotion] translated text"' . "\n"
                . 'Example: "1. [SPEAKER_0:angry] Get out of here!"';

            $translated = $this->callTranslation($systemPrompt, "Translate:\n\n" . implode("\n", $lines));

            // Parse response
            foreach (preg_split('/\n+/', $translated) as $line) {
                if (preg_match('/^(\d+)\.\s*\[([^:\]]+)(?::(\w+))?\]\s*(.+)/', $line, $m)) {
                    $idx = (int) $m[1] - 1;
                    if (isset($segments[$idx])) {
                        $translatedSegments[$idx] = [
                            'start' => $segments[$idx]['start'],
                            'end' => $segments[$idx]['end'],
                            'original_text' => $segments[$idx]['text'],
                            'text' => trim($m[4]),
                            'speaker' => $m[2],
                            'emotion' => $m[3] ?? 'neutral',
                        ];
                    }
                }
            }
        }

        // Fill gaps with untranslated segments
        foreach ($segments as $i => $seg) {
            if (!isset($translatedSegments[$i])) {
                $translatedSegments[$i] = [
                    'start' => $seg['start'],
                    'end' => $seg['end'],
                    'original_text' => $seg['text'],
                    'text' => $seg['text'],
                    'speaker' => $seg['speaker'] ?? 'SPEAKER_0',
                    'emotion' => 'neutral',
                ];
            }
        }

        ksort($translatedSegments);

        $this->updateSession([
            'translated_segments' => array_values($translatedSegments),
            'translation_ready' => true,
        ]);

        Log::info("[PREMIUM] [{$this->dubId}] Translation complete: " . count($translatedSegments) . " segments");

        // Next step: clone voices + synthesize
        PremiumDubCloneAndSynthesizeJob::dispatch($this->dubId)->onQueue('default');
    }

    private function callTranslation(string $system, string $user): string
    {
        // Try Claude first
        $anthropicKey = config('services.anthropic.key');
        if ($anthropicKey) {
            try {
                $response = Http::withHeaders([
                    'x-api-key' => $anthropicKey,
                    'anthropic-version' => '2023-06-01',
                ])->timeout(90)->post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-sonnet-4-6',
                    'max_tokens' => 4096,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $user]],
                ]);

                if ($response->successful()) {
                    return trim($response->json('content.0.text') ?? '');
                }
            } catch (\Throwable $e) {
                Log::warning("[PREMIUM] [{$this->dubId}] Claude failed, falling back to GPT: " . $e->getMessage());
            }
        }

        // Fallback: GPT-4o
        $openaiKey = config('services.openai.key');
        if ($openaiKey) {
            $response = Http::withToken($openaiKey)->timeout(90)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o',
                    'temperature' => 0.3,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
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
        $key = "premium-dub:{$this->dubId}";
        $json = Redis::get($key);
        $session = $json ? json_decode($json, true) : [];
        $session['status'] = $status;
        if ($progress) $session['progress'] = $progress;
        Redis::setex($key, 86400, json_encode($session));
    }

    private function updateSession(array $data): void
    {
        $key = "premium-dub:{$this->dubId}";
        $json = Redis::get($key);
        $session = $json ? json_decode($json, true) : [];
        Redis::setex($key, 86400, json_encode(array_merge($session, $data)));
    }

    public function failed(\Throwable $e): void
    {
        $this->updateStatus('error', 'Translation failed');
        Log::error("[PREMIUM] [{$this->dubId}] PremiumDubTranslateJob failed: " . $e->getMessage());
    }
}
