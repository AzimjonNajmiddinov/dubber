<?php

namespace App\Jobs;

use App\Models\Speaker;
use App\Models\Video;
use App\Models\VideoSegment;
use App\Services\SpeakerTuning;
use App\Services\TextNormalizer;
use App\Services\TextToSpeech\NaturalSpeechProcessor;
use App\Services\Tts\TtsManager;
use App\Traits\DetectsEnglish;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Lightweight per-segment job: translate + TTS only.
 * Runs on 'chunks' queue for 4-worker parallelism.
 *
 * When all segments for a video have TTS, dispatches MixDubbedAudioJob.
 */
class ProcessSegmentTtsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use DetectsEnglish;

    public int $timeout = 300;
    public int $tries = 2;
    public int $uniqueFor = 300;

    private const TRANSLATION_CACHE_TTL = 86400;

    public function __construct(public int $segmentId) {}

    public function uniqueId(): string
    {
        return 'seg_tts_' . $this->segmentId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessSegmentTtsJob failed', [
            'segment_id' => $this->segmentId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function handle(): void
    {
        $segment = VideoSegment::with(['video', 'speaker'])->findOrFail($this->segmentId);
        $video = $segment->video;
        $speaker = $segment->speaker;

        if (!$video || !$speaker) {
            Log::warning('Segment missing video or speaker', ['segment_id' => $this->segmentId]);
            return;
        }

        Log::info('Processing segment TTS', [
            'segment_id' => $segment->id,
            'video_id' => $video->id,
            'speaker' => $speaker->external_key,
            'start' => $segment->start_time,
            'end' => $segment->end_time,
        ]);

        // 1. Apply speaker tuning if not yet done
        $this->ensureSpeakerTuned($video, $speaker);

        // 2. Translate
        if (empty($segment->translated_text)) {
            $translated = $this->translate($segment->text, $video->target_language);

            if (empty($translated)) {
                Log::warning('Translation returned empty', ['segment_id' => $segment->id]);
                $translated = $segment->text;
            }

            $segment->update(['translated_text' => $translated]);
        }

        // 3. Generate TTS
        if (empty($segment->tts_audio_path) && !empty($segment->translated_text)) {
            // Skip if translated text looks like untranslated English (for non-English targets)
            if ($this->looksLikeEnglish($segment->translated_text)
                && !in_array(strtolower($video->target_language), ['en', 'english'])) {
                Log::info('Skipping English-looking segment', [
                    'segment_id' => $segment->id,
                    'text' => mb_substr($segment->translated_text, 0, 50),
                ]);
            } else {
                $ttsPath = $this->generateTts($segment, $speaker, $video);
                if ($ttsPath) {
                    $segment->update(['tts_audio_path' => $ttsPath]);
                }
            }
        }

        Log::info('Segment TTS complete', [
            'segment_id' => $segment->id,
            'has_translation' => !empty($segment->translated_text),
            'has_tts' => !empty($segment->tts_audio_path),
        ]);

        // 4. Check if all segments are done
        $this->checkAllSegmentsDone($video);
    }

    /**
     * Ensure speaker has proper tuning applied (voice, pitch, rate, gain).
     */
    private function ensureSpeakerTuned(Video $video, Speaker $speaker): void
    {
        // Skip if already tuned (tts_rate is set by SpeakerTuning::applyDefaults)
        if ($speaker->tts_rate !== null) {
            return;
        }

        $tuning = app(SpeakerTuning::class);
        $tuning->applyDefaults($video, $speaker);
        $speaker->save();
    }

    /**
     * Translate segment text using OpenAI.
     */
    private function translate(string $text, string $targetLang): string
    {
        if (empty($text)) {
            return '';
        }

        // Check cache
        $cacheKey = 'translation:' . md5($text . ':' . $targetLang);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $langConfig = $this->getLanguageConfig($targetLang);
        $translated = $this->translateSingle($text, $langConfig);

        // Validate Uzbek grammar
        if (in_array(strtolower($targetLang), ['uz', 'uzbek']) && !empty($translated)) {
            $validated = $this->validateUzbekGrammar([$translated]);
            $translated = $validated[0] ?? $translated;
        }

        // Cache
        Cache::put($cacheKey, $translated, self::TRANSLATION_CACHE_TTL);

        return $translated;
    }

    private function getLanguageConfig(string $targetLang): array
    {
        $uzbekGrammarRules = <<<'RULES'

UZBEK GRAMMAR RULES (CRITICAL - follow exactly):
- FORMALITY CONSISTENCY: Determine formality from the source text.
  If source uses informal "ты/you" (casual), use "sen" forms EVERYWHERE:
    * Imperative: bare verb stem (e.g. o'yna, qara, ket, ol)
    * Present: -san (e.g. o'ynaysan, borasan)
    * Past: -ding (e.g. o'ynading, ko'rding)
  If source uses formal "Вы/You" (polite), use "siz" forms EVERYWHERE:
    * Imperative: stem + -ng (e.g. o'ynang, qarang, keting, oling)
    * Present: -siz (e.g. o'ynaysiz, borasiz)
    * Past: -dingiz (e.g. o'ynadingiz, ko'rdingiz)
  NEVER MIX: "sen" with "-ng" imperatives or "siz" with bare-stem imperatives.
- VERB CONJUGATION:
  Present tense: stem + -a/-y + person (men: -man, sen: -san, u: -di, biz: -miz, siz: -siz)
  Past tense: stem + -di + person (men: -m, sen: -ng, u: -Ø, biz: -k, siz: -ngiz)
  Imperative: sen = bare stem, siz = stem + -ng
- APOSTROPHES: Always use ASCII apostrophe (') in o', g', sh, ch combinations.
- EXAMPLES (informal/sen):
  "Играй своим камнем" → "O'z toshing bilan o'yna" (NOT o'ynang)
  "Посмотри на это" → "Bunga qara" (NOT qarang)
- EXAMPLES (formal/siz):
  "Играйте своим камнем" → "O'z toshingiz bilan o'ynang"
  "Посмотрите на это" → "Bunga qarang"
RULES;

        return match (strtolower($targetLang)) {
            'uz', 'uzbek' => [
                'name' => 'Uzbek',
                'model' => 'gpt-4o',
                'script' => 'Use ONLY Latin script (like: ko\'rganingiz, rahmat, yaxshi). NEVER use Cyrillic letters.',
                'grammar_rules' => $uzbekGrammarRules,
            ],
            'ru', 'russian' => [
                'name' => 'Russian',
                'model' => 'gpt-4o-mini',
                'script' => 'Use Cyrillic script.',
                'grammar_rules' => '',
            ],
            'en', 'english' => [
                'name' => 'English',
                'model' => 'gpt-4o-mini',
                'script' => '',
                'grammar_rules' => '',
            ],
            default => ['name' => $targetLang, 'model' => 'gpt-4o-mini', 'script' => '', 'grammar_rules' => ''],
        };
    }

    private function translateSingle(string $text, array $langConfig): string
    {
        if (empty($text)) return '';

        $apiKey = config('services.openai.key');
        if (!$apiKey) return $text;

        try {
            $grammarSection = !empty($langConfig['grammar_rules']) ? "\n{$langConfig['grammar_rules']}" : '';

            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $langConfig['model'],
                    'temperature' => 0.3,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "You are a professional movie dubbing translator. Translate to {$langConfig['name']}.\n\nRULES:\n- PRESERVE the exact meaning - do NOT lose any information\n- Use natural spoken {$langConfig['name']} expressions\n- Match the length closely for lip-sync\n- Output ONLY the translation, nothing else\n{$langConfig['script']}{$grammarSection}"
                        ],
                        ['role' => 'user', 'content' => $text],
                    ],
                ]);

            if ($response->successful()) {
                return trim($response->json('choices.0.message.content') ?? $text);
            }
        } catch (\Exception $e) {
            Log::warning('Translation failed', ['error' => $e->getMessage()]);
        }

        return $text;
    }

    private function validateUzbekGrammar(array $translations): array
    {
        if (empty($translations)) return $translations;

        $apiKey = config('services.openai.key');
        if (!$apiKey) return $translations;

        try {
            $numberedTexts = [];
            foreach ($translations as $i => $text) {
                $numberedTexts[] = "[" . ($i + 1) . "] " . $text;
            }

            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'temperature' => 0.1,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are an Uzbek grammar checker. Check the following Uzbek text lines for grammar errors. Focus ONLY on:
1. FORMALITY CONSISTENCY: If "sen" (informal) is used, ALL verbs must use informal forms (bare stem imperative, -san present, -ding past). If "siz" (formal), ALL verbs must use formal forms (-ng imperative, -siz present, -dingiz past). NEVER mix.
2. VERB SUFFIX CORRECTNESS: Ensure verb endings match the person and tense correctly.
3. Common error: imperative with -ng when subject is "sen" → fix to bare stem.

Output each line with its [N] number. If a line is correct, output it unchanged. If it has errors, output the corrected version.
Output ONLY the numbered lines, no explanations.',
                        ],
                        ['role' => 'user', 'content' => implode("\n", $numberedTexts)],
                    ],
                ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content') ?? '';
                return $this->parseBatchTranslations($content, count($translations), $translations);
            }
        } catch (\Exception $e) {
            Log::warning('Uzbek grammar validation failed', ['error' => $e->getMessage()]);
        }

        return $translations;
    }

    private function parseBatchTranslations(string $content, int $count, array $originals): array
    {
        $results = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (preg_match('/^\[(\d+)\]\s*(.+)$/u', trim($line), $matches)) {
                $idx = (int)$matches[1] - 1;
                if ($idx >= 0 && $idx < $count) {
                    $results[$idx] = trim($matches[2]);
                }
            }
        }

        for ($i = 0; $i < $count; $i++) {
            if (!isset($results[$i])) {
                $results[$i] = $originals[$i];
            }
        }

        ksort($results);
        return array_values($results);
    }

    /**
     * Generate TTS audio for a segment using the configured driver system.
     *
     * @return string|null Relative path to TTS audio file, or null on failure
     */
    private function generateTts(VideoSegment $segment, Speaker $speaker, Video $video): ?string
    {
        $text = $segment->translated_text;
        $emotion = $this->detectSegmentEmotion($segment, $speaker);
        $targetDuration = (float) $segment->end_time - (float) $segment->start_time;

        // Natural speech processing
        $naturalSpeech = new NaturalSpeechProcessor();
        $processedText = $naturalSpeech->process($text, $emotion);
        $processedText = TextNormalizer::normalize($processedText, $video->target_language);

        // Pre-calculate speed to fit time slot
        $speed = $this->calculateSpeed($processedText, $targetDuration);

        $options = [
            'emotion' => $emotion,
            'speed' => $speed,
            'language' => $video->target_language ?? 'uz',
            'gain_db' => (float) ($speaker->tts_gain_db ?? 0),
        ];

        if ($speaker->voice_cloned && $speaker->openvoice_speaker_key) {
            $options['voice_id'] = $speaker->openvoice_speaker_key;
        }

        $ttsManager = app(TtsManager::class);
        $fallbackDriverName = config('dubber.tts.fallback', 'edge');

        // Determine driver: cloned → hybrid_uzbek, otherwise configured default
        $driverName = ($speaker->voice_cloned && $speaker->openvoice_speaker_key)
            ? 'hybrid_uzbek'
            : ($speaker->getEffectiveTtsDriver() ?? config('dubber.tts.default', 'uzbekvoice'));

        $ttsPath = null;

        // Try primary driver
        try {
            if ($ttsManager->hasDriver($driverName)) {
                $ttsPath = $ttsManager->driver($driverName)->synthesize(
                    $processedText, $speaker, $segment, $options
                );
            }
        } catch (\Throwable $e) {
            Log::warning("TTS driver [{$driverName}] failed, trying fallback", [
                'segment_id' => $segment->id,
                'error' => $e->getMessage(),
            ]);
            $ttsPath = null;
        }

        // Fallback driver
        if (!$ttsPath || !file_exists($ttsPath)) {
            try {
                if ($ttsManager->hasDriver($fallbackDriverName)) {
                    $ttsPath = $ttsManager->driver($fallbackDriverName)->synthesize(
                        $processedText, $speaker, $segment, $options
                    );
                    $driverName = $fallbackDriverName;
                }
            } catch (\Throwable $e) {
                Log::error("Fallback TTS also failed for segment {$segment->id}", [
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        if (!$ttsPath || !file_exists($ttsPath)) {
            return null;
        }

        // Post-synthesis speed correction if audio exceeds slot
        $ttsDuration = $this->getAudioDuration($ttsPath);
        if ($ttsDuration > 0 && $targetDuration > 0 && $ttsDuration > $targetDuration * 1.1) {
            $speedRatio = min($ttsDuration / $targetDuration, 1.5);
            $adjustedPath = str_replace('.wav', '_adj.wav', $ttsPath);
            $this->adjustTtsSpeed($ttsPath, $adjustedPath, $speedRatio);

            if (file_exists($adjustedPath)) {
                @unlink($ttsPath);
                $ttsPath = $adjustedPath;
            }
        }

        // Convert to relative path for DB storage
        $storagePath = Storage::disk('local')->path('');
        $relativePath = str_replace($storagePath, '', $ttsPath);

        Log::info('TTS generated', [
            'segment_id' => $segment->id,
            'driver' => $driverName,
            'emotion' => $emotion,
            'speed' => $speed,
            'path' => $relativePath,
        ]);

        return $relativePath;
    }

    private function detectSegmentEmotion(VideoSegment $segment, Speaker $speaker): string
    {
        // Priority 1: Per-segment emotion from WhisperX
        if (!empty($segment->emotion) && $segment->emotion !== 'neutral') {
            return $segment->emotion;
        }

        // Priority 2: Speaker-level emotion
        if ($speaker->emotion && $speaker->emotion !== 'neutral'
            && ($speaker->emotion_confidence ?? 0) > 0.5) {
            return $speaker->emotion;
        }

        // Priority 3: Text-based heuristics
        return $this->detectEmotionFromText($segment->text ?? '');
    }

    private function detectEmotionFromText(string $text): string
    {
        $t = mb_strtolower($text);

        if (preg_match('/[!]{2,}|!!/', $text) ||
            preg_match('/\b(angry|furious|mad|hate|damn|hell|stop|shut up|get out)\b/i', $t)) {
            return 'angry';
        }

        if (preg_match('/[!]{1,}.*[!]|wow|yay|great|amazing|wonderful|fantastic|love|happy|glad|excited/i', $t)) {
            return 'happy';
        }

        if (preg_match('/\?{2,}|what\?|really\?|seriously\?/i', $t)) {
            return 'surprise';
        }

        if (preg_match('/\b(sad|sorry|miss|cry|tears|lost|gone|died|dead|unfortunately|regret)\b/i', $t)) {
            return 'sad';
        }

        if (preg_match('/\b(afraid|scared|fear|danger|help|run|careful|watch out|oh no)\b/i', $t)) {
            return 'fear';
        }

        if (substr_count($text, '!') >= 1) {
            return 'excited';
        }

        return 'neutral';
    }

    private function calculateSpeed(string $text, float $slotDuration): float
    {
        if ($slotDuration <= 0) {
            return 1.0;
        }

        $textLen = mb_strlen($text);
        $estimatedDuration = $textLen / 7.0;

        if ($estimatedDuration <= $slotDuration) {
            return 1.0;
        }

        $speedupFactor = $estimatedDuration / $slotDuration;
        return min(1.5, $speedupFactor);
    }

    private function adjustTtsSpeed(string $inputPath, string $outputPath, float $speedRatio): void
    {
        $atempo = min(max($speedRatio, 0.5), 2.0);

        Process::timeout(30)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $inputPath,
            '-af', "atempo={$atempo}",
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
            $outputPath,
        ]);
    }

    private function getAudioDuration(string $path): float
    {
        $result = Process::timeout(10)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);

        return (float) trim($result->output());
    }

    /**
     * Check if all segments for this video have TTS audio.
     * If so, dispatch MixDubbedAudioJob.
     */
    private function checkAllSegmentsDone(Video $video): void
    {
        $total = $video->segments()->count();
        $done = $video->segments()->whereNotNull('tts_audio_path')->count();

        Log::info('Segment completion check', [
            'video_id' => $video->id,
            'done' => $done,
            'total' => $total,
        ]);

        if ($done >= $total) {
            Log::info('All segments have TTS, dispatching mix', ['video_id' => $video->id]);
            MixDubbedAudioJob::dispatch($video->id);
        }
    }
}
