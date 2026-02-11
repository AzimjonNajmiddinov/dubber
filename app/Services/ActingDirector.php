<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * ActingDirector - Generates professional acting directions for dubbing.
 *
 * Analyzes text to determine:
 * 1. Primary emotion (what they feel)
 * 2. Delivery style (how they express it)
 * 3. Vocal quality (physical voice characteristics)
 * 4. Intent (what they're trying to achieve)
 * 5. Subtext (hidden meaning, sarcasm)
 * 6. Paralinguistic cues (sighs, laughs, pauses)
 */
class ActingDirector
{
    /**
     * All supported emotions with intensity levels.
     */
    public const EMOTIONS = [
        'neutral', 'happy', 'sad', 'angry', 'fear', 'surprise',
        'excited', 'disgusted', 'contempt', 'tender', 'anxious'
    ];

    /**
     * Delivery styles - HOW the line is physically spoken.
     */
    public const DELIVERY_STYLES = [
        'whisper',      // Intimate, soft, breathy
        'soft',         // Gentle, caring
        'normal',       // Standard delivery
        'loud',         // Raised voice, emphasis
        'shout',        // Yelling, extreme
        'breathy',      // Intimate, exhausted, romantic
        'tense',        // Barely controlled, restraint
        'trembling',    // Fear, cold, overwhelming emotion
        'strained',     // Physical exertion, pain
        'matter_of_fact', // Exposition, calm explanation
        'pleading',     // Desperation, begging
    ];

    /**
     * Speaking intents - WHAT the speaker is trying to achieve.
     */
    public const INTENTS = [
        'inform',       // Just sharing information
        'persuade',     // Trying to convince
        'comfort',      // Trying to soothe/support
        'threaten',     // Intimidating
        'mock',         // Making fun of
        'confide',      // Sharing a secret
        'question',     // Seeking information
        'command',      // Giving orders
        'plead',        // Begging
        'seduce',       // Flirting, romantic
        'accuse',       // Blaming
        'apologize',    // Expressing regret
        'celebrate',    // Expressing joy
        'mourn',        // Expressing grief
    ];

    /**
     * Analyze text and generate comprehensive acting direction.
     *
     * @param string $originalText Original text (source language)
     * @param string $translatedText Translated text (target language)
     * @param array $context Additional context (prev/next lines, scene info)
     * @return array{
     *     emotion: string,
     *     emotion_intensity: float,
     *     delivery: string,
     *     intent: string,
     *     vocal_quality: array,
     *     subtext: string|null,
     *     paralinguistics: array,
     *     acting_note: string
     * }
     */
    public function analyze(string $originalText, string $translatedText, array $context = []): array
    {
        $combined = mb_strtolower($originalText . ' ' . $translatedText);
        $original = trim($originalText);

        // Detect all components
        $emotion = $this->detectEmotion($combined, $original);
        $delivery = $this->detectDelivery($combined, $original, $emotion);
        $intent = $this->detectIntent($combined, $original, $context);
        $vocalQuality = $this->detectVocalQuality($combined, $original, $emotion, $delivery);
        $subtext = $this->detectSubtext($combined, $original, $context);
        $paralinguistics = $this->detectParalinguistics($combined, $original, $emotion);

        // Generate human-readable acting note
        $actingNote = $this->generateActingNote($emotion, $delivery, $intent, $vocalQuality, $subtext);

        return [
            'emotion' => $emotion['name'],
            'emotion_intensity' => $emotion['intensity'],
            'delivery' => $delivery,
            'intent' => $intent,
            'vocal_quality' => $vocalQuality,
            'subtext' => $subtext,
            'paralinguistics' => $paralinguistics,
            'acting_note' => $actingNote,
        ];
    }

    /**
     * Detect emotion with intensity scoring.
     */
    protected function detectEmotion(string $text, string $original): array
    {
        $scores = array_fill_keys(self::EMOTIONS, 0.0);

        // === PUNCTUATION ANALYSIS ===
        $exclamations = substr_count($original, '!');
        $questions = substr_count($original, '?');

        if ($exclamations >= 3) {
            $scores['angry'] += 3;
            $scores['excited'] += 3;
        } elseif ($exclamations >= 2) {
            $scores['angry'] += 2;
            $scores['excited'] += 2;
            $scores['surprise'] += 1;
        } elseif ($exclamations === 1) {
            $scores['excited'] += 1;
            $scores['happy'] += 0.5;
        }

        if ($questions >= 2) {
            $scores['surprise'] += 2;
            $scores['anxious'] += 1;
        }

        if (str_contains($original, '...') || str_contains($original, '…')) {
            $scores['sad'] += 1;
            $scores['anxious'] += 1;
            $scores['tender'] += 0.5;
        }

        // ALL CAPS
        if (preg_match('/\b[A-Z]{4,}\b/', $original)) {
            $scores['angry'] += 2;
            $scores['excited'] += 1;
        }

        // === EMOTION KEYWORDS (weighted by intensity) ===

        // ANGER
        if (preg_match('/\b(furious|rage|hate|loathe|despise|kill|murder)\b/i', $text)) $scores['angry'] += 5;
        if (preg_match('/\b(angry|mad|pissed|annoyed|frustrated|irritated)\b/i', $text)) $scores['angry'] += 3;
        if (preg_match('/\b(damn|hell|shut up|get out|stop it|enough|sick of)\b/i', $text)) $scores['angry'] += 2;

        // HAPPINESS
        if (preg_match('/\b(ecstatic|overjoyed|thrilled|delighted|elated)\b/i', $text)) $scores['happy'] += 5;
        if (preg_match('/\b(happy|joy|wonderful|amazing|fantastic|love it|perfect)\b/i', $text)) $scores['happy'] += 3;
        if (preg_match('/\b(glad|pleased|nice|good|smile|laugh|yay)\b/i', $text)) $scores['happy'] += 2;

        // SADNESS
        if (preg_match('/\b(devastated|heartbroken|grief|mourn|tragic|crushed)\b/i', $text)) $scores['sad'] += 5;
        if (preg_match('/\b(sad|sorry|miss|cry|tears|died|dead|lost|gone)\b/i', $text)) $scores['sad'] += 3;
        if (preg_match('/\b(unfortunately|regret|alone|lonely|pain|hurt)\b/i', $text)) $scores['sad'] += 2;

        // FEAR
        if (preg_match('/\b(terrified|horrified|petrified|panic|nightmare)\b/i', $text)) $scores['fear'] += 5;
        if (preg_match('/\b(afraid|scared|fear|terror|danger|threat|die)\b/i', $text)) $scores['fear'] += 3;
        if (preg_match('/\b(worried|nervous|anxious|careful|watch out|run|help)\b/i', $text)) $scores['fear'] += 2;

        // SURPRISE
        if (preg_match('/\b(shocked|astonished|stunned|incredible|unbelievable)\b/i', $text)) $scores['surprise'] += 4;
        if (preg_match('/\b(surprised|unexpected|what|really|can\'t believe)\b/i', $text)) $scores['surprise'] += 2;
        if (preg_match('/\b(wow|oh|whoa|no way|seriously|huh)\b/i', $text)) $scores['surprise'] += 2;

        // EXCITEMENT
        if (preg_match('/\b(yes|yeah|awesome|incredible|let\'s go|can\'t wait|finally)\b/i', $text)) $scores['excited'] += 3;
        if (preg_match('/\b(exciting|excited|thrilling|adventure|amazing)\b/i', $text)) $scores['excited'] += 2;

        // TENDER (love, care)
        if (preg_match('/\b(love you|darling|sweetheart|honey|dear|baby|precious)\b/i', $text)) $scores['tender'] += 4;
        if (preg_match('/\b(gentle|soft|care|protect|safe|hold you)\b/i', $text)) $scores['tender'] += 2;

        // DISGUST
        if (preg_match('/\b(disgusting|revolting|vile|sick|gross|pathetic)\b/i', $text)) $scores['disgusted'] += 4;
        if (preg_match('/\b(eww|ugh|yuck|nasty|horrible)\b/i', $text)) $scores['disgusted'] += 2;

        // CONTEMPT
        if (preg_match('/\b(pathetic|worthless|fool|idiot|stupid|moron)\b/i', $text)) $scores['contempt'] += 3;
        if (preg_match('/\b(beneath|don\'t deserve|waste|laughable)\b/i', $text)) $scores['contempt'] += 2;

        // ANXIETY
        if (preg_match('/\b(worried|anxious|nervous|stressed|can\'t breathe)\b/i', $text)) $scores['anxious'] += 3;
        if (preg_match('/\b(what if|might|maybe|hope not|please don\'t)\b/i', $text)) $scores['anxious'] += 2;

        // Find dominant emotion
        $maxScore = max($scores);
        $dominantEmotion = 'neutral';

        if ($maxScore >= 2) {
            $dominantEmotion = array_search($maxScore, $scores);
        }

        // Calculate intensity (0.0 - 1.0)
        $intensity = min(1.0, $maxScore / 6.0);
        if ($dominantEmotion === 'neutral') {
            $intensity = 0.3; // Neutral has low intensity
        }

        return [
            'name' => $dominantEmotion,
            'intensity' => round($intensity, 2),
            'scores' => $scores,
        ];
    }

    /**
     * Detect delivery style based on text and emotion.
     */
    protected function detectDelivery(string $text, string $original, array $emotion): string
    {
        // Physical cues in text
        if (preg_match('/\*whisper(s|ed|ing)?\*|\(whisper(s|ed|ing)?\)|\bwhisper(s|ed|ing)?\b/i', $text)) {
            return 'whisper';
        }
        if (preg_match('/\*shout(s|ed|ing)?\*|\(shout(s|ed|ing)?\)|\bscream(s|ed|ing)?\b/i', $text)) {
            return 'shout';
        }
        if (preg_match('/\*mutter(s|ed|ing)?\*|\(mutter(s|ed|ing)?\)/i', $text)) {
            return 'soft';
        }
        if (preg_match('/\bplease\b.*\bplease\b|\bI beg\b|\bI\'m begging\b/i', $text)) {
            return 'pleading';
        }

        // Multiple exclamations = loud or shout
        $exclamations = substr_count($original, '!');
        if ($exclamations >= 3) {
            return 'shout';
        }
        if ($exclamations >= 2 && $emotion['name'] === 'angry') {
            return 'shout';
        }
        if ($exclamations >= 2) {
            return 'loud';
        }

        // Emotion-based defaults
        return match($emotion['name']) {
            'angry' => $emotion['intensity'] > 0.7 ? 'shout' : 'loud',
            'fear' => $emotion['intensity'] > 0.7 ? 'trembling' : 'tense',
            'sad' => $emotion['intensity'] > 0.7 ? 'trembling' : 'soft',
            'tender' => 'soft',
            'anxious' => 'tense',
            'contempt' => 'matter_of_fact',
            default => 'normal',
        };
    }

    /**
     * Detect speaker intent.
     */
    protected function detectIntent(string $text, string $original, array $context): string
    {
        // Command detection
        if (preg_match('/^(go|come|run|stop|wait|look|listen|get|do|don\'t|never|leave)\b/i', trim($original))) {
            return 'command';
        }

        // Question = seeking information
        if (str_ends_with(trim($original), '?')) {
            // Rhetorical questions (sarcasm) are different
            if (preg_match('/\breally\?|\bseriously\?|\boh really/i', $text)) {
                return 'mock';
            }
            return 'question';
        }

        // Persuasion
        if (preg_match('/\byou should\b|\byou need to\b|\btrust me\b|\bbelieve me\b|\bthink about it\b/i', $text)) {
            return 'persuade';
        }
        if (preg_match('/\bwouldn\'t you\b|\bdon\'t you think\b|\bimagine\b/i', $text)) {
            return 'persuade';
        }

        // Comfort
        if (preg_match('/\bit\'s okay\b|\bit\'ll be\b|\bdon\'t worry\b|\bI\'m here\b|\beverything will\b/i', $text)) {
            return 'comfort';
        }

        // Threat
        if (preg_match('/\bor else\b|\byou\'ll regret\b|\bI\'ll kill\b|\bwatch your\b|\bI warn you\b/i', $text)) {
            return 'threaten';
        }
        if (preg_match('/\bif you don\'t\b.*\bwill\b/i', $text)) {
            return 'threaten';
        }

        // Confide (sharing secrets)
        if (preg_match('/\bdon\'t tell\b|\bbetween us\b|\bsecret\b|\bno one knows\b|\bjust between\b/i', $text)) {
            return 'confide';
        }

        // Apologize
        if (preg_match('/\bI\'m sorry\b|\bforgive me\b|\bmy fault\b|\bI apologize\b|\bI didn\'t mean\b/i', $text)) {
            return 'apologize';
        }

        // Accuse
        if (preg_match('/\byou did\b|\bit\'s your fault\b|\byou always\b|\byou never\b|\bhow could you\b/i', $text)) {
            return 'accuse';
        }

        // Plead
        if (preg_match('/\bplease\b.*\bplease\b|\bI beg\b|\bI\'m begging\b|\bjust this once\b/i', $text)) {
            return 'plead';
        }

        // Celebrate
        if (preg_match('/\bwe did it\b|\byes!\b|\bfinally\b|\bwe won\b|\bcongratulations\b/i', $text)) {
            return 'celebrate';
        }

        // Mourn
        if (preg_match('/\bhe\'s gone\b|\bshe\'s gone\b|\bI can\'t believe.*dead\b|\brest in peace\b/i', $text)) {
            return 'mourn';
        }

        return 'inform';
    }

    /**
     * Detect vocal quality characteristics.
     */
    protected function detectVocalQuality(string $text, string $original, array $emotion, string $delivery): array
    {
        $qualities = [];

        // Breathy - intimacy, exhaustion
        if ($delivery === 'whisper' || preg_match('/\bexhausted\b|\btired\b|\bout of breath\b/i', $text)) {
            $qualities[] = 'breathy';
        }

        // Tense - controlled anger, stress
        if ($delivery === 'tense' || $emotion['name'] === 'anxious') {
            $qualities[] = 'tense';
        }

        // Trembling - fear, overwhelming emotion
        if ($delivery === 'trembling' || preg_match('/\bshaking\b|\btrembling\b|\bcan\'t stop\b/i', $text)) {
            $qualities[] = 'trembling';
        }

        // Strained - physical exertion
        if (preg_match('/\bpain\b|\bhurts\b|\bcan\'t breathe\b|\bhelp me\b/i', $text)) {
            $qualities[] = 'strained';
        }

        // Creaky - tiredness, resignation
        if (preg_match('/\bgive up\b|\bwhat\'s the point\b|\bI don\'t care anymore\b/i', $text)) {
            $qualities[] = 'creaky';
        }

        // Nasal - crying, cold
        if ($emotion['name'] === 'sad' && $emotion['intensity'] > 0.6) {
            $qualities[] = 'nasal';
        }

        return $qualities;
    }

    /**
     * Detect subtext and sarcasm.
     */
    protected function detectSubtext(string $text, string $original, array $context): ?string
    {
        // Sarcasm markers
        $sarcasmPatterns = [
            '/\boh,? (sure|great|wonderful|perfect|fantastic)\b/i' => 'actually means the opposite',
            '/\byeah,? right\b/i' => 'expresses disbelief',
            '/\bof course\b.*!/i' => 'may be sarcastic',
            '/\bwow,? (what a|how)\b/i' => 'possibly sarcastic',
            '/\breally\?\s*$/i' => 'skeptical, may be sarcastic',
            '/\boh,? I\'m sure\b/i' => 'actually doubts',
            '/\bhow (nice|lovely|wonderful)\b/i' => 'may be contemptuous',
            '/\bvery (funny|clever|smart)\b/i' => 'possibly mocking',
        ];

        foreach ($sarcasmPatterns as $pattern => $meaning) {
            if (preg_match($pattern, $text)) {
                return $meaning;
            }
        }

        // Irony detection: positive words in negative context
        if (isset($context['prev_text'])) {
            $prevText = mb_strtolower($context['prev_text']);
            // If previous text was negative and current is overly positive
            if (preg_match('/\b(terrible|awful|bad|wrong|failed|lost)\b/', $prevText) &&
                preg_match('/\b(great|wonderful|perfect|amazing)\b/', $text)) {
                return 'ironic response to bad news';
            }
        }

        return null;
    }

    /**
     * Detect paralinguistic cues (sighs, laughs, etc.).
     */
    protected function detectParalinguistics(string $text, string $original, array $emotion): array
    {
        $cues = [];

        // Explicit markers
        if (preg_match('/\*sigh(s)?\*|\(sigh(s)?\)/i', $text)) {
            $cues[] = ['type' => 'sigh', 'position' => 'start'];
        }
        if (preg_match('/\*laugh(s)?\*|\(laugh(s)?\)|haha|hehe|lol/i', $text)) {
            $cues[] = ['type' => 'laugh', 'position' => 'inline'];
        }
        if (preg_match('/\*gasp(s)?\*|\(gasp(s)?\)/i', $text)) {
            $cues[] = ['type' => 'gasp', 'position' => 'start'];
        }
        if (preg_match('/\*sob(s)?\*|\(sob(s)?\)|sob(s|bed|bing)?/i', $text)) {
            $cues[] = ['type' => 'sob', 'position' => 'inline'];
        }
        if (preg_match('/\*cough(s)?\*|\(cough(s)?\)/i', $text)) {
            $cues[] = ['type' => 'cough', 'position' => 'start'];
        }

        // Implicit from emotion
        if ($emotion['name'] === 'sad' && $emotion['intensity'] > 0.7 && !str_contains(json_encode($cues), 'sob')) {
            $cues[] = ['type' => 'shaky_breath', 'position' => 'start'];
        }
        if ($emotion['name'] === 'fear' && $emotion['intensity'] > 0.5) {
            $cues[] = ['type' => 'quick_breath', 'position' => 'start'];
        }
        if ($emotion['name'] === 'angry' && $emotion['intensity'] > 0.6 && strlen($original) > 50) {
            $cues[] = ['type' => 'heavy_breath', 'position' => 'end'];
        }

        // Add natural breath for long sentences (>60 chars)
        if (mb_strlen($original) > 60 && empty(array_filter($cues, fn($c) => str_contains($c['type'], 'breath')))) {
            $cues[] = ['type' => 'breath', 'position' => 'start'];
        }

        return $cues;
    }

    /**
     * Generate human-readable acting note.
     */
    protected function generateActingNote(array $emotion, string $delivery, string $intent, array $vocalQuality, ?string $subtext): string
    {
        $parts = [];

        // Emotion with intensity
        $intensityWord = match(true) {
            $emotion['intensity'] > 0.8 => 'intensely',
            $emotion['intensity'] > 0.5 => 'clearly',
            $emotion['intensity'] > 0.3 => 'subtly',
            default => 'with hint of',
        };

        if ($emotion['name'] !== 'neutral') {
            $parts[] = "{$intensityWord} {$emotion['name']}";
        }

        // Delivery style
        if ($delivery !== 'normal') {
            $deliveryDescriptions = [
                'whisper' => 'whispered, intimate',
                'soft' => 'gentle, soft',
                'loud' => 'raised voice',
                'shout' => 'shouting',
                'breathy' => 'breathy, intimate',
                'tense' => 'tense, restrained',
                'trembling' => 'voice trembling',
                'strained' => 'strained, effortful',
                'matter_of_fact' => 'matter-of-fact, flat',
                'pleading' => 'pleading, desperate',
            ];
            $parts[] = $deliveryDescriptions[$delivery] ?? $delivery;
        }

        // Intent
        if (!in_array($intent, ['inform', 'question'])) {
            $intentDescriptions = [
                'persuade' => 'trying to convince',
                'comfort' => 'trying to soothe',
                'threaten' => 'threatening',
                'mock' => 'mocking',
                'confide' => 'confiding a secret',
                'command' => 'commanding',
                'plead' => 'begging',
                'accuse' => 'accusing',
                'apologize' => 'apologizing',
                'celebrate' => 'celebrating',
                'mourn' => 'mourning',
            ];
            if (isset($intentDescriptions[$intent])) {
                $parts[] = $intentDescriptions[$intent];
            }
        }

        // Subtext
        if ($subtext) {
            $parts[] = "({$subtext})";
        }

        // Vocal qualities
        if (!empty($vocalQuality)) {
            $parts[] = 'voice: ' . implode(', ', $vocalQuality);
        }

        if (empty($parts)) {
            return 'neutral delivery';
        }

        return implode('; ', $parts);
    }

    /**
     * Map acting direction to TTS-compatible parameters.
     */
    public function mapToTtsParams(array $direction): array
    {
        $params = [
            'rate_adjust' => 0,      // -20 to +20 percent
            'pitch_adjust' => 0,     // Hz adjustment (minimal for consistency)
            'volume_adjust' => 0,    // -50 to +50 percent
            'breathiness' => 0,      // 0 to 1
            'tension' => 0,          // 0 to 1
            'tremolo' => 0,          // 0 to 1
        ];

        // Delivery-based adjustments
        $deliveryParams = [
            'whisper'   => ['rate_adjust' => -10, 'volume_adjust' => -40, 'breathiness' => 0.8],
            'soft'      => ['rate_adjust' => -5,  'volume_adjust' => -20, 'breathiness' => 0.3],
            'loud'      => ['rate_adjust' => 3,   'volume_adjust' => 15],
            'shout'     => ['rate_adjust' => 5,   'volume_adjust' => 30, 'tension' => 0.6],
            'breathy'   => ['rate_adjust' => -8,  'volume_adjust' => -15, 'breathiness' => 0.7],
            'tense'     => ['rate_adjust' => -3,  'tension' => 0.7],
            'trembling' => ['tremolo' => 0.5, 'tension' => 0.3],
            'strained'  => ['rate_adjust' => -5,  'tension' => 0.8],
            'pleading'  => ['rate_adjust' => 5,   'volume_adjust' => -5, 'tremolo' => 0.2],
        ];

        $delivery = $direction['delivery'] ?? 'normal';
        if (isset($deliveryParams[$delivery])) {
            $params = array_merge($params, $deliveryParams[$delivery]);
        }

        // Emotion-based adjustments (rate and volume only - not pitch!)
        $emotion = $direction['emotion'] ?? 'neutral';
        $intensity = $direction['emotion_intensity'] ?? 0.5;

        $emotionParams = [
            'happy'    => ['rate_adjust' => 5 * $intensity,  'volume_adjust' => 5 * $intensity],
            'excited'  => ['rate_adjust' => 8 * $intensity,  'volume_adjust' => 8 * $intensity],
            'sad'      => ['rate_adjust' => -8 * $intensity, 'volume_adjust' => -5 * $intensity],
            'angry'    => ['rate_adjust' => 3 * $intensity,  'volume_adjust' => 10 * $intensity, 'tension' => 0.5 * $intensity],
            'fear'     => ['rate_adjust' => 5 * $intensity,  'volume_adjust' => -3 * $intensity, 'tremolo' => 0.3 * $intensity],
            'anxious'  => ['rate_adjust' => 3 * $intensity,  'tension' => 0.4 * $intensity],
            'tender'   => ['rate_adjust' => -5 * $intensity, 'volume_adjust' => -10 * $intensity, 'breathiness' => 0.3 * $intensity],
        ];

        if (isset($emotionParams[$emotion])) {
            foreach ($emotionParams[$emotion] as $key => $value) {
                $params[$key] += $value;
            }
        }

        // Clamp values
        $params['rate_adjust'] = max(-20, min(20, round($params['rate_adjust'])));
        $params['volume_adjust'] = max(-50, min(50, round($params['volume_adjust'])));
        $params['breathiness'] = max(0, min(1, $params['breathiness']));
        $params['tension'] = max(0, min(1, $params['tension']));
        $params['tremolo'] = max(0, min(1, $params['tremolo']));

        return $params;
    }
}
