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

                // Calculate slot duration and gap to next segment
                $slotDuration = ((float) $seg->end_time) - ((float) $seg->start_time);

                // Check if there's a gap after this segment (can extend into it if needed)
                $gapToNext = 0;
                if ($segIndex < $segmentsList->count() - 1) {
                    $nextSeg = $segmentsList[$segIndex + 1];
                    $gapToNext = max(0, ((float) $nextSeg->start_time) - ((float) $seg->end_time));
                }
                // Available time = slot + up to 0.5s of gap (don't eat too much into next segment's space)
                $availableTime = $slotDuration + min(0.5, $gapToNext);

                // Attempt up to 2 times if English leaks
                $translated = null;
                $emotion = 'neutral';
                $direction = 'normal';
                $detectedIntent = 'inform';
                $actingNote = null;
                $lastBody = null;

                for ($attempt = 1; $attempt <= 2; $attempt++) {
                    // Use availableTime (includes gap) for character budget guidance
                    $system = $this->buildSystemPrompt($targetLanguage, $attempt, $charCount, $prevText, $nextText, $availableTime, $emotionArc);

                    // Build messages with few-shot examples for better accuracy
                    $messages = [
                        ['role' => 'system', 'content' => $system],
                    ];

                    // Add few-shot examples with enhanced emotion, delivery, intent detection
                    // IMPORTANT: Use Unicode ʻ (U+02BB) for Uzbek oʻ and gʻ
                    if (str_contains($targetLanguage, 'Uzbek') || str_contains($targetLanguage, 'uz')) {
                        $messages[] = ['role' => 'user', 'content' => 'Ask.'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Soʻra.","e":"neutral","d":"normal","i":"command","n":"simple command"}'];

                        $messages[] = ['role' => 'user', 'content' => 'I can\'t believe this!'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Bunga ishonolmayman!","e":"surprise","d":"loud","i":"inform","n":"shocked, disbelief"}'];

                        $messages[] = ['role' => 'user', 'content' => 'Get out of here right now!'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Hoziroq yoʻqol bu yerdan!","e":"angry","d":"shout","i":"command","n":"furious, commanding"}'];

                        $messages[] = ['role' => 'user', 'content' => 'I miss you so much...'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Seni juda sogʻindim...","e":"sad","d":"soft","i":"confide","n":"tender, vulnerable"}'];

                        $messages[] = ['role' => 'user', 'content' => 'This is amazing! I love it!'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Bu ajoyib! Menga yoqdi!","e":"excited","d":"loud","i":"celebrate","n":"joyful, enthusiastic"}'];

                        $messages[] = ['role' => 'user', 'content' => 'Don\'t tell anyone, but...'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Hech kimga aytma, lekin...","e":"neutral","d":"whisper","i":"confide","n":"secretive, intimate"}'];

                        $messages[] = ['role' => 'user', 'content' => 'Oh, you think you\'re so smart, don\'t you?'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Oh, oʻzingni aqlli deb oʻylaysan, shundaymi?","e":"contempt","d":"matter_of_fact","i":"mock","n":"sarcastic, condescending"}'];

                        $messages[] = ['role' => 'user', 'content' => 'Please, I\'m begging you, don\'t do this!'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Iltimos, yolvoraman, bunday qilma!","e":"fear","d":"pleading","i":"plead","n":"desperate, trembling"}'];

                        $messages[] = ['role' => 'user', 'content' => 'You have no idea what I\'m capable of.'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Men nimaga qodirligimni bilmaysan.","e":"angry","d":"tense","i":"threaten","n":"cold, menacing"}'];

                        $messages[] = ['role' => 'user', 'content' => 'Everything will be okay, I promise.'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Hammasi yaxshi boʻladi, vaʻda beraman.","e":"tender","d":"soft","i":"comfort","n":"warm, reassuring"}'];

                        $messages[] = ['role' => 'user', 'content' => 'We did it! We actually did it!'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Uddaladik! Haqiqatan ham uddaladik!","e":"excited","d":"loud","i":"celebrate","n":"ecstatic, triumphant"}'];

                        $messages[] = ['role' => 'user', 'content' => 'There is no paper with my goals written.'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Maqsadlarim yozilgan qogʻoz yoʻq.","e":"neutral","d":"normal","i":"inform","n":"matter of fact"}'];
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

                    // Parse JSON response: {"t":"translation","e":"emotion","d":"delivery","i":"intent","n":"acting_note"}
                    $parsedText = $out;
                    $detectedEmotion = 'neutral';
                    $detectedDirection = 'normal';
                    $detectedIntent = 'inform';
                    $actingNote = null;

                    if (str_starts_with($out, '{') && str_contains($out, '"t"')) {
                        $parsed = json_decode($out, true);
                        if (is_array($parsed) && isset($parsed['t'])) {
                            $parsedText = trim($parsed['t']);
                            $detectedEmotion = strtolower(trim($parsed['e'] ?? 'neutral'));
                            $detectedDirection = strtolower(trim($parsed['d'] ?? 'normal'));
                            $detectedIntent = strtolower(trim($parsed['i'] ?? 'inform'));
                            $actingNote = trim($parsed['n'] ?? '');

                            // Validate emotion (expanded list)
                            $validEmotions = ['neutral', 'happy', 'sad', 'angry', 'fear', 'surprise', 'excited', 'tender', 'anxious', 'contempt', 'disgusted'];
                            if (!in_array($detectedEmotion, $validEmotions)) {
                                $detectedEmotion = 'neutral';
                            }

                            // Validate direction (expanded list)
                            $validDirections = [
                                'whisper', 'soft', 'normal', 'loud', 'shout',
                                'breathy', 'tense', 'trembling', 'strained', 'pleading', 'matter_of_fact',
                                'sarcastic', 'playful', 'cold', 'warm' // Legacy support
                            ];
                            if (!in_array($detectedDirection, $validDirections)) {
                                // Map legacy values
                                $detectedDirection = match($detectedDirection) {
                                    'sarcastic' => 'matter_of_fact',
                                    'playful' => 'normal',
                                    'cold' => 'tense',
                                    'warm' => 'soft',
                                    default => 'normal',
                                };
                            }

                            // Validate intent
                            $validIntents = ['inform', 'persuade', 'comfort', 'threaten', 'mock', 'confide', 'question', 'command', 'plead', 'accuse', 'apologize', 'celebrate', 'mourn'];
                            if (!in_array($detectedIntent, $validIntents)) {
                                $detectedIntent = 'inform';
                            }

                            Log::info('Acting direction detected', [
                                'segment_id' => $seg->id,
                                'emotion' => $detectedEmotion,
                                'delivery' => $detectedDirection,
                                'intent' => $detectedIntent,
                                'acting_note' => $actingNote,
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

                    // Log translation stats but DON'T truncate - preserve meaning!
                    // TTS can handle slight overflows by speaking faster
                    if ($slotDuration > 0) {
                        $actualChars = mb_strlen($parsedText);
                        $charsPerSec = $actualChars / $slotDuration;

                        Log::debug('Translation stats', [
                            'segment_id' => $seg->id,
                            'slot' => round($slotDuration, 2),
                            'chars' => $actualChars,
                            'chars_per_sec' => round($charsPerSec, 1),
                        ]);
                    }

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

                // Debug: Log what we're about to save
                Log::debug('About to update segment', [
                    'segment_id' => $seg->id,
                    'translated' => mb_substr($translated ?? '', 0, 30),
                    'emotion' => $emotion,
                    'direction' => $direction,
                    'detectedIntent' => $detectedIntent,
                    'actingNote' => $actingNote,
                ]);

                $seg->update([
                    'translated_text' => $this->normalizeUzbekApostrophes($translated),
                    'emotion' => $emotion,
                    'direction' => $direction,
                    'intent' => $detectedIntent ?? 'inform',
                    'acting_note' => $actingNote ?: null,
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
                "- Do NOT output English words.\n" .
                "- Use natural spoken phrases used by native speakers.\n" .
                "- Keep the FULL meaning of the original sentence.\n";
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

        // Time-aware character budget - VERY STRICT for dubbing sync
        // Edge TTS Uzbek speaks at ~10 chars/sec at normal speed
        // Use aggressive budgets to PREVENT sentence cutting:
        // - Very short slots (<1.5s): 6 chars/sec (room for TTS variation)
        // - Short slots (1.5-3s): 7 chars/sec
        // - Normal slots (3s+): 8 chars/sec
        // REALISTIC character budget - preserve meaning over strict timing!
        // Normal speaking rate: 10-12 chars/sec. Speaker can speed up slightly if needed.
        // NEVER cut sentences - better to speak faster than lose meaning.
        $budgetRule = '';
        $isUzbek = str_contains($targetLanguage, 'Uzbek') || str_contains($targetLanguage, 'uz');

        if ($slotDuration > 0) {
            // Realistic rates - these are GUIDES, not hard limits
            // TTS can handle 12-14 chars/sec by speaking faster
            $normalRate = $isUzbek ? 10 : 11;  // Normal comfortable pace
            $fastRate = $isUzbek ? 13 : 14;    // Fast but still clear

            $idealChars = (int) round($slotDuration * $normalRate);
            $maxChars = (int) round($slotDuration * $fastRate);

            $slotRounded = round($slotDuration, 1);

            if ($slotDuration < 1.5) {
                // Short slots - be concise but KEEP MEANING
                $budgetRule = "Time: {$slotRounded}s. Aim for ~{$idealChars} chars (max ~{$maxChars}).\n" .
                    "Be concise but PRESERVE FULL MEANING. Don't cut words.\n\n";
            } elseif ($slotDuration < 3.0) {
                $budgetRule = "Time: {$slotRounded}s. Target ~{$idealChars} chars.\n" .
                    "Translate naturally, preserving complete meaning.\n\n";
            } else {
                $budgetRule = "Time available: {$slotRounded}s (~{$idealChars} chars).\n\n";
            }
        }

        // Uzbek-specific translation rules
        $examples = '';
        if (str_contains($targetLanguage, 'Uzbek') || str_contains($targetLanguage, 'uz')) {
            $examples =
                "\n### MUHIM: OʻZBEK TARJIMA QOIDALARI ###\n\n" .
                "IMLO QOIDALARI - oʻ va gʻ harflarini TOʻGʻRI yozing:\n" .
                "- oʻ = o + ʻ (ALWAYS use: soʻra, toʻrt, oʻn, boʻldi, yoʻq, koʻp)\n" .
                "- gʻ = g + ʻ (ALWAYS use: gʻalati, bogʻliq, toʻgʻri, qogʻoz, togʻ)\n" .
                "- XATO: og, tog, bog, yoq, soq → TOʻGʻRI: oʻg, togʻ, bogʻ, yoʻq, soʻq\n" .
                "- Use the character ʻ (Unicode U+02BB) NOT regular apostrophe '\n\n" .
                "COMMON WORDS WITH oʻ:\n" .
                "- yoʻq (no/none), boʻldi (was), oʻzi (itself), koʻp (many), soʻra (ask)\n" .
                "- oʻqish (study), toʻrt (four), oʻn (ten), boʻsh (empty), yoʻl (road)\n\n" .
                "COMMON WORDS WITH gʻ:\n" .
                "- togʻ (mountain), bogʻ (garden), qogʻoz (paper), sogʻliq (health)\n" .
                "- toʻgʻri (correct), gʻalati (strange), bogʻliq (connected)\n\n" .
                "FEʻL SHAKLLARI - FAQAT NORASMIY \"SEN\" ISHLATILSIN:\n" .
                "- \"Ask\" = \"Soʻra\" (XATO: \"Soʻrang\")\n" .
                "- \"Listen\" = \"Tingla\" (XATO: \"Tinglang\")\n" .
                "- \"Come\" = \"Kel\" (XATO: \"Keling\")\n" .
                "- \"Look\" = \"Qara\" (XATO: \"Qarang\")\n" .
                "- \"Go\" = \"Bor\" yoki \"Ket\" (XATO: \"Boring\")\n\n" .
                "QOIDALAR:\n" .
                "1. MAʻNONI SAQLANG - inglizcha gap nimani anglatsa, oʻzbekcha ham shu maʻnoni bersin\n" .
                "2. NORASMIY SOʻZLASHING - doʻstingiz bilan gaplashgandek\n" .
                "3. QISQA BOʻLSIN - dublyaj uchun\n";
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
            "2. Detect the PRIMARY emotion from context, punctuation, and meaning\n" .
            "3. Detect the DELIVERY style (how to physically speak the line)\n" .
            "4. Detect the INTENT (what speaker is trying to achieve)\n" .
            "5. Return JSON format with ALL fields:\n" .
            "   {\"t\":\"translation\",\"e\":\"emotion\",\"d\":\"delivery\",\"i\":\"intent\",\"n\":\"acting_note\"}\n\n" .
            "EMOTIONS (primary feeling - choose one):\n" .
            "neutral, happy, sad, angry, fear, surprise, excited, tender, anxious, contempt\n\n" .
            "DELIVERY STYLES (physical voice quality - choose one):\n" .
            "- whisper: intimate, soft, breathy (secrets, romance, fear)\n" .
            "- soft: gentle, caring (comfort, tenderness)\n" .
            "- normal: standard conversational delivery\n" .
            "- loud: raised voice, emphasis (arguments, calls)\n" .
            "- shout: yelling, extreme (danger, rage)\n" .
            "- breathy: intimate, exhausted, romantic\n" .
            "- tense: barely controlled emotion, restraint\n" .
            "- trembling: voice shaking (fear, overwhelming emotion)\n" .
            "- strained: physical effort, pain\n" .
            "- pleading: desperate begging\n" .
            "- matter_of_fact: flat, emotionless exposition\n\n" .
            "INTENT (speaker's goal - choose one):\n" .
            "inform, persuade, comfort, threaten, mock, confide, question, command, plead, accuse, apologize, celebrate, mourn\n\n" .
            "ACTING NOTE (n): 3-5 word direction for voice actor. Examples:\n" .
            "- 'intensely angry, threatening'\n" .
            "- 'whispered, sharing a secret'\n" .
            "- 'sarcastic, mocking undertone'\n" .
            "- 'trembling with fear'\n" .
            "- 'tender, comforting a child'\n\n" .
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

    /**
     * Calculate maximum allowed characters for a given slot duration.
     * Uses same logic as prompt but for validation.
     */
    private function calculateMaxChars(float $slotDuration, string $targetLanguage): int
    {
        $isUzbek = str_contains($targetLanguage, 'Uzbek') || str_contains($targetLanguage, 'uz');

        if ($slotDuration < 1.0) {
            $charsPerSec = 5;
        } elseif ($slotDuration < 1.5) {
            $charsPerSec = 6;
        } elseif ($slotDuration < 3.0) {
            $charsPerSec = 7;
        } else {
            $charsPerSec = $isUzbek ? 8 : 9;
        }

        return max(3, (int) floor($slotDuration * $charsPerSec));
    }

    /**
     * Truncate text to fit within character budget, breaking at word boundaries.
     * Preserves meaning by keeping complete words and adding ellipsis if needed.
     */
    private function truncateToFit(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        // Try to break at sentence boundaries first
        $sentences = preg_split('/([.!?])\s+/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = '';
        for ($i = 0; $i < count($sentences) - 1; $i += 2) {
            $sentence = $sentences[$i] . ($sentences[$i + 1] ?? '');
            if (mb_strlen($result . $sentence) <= $maxChars) {
                $result .= $sentence . ' ';
            } else {
                break;
            }
        }

        $result = trim($result);
        if (mb_strlen($result) > 0 && mb_strlen($result) <= $maxChars) {
            return $result;
        }

        // Fall back to word boundary truncation
        $words = preg_split('/\s+/u', $text);
        $result = '';
        foreach ($words as $word) {
            $test = $result === '' ? $word : $result . ' ' . $word;
            if (mb_strlen($test) <= $maxChars) {
                $result = $test;
            } else {
                break;
            }
        }

        // If no words fit, take the first word truncated to maxChars
        // This ensures we always return SOMETHING meaningful
        if (mb_strlen(trim($result)) === 0 && count($words) > 0) {
            $result = mb_substr($words[0], 0, $maxChars);
        }

        return trim($result);
    }

    /**
     * Normalize Uzbek apostrophes to Unicode modifier letter turned comma (ʻ U+02BB).
     * This character is properly pronounced by Edge TTS Uzbek voices.
     */
    private function normalizeUzbekApostrophes(string $text): string
    {
        $apostropheVariants = [
            "'",   // U+0027 ASCII apostrophe
            "'",   // U+2019 Right single quotation mark
            "ʼ",   // U+02BC Modifier letter apostrophe
            "`",   // U+0060 Grave accent (backtick)
        ];

        return str_replace($apostropheVariants, "ʻ", $text);
    }

}
