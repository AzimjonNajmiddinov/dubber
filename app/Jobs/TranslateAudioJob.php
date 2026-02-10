<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoSegment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Traits\DetectsEnglish;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslateAudioJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use DetectsEnglish;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1200;

    /**
     * Exponential backoff between retries (seconds).
     */
    public array $backoff = [30, 60, 120];

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    /**
     * Handle job failure - mark video as failed.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TranslateAudioJob failed permanently', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $video = Video::find($this->videoId);
            if ($video) {
                $video->update(['status' => 'translation_failed']);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to update video status after TranslateAudioJob failure', [
                'video_id' => $this->videoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(): void
    {
        $lock = Cache::lock("video:{$this->videoId}:translate", 1200);
        if (! $lock->get()) {
            return;
        }

        try {
            /** @var Video $video */
            $video = Video::query()->findOrFail($this->videoId);

            // Normalize target language (critical for determinism)
            $targetLanguage = $this->normalizeTargetLanguage((string) ($video->target_language ?: 'uz'));

            // Fetch all segments ordered by time for surrounding context
            $allSegments = VideoSegment::query()
                ->where('video_id', $video->id)
                ->orderBy('start_time')
                ->get(['id', 'text', 'translated_text', 'start_time', 'end_time']);

            $segments = $allSegments->filter(fn ($s) => $s->translated_text === null);

            if ($segments->isEmpty()) {
                $video->update(['status' => 'translated']);
                GenerateTtsSegmentsJobV2::dispatch($video->id);
                return;
            }

            // Build index for surrounding context lookup and emotional arc
            $segmentsList = $allSegments->values();
            $segmentsById = $allSegments->keyBy('id');

            $apiKey = (string) config('services.openai.key');
            if (trim($apiKey) === '') {
                throw new \RuntimeException('OpenAI API key missing: services.openai.key');
            }

            $client = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(90)
                ->retry(2, 1000);

            foreach ($segments as $seg) {
                $src = trim((string) $seg->text);

                if ($src === '') {
                    $seg->update(['translated_text' => '']);
                    continue;
                }

                // Find surrounding context
                $segIndex = $segmentsList->search(fn ($s) => $s->id === $seg->id);
                $prevText = $segIndex > 0 ? trim((string) $segmentsList[$segIndex - 1]->text) : '';
                $nextText = $segIndex < $segmentsList->count() - 1 ? trim((string) $segmentsList[$segIndex + 1]->text) : '';

                // Build emotional arc context from surrounding segments
                $emotionArc = $this->buildEmotionArc($segmentsList, $segIndex);

                $charCount = mb_strlen($src);

                // Calculate slot duration for time-aware character budget
                $slotDuration = ((float) $seg->end_time) - ((float) $seg->start_time);

                // Attempt up to 2 times if English leaks
                $translated = null;
                $emotion = 'neutral';
                $direction = 'normal';
                $lastBody = null;

                for ($attempt = 1; $attempt <= 2; $attempt++) {
                    $system = $this->buildSystemPrompt($targetLanguage, $attempt, $charCount, $prevText, $nextText, $slotDuration, $emotionArc);

                    // Build messages with few-shot examples for better accuracy
                    $messages = [
                        ['role' => 'system', 'content' => $system],
                    ];

                    // Add few-shot examples with emotion and direction detection (JSON format)
                    if (str_contains($targetLanguage, 'Uzbek') || str_contains($targetLanguage, 'uz')) {
                        $messages[] = ['role' => 'user', 'content' => 'Ask.'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"So\'ra.","e":"neutral","d":"normal"}'];
                        $messages[] = ['role' => 'user', 'content' => 'I can\'t believe this!'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Bunga ishonolmayman!","e":"surprise","d":"loud"}'];
                        $messages[] = ['role' => 'user', 'content' => 'Get out of here right now!'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Hoziroq yo\'qol bu yerdan!","e":"angry","d":"shout"}'];
                        $messages[] = ['role' => 'user', 'content' => 'I miss you so much...'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Seni juda sog\'indim...","e":"sad","d":"soft"}'];
                        $messages[] = ['role' => 'user', 'content' => 'This is amazing! I love it!'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Bu ajoyib! Menga yoqdi!","e":"happy","d":"warm"}'];
                        $messages[] = ['role' => 'user', 'content' => 'Don\'t tell anyone, but...'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Hech kimga aytma, lekin...","e":"neutral","d":"whisper"}'];
                        $messages[] = ['role' => 'user', 'content' => 'Oh, you think you\'re so smart, don\'t you?'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Oh, o\'zingni aqlli deb o\'ylaysan, shundaymi?","e":"neutral","d":"sarcastic"}'];
                    }

                    $messages[] = ['role' => 'user', 'content' => $src];

                    $res = $client->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o',
                        'temperature' => 0.1,
                        'messages' => $messages,
                    ]);

                    if ($res->failed()) {
                        $lastBody = mb_substr($res->body(), 0, 4000);
                        Log::error('OpenAI translate failed', [
                            'video_id' => $video->id,
                            'segment_id' => $seg->id,
                            'attempt' => $attempt,
                            'status' => $res->status(),
                            'body' => $lastBody,
                        ]);
                        throw new \RuntimeException('OpenAI translate request failed');
                    }

                    $out = $res->json('choices.0.message.content');
                    if (!is_string($out)) {
                        Log::error('OpenAI translate invalid response', [
                            'video_id' => $video->id,
                            'segment_id' => $seg->id,
                            'attempt' => $attempt,
                            'json' => $res->json(),
                        ]);
                        throw new \RuntimeException('OpenAI translate invalid response');
                    }

                    $out = trim($out);

                    // Parse JSON response: {"t":"translation","e":"emotion","d":"direction"}
                    $parsedText = $out;
                    $detectedEmotion = 'neutral';
                    $detectedDirection = 'normal';

                    if (str_starts_with($out, '{') && str_contains($out, '"t"')) {
                        $parsed = json_decode($out, true);
                        if (is_array($parsed) && isset($parsed['t'])) {
                            $parsedText = trim($parsed['t']);
                            $detectedEmotion = strtolower(trim($parsed['e'] ?? 'neutral'));
                            $detectedDirection = strtolower(trim($parsed['d'] ?? 'normal'));

                            // Validate emotion
                            $validEmotions = ['neutral', 'happy', 'sad', 'angry', 'fear', 'surprise', 'excited'];
                            if (!in_array($detectedEmotion, $validEmotions)) {
                                $detectedEmotion = 'neutral';
                            }

                            // Validate direction
                            $validDirections = ['whisper', 'soft', 'normal', 'loud', 'shout', 'sarcastic', 'playful', 'cold', 'warm'];
                            if (!in_array($detectedDirection, $validDirections)) {
                                $detectedDirection = 'normal';
                            }

                            Log::info('Emotion and direction detected from translation', [
                                'segment_id' => $seg->id,
                                'emotion' => $detectedEmotion,
                                'direction' => $detectedDirection,
                            ]);
                        }
                    }

                    // Guard: reject English-ish outputs
                    if ($this->looksLikeEnglish($parsedText)) {
                        Log::warning('Translation looks English; retrying with stricter prompt', [
                            'video_id' => $video->id,
                            'segment_id' => $seg->id,
                            'attempt' => $attempt,
                            'target_language' => $targetLanguage,
                            'sample' => mb_substr($parsedText, 0, 160),
                        ]);
                        $translated = null;
                        $emotion = null;
                        continue;
                    }

                    $translated = $parsedText;
                    $emotion = $detectedEmotion;
                    $direction = $detectedDirection;
                    break;
                }

                if ($translated === null) {
                    // Last resort: store *something* but mark it so you can find it
                    Log::error('Translation kept coming back English; storing last output anyway', [
                        'video_id' => $video->id,
                        'segment_id' => $seg->id,
                        'target_language' => $targetLanguage,
                        'src_sample' => mb_substr($src, 0, 160),
                    ]);

                    // Try one more very strict request (single shot)
                    $res = $client->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o',
                        'temperature' => 0.0,
                        'messages' => [
                            ['role' => 'system', 'content' => $this->buildSystemPrompt($targetLanguage, 3, $charCount, $prevText, $nextText, $slotDuration)],
                            ['role' => 'user', 'content' => $src],
                        ],
                    ]);

                    $out = is_string($res->json('choices.0.message.content'))
                        ? trim((string) $res->json('choices.0.message.content'))
                        : '';

                    $translated = $out;
                }

                $seg->update([
                    'translated_text' => $translated,
                    'emotion' => $emotion,
                    'direction' => $direction,
                ]);
            }

            $video->update(['status' => 'translated']);
            GenerateTtsSegmentsJobV2::dispatch($video->id);

        } finally {
            optional($lock)->release();
        }
    }

    private function normalizeTargetLanguage(string $raw): string
    {
        $v = trim(mb_strtolower($raw));

        // Uzbek normalization
        if ($v === '' || $v === 'uz' || $v === 'uzbek' || $v === 'oʻzbek' || $v === 'o‘zbek' || $v === 'uzb' || str_contains($v, 'uzbek')) {
            // Make it explicit: Uzbek Latin
            return "Uzbek (Latin, O‘zbekcha) [uz]";
        }

        // Russian normalization example (optional)
        if ($v === 'ru' || str_contains($v, 'russian')) {
            return "Russian [ru]";
        }

        // Fallback: keep user provided but include "no English" rules anyway
        return $raw;
    }

    private function buildSystemPrompt(string $targetLanguage, int $attempt, int $charCount = 0, string $prevText = '', string $nextText = '', float $slotDuration = 0, string $emotionArc = ''): string
    {
        $extra = '';
        if ($attempt >= 2) {
            $extra =
                "\nSTRICT MODE:\n" .
                "- If you are unsure, still produce output in the target language.\n" .
                "- Do NOT output English words.\n" .
                "- Use natural spoken phrases used by native speakers.\n";
        }
        if ($attempt >= 3) {
            $extra .=
                "- Output must be ONLY Uzbek Latin text.\n" .
                "- If a name is English, keep name but everything else Uzbek.\n";
        }

        // Surrounding context block
        $contextBlock = '';
        if ($prevText !== '' || $nextText !== '') {
            $contextBlock = "\nSURROUNDING DIALOGUE (for natural flow, do NOT translate these):\n";
            if ($prevText !== '') {
                $contextBlock .= "- Previous line: \"{$prevText}\"\n";
            }
            if ($nextText !== '') {
                $contextBlock .= "- Next line: \"{$nextText}\"\n";
            }
        }

        // Time-aware character budget
        // Uzbek TTS speaks ~12 chars/sec at normal speed
        // Use 10 chars/sec to leave buffer for natural pauses
        $budgetRule = '';
        if ($slotDuration > 0) {
            $maxChars = max(5, (int) floor($slotDuration * 10));

            if ($slotDuration < 1.0) {
                $budgetRule = "8. CRITICAL: Only {$slotDuration}s! Max {$maxChars} characters. Be VERY brief.\n";
            } elseif ($slotDuration < 2.0) {
                $budgetRule = "8. SHORT: {$slotDuration}s slot. Max {$maxChars} characters. Keep it brief.\n";
            } elseif ($slotDuration < 3.0) {
                $budgetRule = "8. SHORT: {$slotDuration}s. Keep under {$maxChars} characters. Be extremely brief.\n";
            } else {
                $budgetRule = "8. LIMIT: {$slotDuration}s = max {$maxChars} characters. Brevity is essential.\n";
            }
        }

        // Uzbek-specific translation rules
        $examples = '';
        if (str_contains($targetLanguage, 'Uzbek') || str_contains($targetLanguage, 'uz')) {
            $examples =
                "\n### MUHIM: O'ZBEK TARJIMA QOIDALARI ###\n\n" .
                "FE'L SHAKLLARI - FAQAT NORASMIY \"SEN\" ISHLATILSIN:\n" .
                "- \"Ask\" = \"So'ra\" (XATO: \"So'rang\")\n" .
                "- \"Listen\" = \"Tingla\" (XATO: \"Tinglang\")\n" .
                "- \"Come\" = \"Kel\" (XATO: \"Keling\")\n" .
                "- \"Look\" = \"Qara\" (XATO: \"Qarang\")\n" .
                "- \"Go\" = \"Bor\" yoki \"Ket\" (XATO: \"Boring\")\n\n" .
                "MA'NO BO'YICHA TARJIMA:\n" .
                "- \"End of notes\" = \"Qaydlar tugadi\" yoki \"Eslatmalar oxiri\" (XATO: \"Qaytish\" - bu \"return\" degan ma'no!)\n" .
                "- \"It says, ask\" = \"Unda aytilgan: so'ra\" (bu kitob/qog'oz nimani aytayotganini bildiradi)\n" .
                "- \"Here's what it says\" = \"Mana nima deyilgan\" yoki \"Mana u nima deydi\"\n" .
                "- \"That's it\" = \"Tamom\" yoki \"Shu, xolos\"\n" .
                "- \"The art of asking\" = \"So'rash san'ati\"\n\n" .
                "QOIDALAR:\n" .
                "1. MA'NONI SAQLANG - inglizcha gap nimani anglatsa, o'zbekcha ham shu ma'noni bersin\n" .
                "2. NORASMIY SO'ZLASHING - do'stingiz bilan gaplashgandek\n" .
                "3. QISQA BO'LSIN - dublyaj uchun\n";
        }

        // Emotional arc context
        $emotionArcBlock = '';
        if ($emotionArc !== '') {
            $emotionArcBlock = "\nEMOTIONAL ARC (maintain consistency with scene flow):\n{$emotionArc}\n" .
                "- Avoid jarring emotion switches (e.g., happy→sad→happy in 3 lines)\n" .
                "- Match the scene's emotional trajectory\n";
        }

        return
            "You are a professional {$targetLanguage} translator for movie dubbing with emotional and performance awareness.\n\n" .
            "YOUR TASK:\n" .
            "1. Translate the text accurately and naturally\n" .
            "2. Detect the emotion from context, punctuation, and meaning\n" .
            "3. Detect the acting direction/delivery style\n" .
            "4. Return JSON format: {\"t\":\"translation\",\"e\":\"emotion\",\"d\":\"direction\"}\n\n" .
            "EMOTIONS (choose one): neutral, happy, sad, angry, fear, surprise, excited\n\n" .
            "DIRECTIONS (choose one based on HOW the line should be delivered):\n" .
            "- whisper: intimate, soft, breathy (secrets, romance, fear)\n" .
            "- soft: gentle, caring (comfort, tenderness)\n" .
            "- normal: standard delivery\n" .
            "- loud: raised voice, emphasis (arguments, calls across room)\n" .
            "- shout: yelling, extreme (danger, rage)\n" .
            "- sarcastic: ironic undertone (wit, mockery)\n" .
            "- playful: teasing, light (jokes, flirtation)\n" .
            "- cold: emotionless, detached (villains, formal)\n" .
            "- warm: friendly, loving (affection, encouragement)\n\n" .
            "TRANSLATION RULES:\n" .
            "- Translate MEANING, not word-by-word\n" .
            "- Use informal speech (talking to a friend)\n" .
            "- Keep it concise for dubbing\n" .
            $budgetRule .
            $examples .
            $contextBlock .
            $emotionArcBlock .
            $extra;
    }

    /**
     * Build emotional arc context from surrounding segments.
     * Returns a string describing the emotional flow for context.
     */
    private function buildEmotionArc($segmentsList, int $currentIndex): string
    {
        $emotionWindow = [];
        $count = $segmentsList->count();

        // Collect emotions from prev 2 and next 2 segments
        for ($i = max(0, $currentIndex - 2); $i <= min($count - 1, $currentIndex + 2); $i++) {
            $seg = $segmentsList[$i];
            $emotion = $seg->emotion ?? null;

            if ($i === $currentIndex) {
                $emotionWindow[] = '[CURRENT]';
            } elseif ($emotion) {
                $emotionWindow[] = $emotion;
            }
        }

        if (count($emotionWindow) <= 1) {
            return '';
        }

        return 'Scene emotional flow: ' . implode(' → ', $emotionWindow);
    }

    /**
     * Fix common "siz" forms that GPT keeps using despite instructions.
     * Converts formal forms to informal "sen" forms for natural dubbing.
     */
    private function fixSizForms(string $text): string
    {
        // Imperative -ng → informal form (word boundary aware)
        $imperatives = [
            '/\bso\'rang\b/ui' => "so'ra",
            '/\bkeling\b/ui' => 'kel',
            '/\bqarang\b/ui' => 'qara',
            '/\bayting\b/ui' => 'ayt',
            '/\bboring\b/ui' => 'bor',
            '/\bturing\b/ui' => "tur",
            '/\bchiqing\b/ui' => 'chiq',
            '/\boling\b/ui' => 'ol',
            '/\bbering\b/ui' => 'ber',
            '/\btinglang\b/ui' => 'tingla',
            '/\bo\'qing\b/ui' => "o'qi",
            '/\byozing\b/ui' => 'yoz',
            '/\bkuting\b/ui' => 'kut',
            '/\byuring\b/ui' => 'yur',
            '/\bo\'tiring\b/ui' => "o'tir",
            '/\bkiriting\b/ui' => 'kirit',
        ];

        foreach ($imperatives as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        // Verb -siz endings → -san (careful not to match nouns)
        // Only fix clear verb patterns
        $verbSiz = [
            '/(\w)asiz\b/ui' => '$1asan',
            '/(\w)aysiz\b/ui' => '$1aysan',
            '/(\w)isiz\b/ui' => '$1isan',
            '/(\w)osiz\b/ui' => '$1osan',
        ];

        foreach ($verbSiz as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        // Pronouns (word boundary)
        $text = preg_replace('/\bsizga\b/ui', 'senga', $text);
        $text = preg_replace('/\bsizni\b/ui', 'seni', $text);
        $text = preg_replace('/\bsizning\b/ui', 'sening', $text);

        // "siz" as standalone pronoun when it means "you" → "sen"
        // Be careful: "siz" could be part of compound words
        $text = preg_replace('/\bSiz\b/u', 'Sen', $text);
        $text = preg_replace('/\bsiz\b/u', 'sen', $text);

        // Fix "so'rov berish" → "so'rash" (more natural)
        $text = preg_replace('/so\'rov berish/ui', "so'rash", $text);
        $text = preg_replace('/so\'rov qilish/ui', "so'rash", $text);

        return $text;
    }

}
