<?php

namespace App\Jobs;

use App\Models\Speaker;
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
                ->with('speaker')
                ->where('video_id', $video->id)
                ->orderBy('start_time')
                ->get(['id', 'speaker_id', 'text', 'translated_text', 'start_time', 'end_time']);

            // Load all speakers for context building
            $speakers = Speaker::where('video_id', $video->id)->get()->keyBy('id');

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

            $makeClient = fn () => Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(90);

            $segmentCount = 0;

            foreach ($segments as $seg) {
                $src = trim((string) $seg->text);

                if ($src === '') {
                    $seg->update(['translated_text' => '']);
                    continue;
                }

                // Find surrounding context with speaker info
                $segIndex = $segmentsList->search(fn ($s) => $s->id === $seg->id);

                // Build scene context with speaker labels, gender, age
                $sceneContext = $this->buildSceneContext($segmentsList, $segIndex, $speakers);

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
                $formality = 'sen';
                $lastBody = null;

                for ($attempt = 1; $attempt <= 2; $attempt++) {
                    // Use availableTime (includes gap) for character budget guidance
                    $system = $this->buildSystemPrompt($targetLanguage, $attempt, $charCount, $sceneContext, $availableTime, $emotionArc);

                    // Build messages with few-shot examples for better accuracy
                    $messages = [
                        ['role' => 'system', 'content' => $system],
                    ];

                    // Add few-shot examples with enhanced emotion, delivery, intent detection
                    // IMPORTANT: Use Unicode ʻ (U+02BB) for Uzbek oʻ and gʻ
                    if (str_contains($targetLanguage, 'Uzbek') || str_contains($targetLanguage, 'uz')) {
                        // Informal "sen" examples (friends, peers, elder→child)
                        $messages[] = ['role' => 'user', 'content' => 'Ask.'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Soʻra.","e":"neutral","d":"normal","i":"command","n":"simple command","f":"sen"}'];

                        $messages[] = ['role' => 'user', 'content' => 'I can\'t believe this!'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Bunga ishonolmayman!","e":"surprise","d":"loud","i":"inform","n":"shocked, disbelief","f":"sen"}'];

                        $messages[] = ['role' => 'user', 'content' => 'Get out of here right now!'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Hoziroq yoʻqol bu yerdan!","e":"angry","d":"shout","i":"command","n":"furious, commanding","f":"sen"}'];

                        $messages[] = ['role' => 'user', 'content' => 'I miss you so much...'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Seni juda sogʻindim...","e":"sad","d":"soft","i":"confide","n":"tender, vulnerable","f":"sen"}'];

                        $messages[] = ['role' => 'user', 'content' => 'Don\'t tell anyone, but...'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Hech kimga aytma, lekin...","e":"neutral","d":"whisper","i":"confide","n":"secretive, intimate","f":"sen"}'];

                        $messages[] = ['role' => 'user', 'content' => 'Oh, you think you\'re so smart, don\'t you?'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Oh, oʻzingni aqlli deb oʻylaysan, shundaymi?","e":"contempt","d":"matter_of_fact","i":"mock","n":"sarcastic, condescending","f":"sen"}'];

                        $messages[] = ['role' => 'user', 'content' => 'You have no idea what I\'m capable of.'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Men nimaga qodirligimni bilmaysan.","e":"angry","d":"tense","i":"threaten","n":"cold, menacing","f":"sen"}'];

                        $messages[] = ['role' => 'user', 'content' => 'Everything will be okay, I promise.'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Hammasi yaxshi boʻladi, vaʻda beraman.","e":"tender","d":"soft","i":"comfort","n":"warm, reassuring","f":"sen"}'];

                        $messages[] = ['role' => 'user', 'content' => 'There is no paper with my goals written.'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Maqsadlarim yozilgan qogʻoz yoʻq.","e":"neutral","d":"normal","i":"inform","n":"matter of fact","f":"sen"}'];

                        // Formal "siz" examples (child→elder, stranger, authority)
                        $messages[] = ['role' => 'user', 'content' => 'Grandpa, please tell me a story.'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Buva, iltimos menga ertak aytib bering.","e":"tender","d":"soft","i":"plead","n":"child asking gently","f":"siz"}'];

                        $messages[] = ['role' => 'user', 'content' => 'Doctor, will he be okay?'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Doktor, u tuzaladimi?","e":"anxious","d":"trembling","i":"question","n":"worried, seeking comfort","f":"siz"}'];

                        $messages[] = ['role' => 'user', 'content' => 'Excuse me, could you help me?'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Kechirasiz, menga yordam bera olasizmi?","e":"neutral","d":"soft","i":"plead","n":"polite request to stranger","f":"siz"}'];

                        $messages[] = ['role' => 'user', 'content' => 'Please, I\'m begging you, don\'t do this!'];
                        $messages[] = ['role' => 'assistant', 'content' => '{"t":"Iltimos, yolvoraman, bunday qilmang!","e":"fear","d":"pleading","i":"plead","n":"desperate, trembling","f":"siz"}'];
                    }

                    $messages[] = ['role' => 'user', 'content' => $src];

                    $res = null;
                    for ($apiRetry = 1; $apiRetry <= 6; $apiRetry++) {
                        $res = $makeClient()->post('https://api.openai.com/v1/chat/completions', [
                            'model' => 'gpt-4o',
                            'temperature' => 0.1,
                            'messages' => $messages,
                        ]);

                        if ($res->successful()) {
                            break;
                        }

                        if ($res->status() === 429) {
                            $retryAfter = $res->header('Retry-After');
                            $wait = ($retryAfter && is_numeric($retryAfter))
                                ? (int) $retryAfter + 1
                                : min(5 * pow(2, $apiRetry - 1), 120);

                            Log::warning('OpenAI 429 rate limit, waiting', [
                                'segment_id' => $seg->id,
                                'api_retry' => $apiRetry,
                                'wait_seconds' => $wait,
                            ]);
                            sleep((int) $wait);
                            continue;
                        }

                        // Non-429 error — fail immediately
                        break;
                    }

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
                            $audioSource = strtolower(trim($parsed['s'] ?? 'direct'));

                            // Validate audio source
                            $validAudioSources = ['direct', 'phone', 'tv', 'voiceover'];
                            if (!in_array($audioSource, $validAudioSources)) {
                                $audioSource = 'direct';
                            }

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

                            // Parse formality field
                            $formality = strtolower(trim($parsed['f'] ?? ''));
                            if (!in_array($formality, ['sen', 'siz'])) {
                                $formality = 'sen'; // default informal
                            }

                            Log::info('Acting direction detected', [
                                'segment_id' => $seg->id,
                                'emotion' => $detectedEmotion,
                                'delivery' => $detectedDirection,
                                'intent' => $detectedIntent,
                                'acting_note' => $actingNote,
                                'formality' => $formality,
                                'audio_source' => $audioSource,
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
                    $res = $makeClient()->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o',
                        'temperature' => 0.0,
                        'messages' => [
                            ['role' => 'system', 'content' => $this->buildSystemPrompt($targetLanguage, 3, $charCount, $sceneContext, $slotDuration)],
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
                    'formality' => $formality ?? 'sen',
                    'audio_source' => $audioSource ?? 'direct',
                ]);

                $segmentCount++;

                // Pace requests: short delay every segment, longer pause every 20
                if ($segmentCount % 20 === 0) {
                    usleep(2_000_000); // 2s pause every 20 segments
                } else {
                    usleep(200_000); // 200ms between segments
                }
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

    private function buildSystemPrompt(string $targetLanguage, int $attempt, int $charCount = 0, string $sceneContext = '', float $slotDuration = 0, string $emotionArc = ''): string
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

        // Scene context block (includes speaker labels, gender, age)
        $contextBlock = '';
        if ($sceneContext !== '') {
            $contextBlock = "\nSCENE CONTEXT (use to determine formality and natural flow, do NOT translate these):\n" .
                $sceneContext . "\n";
        }

        // Time-aware character budget - STRICT for dubbing sync
        // Uzbek is ~20-30% longer than English, so use tighter rates
        $budgetRule = '';
        $isUzbek = str_contains($targetLanguage, 'Uzbek') || str_contains($targetLanguage, 'uz');

        if ($slotDuration > 0) {
            $normalRate = $isUzbek ? 9 : 10;

            $idealChars = (int) round($slotDuration * $normalRate);

            $slotRounded = round($slotDuration, 1);

            $budgetRule = "Time slot: {$slotRounded}s. Aim for ~{$idealChars} chars. Be concise but NEVER drop meaning.\n\n";
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
                "RASMIYLIK / FORMALITY (\"f\" field in JSON):\n" .
                "Analyze the SCENE CONTEXT to determine relationship between speakers:\n" .
                "- Friends, peers, same age → \"sen\" (informal): Soʻra, Tingla, Kel, Qara, Bor\n" .
                "- Child → Elder (parent, grandpa, teacher, doctor) → \"siz\" (formal): Soʻrang, Tinglang, Keling, Qarang, Boring\n" .
                "- Strangers, first meeting → \"siz\" (formal)\n" .
                "- To authority figures (boss, police, judge) → \"siz\" (formal)\n" .
                "- Elder → Child, Boss → Employee → \"sen\" (informal)\n\n" .
                "QOIDALAR:\n" .
                "1. MAʻNONI SAQLANG - inglizcha gap nimani anglatsa, oʻzbekcha ham shu maʻnoni bersin\n" .
                "2. RASMIYLIKNI SAQLANG - kontekstga qarab \"sen\" yoki \"siz\" ishlating\n" .
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
            "5. Determine FORMALITY (sen/siz) from speaker relationships in scene context\n" .
            "6. Detect AUDIO SOURCE — how this voice reaches the audience\n" .
            "7. Return JSON format with ALL fields:\n" .
            "   {\"t\":\"translation\",\"e\":\"emotion\",\"d\":\"delivery\",\"i\":\"intent\",\"n\":\"acting_note\",\"f\":\"sen_or_siz\",\"s\":\"audio_source\"}\n\n" .
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
            "AUDIO SOURCE (s): How this voice reaches the audience — choose one:\n" .
            "- direct: speaker is physically present in the scene (DEFAULT — most lines)\n" .
            "- phone: voice heard through a phone/video call (other end of conversation)\n" .
            "- tv: voice from a TV, radio, PA system, or loudspeaker\n" .
            "- voiceover: narration, inner thoughts, or off-screen commentary\n" .
            "Context clues: phone ringing, 'hello?', one-sided conversation, 'on the phone',\n" .
            "TV news, radio broadcast, announcement. If unsure, use 'direct'.\n\n" .
            "ACTING NOTE (n): 3-5 word direction for voice actor. Examples:\n" .
            "- 'intensely angry, threatening'\n" .
            "- 'whispered, sharing a secret'\n" .
            "- 'sarcastic, mocking undertone'\n" .
            "- 'trembling with fear'\n" .
            "- 'tender, comforting a child'\n\n" .
            "FORMALITY (f): \"sen\" or \"siz\" based on speaker relationship in scene context.\n\n" .
            "TRANSLATION RULES:\n" .
            "- Translate MEANING, not word-by-word\n" .
            "- NEVER omit meaning, context, or nuance to save characters. Full meaning is more important than brevity.\n" .
            "- Match formality to speaker relationship (see scene context)\n" .
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
     * Build scene context string showing surrounding segments with speaker info.
     * Shows 5 previous + 2 next segments with speaker labels, gender, age.
     */
    private function buildSceneContext($segmentsList, int $currentIndex, $speakers): string
    {
        $lines = [];
        $count = $segmentsList->count();

        $start = max(0, $currentIndex - 5);
        $end = min($count - 1, $currentIndex + 2);

        for ($i = $start; $i <= $end; $i++) {
            $seg = $segmentsList[$i];
            $text = trim((string) $seg->text);
            if ($text === '') continue;

            // Get speaker info
            $speakerLabel = 'UNKNOWN';
            $speakerInfo = '';
            if ($seg->speaker_id && isset($speakers[$seg->speaker_id])) {
                $speaker = $speakers[$seg->speaker_id];
                $speakerLabel = $speaker->label ?: ('SPEAKER_' . $speaker->id);
                $parts = [];
                if ($speaker->gender) $parts[] = $speaker->gender;
                if ($speaker->age_group) $parts[] = $speaker->age_group;
                if (!empty($parts)) {
                    $speakerInfo = ' (' . implode(', ', $parts) . ')';
                }
            } elseif ($seg->speaker) {
                $speaker = $seg->speaker;
                $speakerLabel = $speaker->label ?: ('SPEAKER_' . $speaker->id);
                $parts = [];
                if ($speaker->gender) $parts[] = $speaker->gender;
                if ($speaker->age_group) $parts[] = $speaker->age_group;
                if (!empty($parts)) {
                    $speakerInfo = ' (' . implode(', ', $parts) . ')';
                }
            }

            $marker = ($i === $currentIndex) ? ' [CURRENT LINE]' : '';
            $lines[] = "{$speakerLabel}{$speakerInfo}: \"{$text}\"{$marker}";
        }

        return implode("\n", $lines);
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
