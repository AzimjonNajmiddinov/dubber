<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoSegment;
use App\Models\Speaker;
use App\Services\TextNormalizer;
use App\Services\Tts\TtsManager;
use App\Services\TextToSpeech\NaturalSpeechProcessor;
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
 * Optimized video dubbing with:
 * - Parallel TTS generation
 * - Translation caching
 * - Early silence detection (skip processing)
 * - Reduced FFmpeg passes
 * - Immediate streaming support
 */
class ProcessVideoChunkJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use DetectsEnglish;

    public int $timeout = 600;
    public int $tries = 2;
    public int $uniqueFor = 600;

    // Translation cache TTL (24 hours)
    private const TRANSLATION_CACHE_TTL = 86400;

    // Voices per language
    private const VOICES = [
        'uz' => [
            'male' => ['uz-UZ-SardorNeural'],
            'female' => ['uz-UZ-MadinaNeural'],
        ],
        'ru' => [
            'male' => ['ru-RU-DmitryNeural', 'ru-RU-DmitryNeural'],
            'female' => ['ru-RU-SvetlanaNeural', 'ru-RU-DariyaNeural'],
        ],
        'en' => [
            'male' => ['en-US-GuyNeural', 'en-US-ChristopherNeural', 'en-US-EricNeural'],
            'female' => ['en-US-AriaNeural', 'en-US-JennyNeural', 'en-US-MichelleNeural'],
        ],
    ];

    private const PITCH_VARIATIONS = ['+0Hz', '-3Hz', '+4Hz', '-2Hz', '+2Hz', '-4Hz'];
    private const RATE_VARIATIONS = ['+0%', '-5%', '+5%', '-3%', '+3%'];

    public function __construct(
        public int $videoId,
        public int $chunkIndex,
        public float $startTime,
        public float $endTime
    ) {}

    public function uniqueId(): string
    {
        return "chunk_{$this->videoId}_{$this->chunkIndex}";
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessVideoChunkJob failed', [
            'video_id' => $this->videoId,
            'chunk' => $this->chunkIndex,
            'error' => $exception->getMessage(),
        ]);
    }

    public function handle(): void
    {
        $video = Video::find($this->videoId);
        if (!$video || !$video->original_path) {
            return;
        }

        $videoPath = Storage::disk('local')->path($video->original_path);
        if (!file_exists($videoPath)) {
            return;
        }

        $duration = $this->endTime - $this->startTime;

        Log::info('Processing chunk', [
            'video_id' => $this->videoId,
            'chunk' => $this->chunkIndex,
            'start' => $this->startTime,
            'duration' => $duration,
        ]);

        $chunkDir = "videos/chunks/{$this->videoId}";
        Storage::disk('local')->makeDirectory($chunkDir);
        $chunkDirAbs = Storage::disk('local')->path($chunkDir);

        // OPTIMIZATION 1: Combined audio extraction (single FFmpeg pass for both HQ and transcription)
        $audioHqPath = "{$chunkDirAbs}/audio_hq_{$this->chunkIndex}.wav";
        $audioTranscribePath = "{$chunkDirAbs}/audio_{$this->chunkIndex}.wav";
        $this->extractAudioCombined($videoPath, $audioHqPath, $audioTranscribePath, $this->startTime, $duration);

        // OPTIMIZATION 2: Early transcription to detect silence BEFORE stem separation
        $transcription = $this->transcribe($audioTranscribePath);
        $segments = $transcription['segments'];
        $speakerMeta = $transcription['speaker_meta'];

        if (empty($segments)) {
            Log::info('No speech in chunk - fast path', ['chunk' => $this->chunkIndex]);
            // OPTIMIZATION 3: Skip stem separation for silent chunks
            $this->createSilentChunk($video, $videoPath, $chunkDir, $duration);
            $this->cleanup([$audioHqPath, $audioTranscribePath]);
            $this->checkAndTriggerCombine($video);
            return;
        }

        // Now do stem separation (only for chunks with speech)
        // Returns both background and vocals for voice cloning
        $stemResults = $this->separateStems($audioHqPath, $chunkDirAbs);
        $bgMusicPath = $stemResults['bg'];
        $vocalsPath = $stemResults['vocals'];

        // OPTIMIZATION 4: Batch translation with caching + voice cloning
        $processedSegments = $this->processSegmentsBatched($video, $segments, $vocalsPath, $speakerMeta);

        // OPTIMIZATION 5: Parallel TTS generation
        $ttsFiles = $this->generateAllTTSParallel($video, $processedSegments, $chunkDirAbs);

        // Create final audio
        $finalAudioPath = $this->createFinalAudio($ttsFiles, $bgMusicPath, $chunkDirAbs, $duration);

        // Create video chunk
        $outputPath = "{$chunkDir}/seg_{$this->chunkIndex}.mp4";
        $this->createVideoChunk($videoPath, $finalAudioPath, $outputPath, $this->startTime, $duration);

        // Save segments
        $this->saveSegments($video, $processedSegments);

        // Cleanup
        $this->cleanup([$audioHqPath, $audioTranscribePath, $bgMusicPath, $vocalsPath, $finalAudioPath]);
        foreach ($ttsFiles as $tts) {
            if (isset($tts['path'])) @unlink($tts['path']);
        }

        Log::info('Chunk complete', [
            'video_id' => $this->videoId,
            'chunk' => $this->chunkIndex,
            'segments' => count($processedSegments),
            'has_background' => $bgMusicPath !== null,
            'has_vocals' => $vocalsPath !== null,
        ]);

        // Check if all chunks are done and trigger combine
        $this->checkAndTriggerCombine($video);
    }

    private function checkAndTriggerCombine(Video $video): void
    {
        $videoPath = Storage::disk('local')->path($video->original_path);
        $result = Process::timeout(10)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $videoPath,
        ]);

        $duration = (float) trim($result->output());
        if ($duration <= 0) return;

        $chunkDuration = $duration <= 60 ? 8 : ($duration <= 300 ? 10 : ($duration <= 1800 ? 12 : 15));
        $expectedChunks = (int) ceil($duration / $chunkDuration);

        // Count ready chunks
        $chunkDir = "videos/chunks/{$video->id}";
        $readyChunks = 0;
        $index = 0;
        while (Storage::disk('local')->exists("{$chunkDir}/seg_{$index}.mp4")) {
            $readyChunks++;
            $index++;
        }

        Log::info('Chunk completion check', [
            'video_id' => $video->id,
            'ready' => $readyChunks,
            'expected' => $expectedChunks,
        ]);

        if ($readyChunks >= $expectedChunks) {
            Log::info('All chunks ready - dispatching combine job', ['video_id' => $video->id]);
            CombineChunksJob::dispatch($video->id)->onQueue('chunks');
        }
    }

    /**
     * OPTIMIZATION: Extract both HQ and transcription audio in single FFmpeg call
     */
    private function extractAudioCombined(string $videoPath, string $hqPath, string $transcribePath, float $start, float $duration): void
    {
        // Single FFmpeg call with multiple outputs
        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -ss %s -i %s -t %s ' .
            '-map 0:a -ac 2 -ar 44100 -c:a pcm_s16le %s ' .
            '-map 0:a -ac 1 -ar 16000 -c:a pcm_s16le %s 2>&1',
            escapeshellarg((string)$start),
            escapeshellarg($videoPath),
            escapeshellarg((string)$duration),
            escapeshellarg($hqPath),
            escapeshellarg($transcribePath)
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            // Fallback to separate extractions
            $this->extractAudioHQ($videoPath, $hqPath, $start, $duration);
            $this->extractAudioForTranscription($videoPath, $transcribePath, $start, $duration);
        }
    }

    private function extractAudioHQ(string $videoPath, string $outputPath, float $start, float $duration): void
    {
        Process::timeout(60)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-ss', (string)$start,
            '-i', $videoPath,
            '-t', (string)$duration,
            '-vn', '-ac', '2', '-ar', '44100', '-c:a', 'pcm_s16le',
            $outputPath,
        ]);
    }

    private function extractAudioForTranscription(string $videoPath, string $outputPath, float $start, float $duration): void
    {
        Process::timeout(60)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-ss', (string)$start,
            '-i', $videoPath,
            '-t', (string)$duration,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le',
            $outputPath,
        ]);
    }

    private function cleanup(array $files): void
    {
        foreach ($files as $f) {
            if ($f && file_exists($f)) @unlink($f);
        }
    }

    /**
     * Create chunk for silent segments - skip all heavy processing
     */
    private function createSilentChunk(Video $video, string $videoPath, string $chunkDir, float $duration): void
    {
        $outputPath = "{$chunkDir}/seg_{$this->chunkIndex}.mp4";
        $outputAbs = Storage::disk('local')->path($outputPath);

        // Just copy video with reduced audio volume (fast, no stem separation needed)
        Process::timeout(60)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-ss', (string)$this->startTime,
            '-i', $videoPath,
            '-t', (string)$duration,
            '-c:v', 'copy',
            '-af', 'volume=0.3',
            '-c:a', 'aac', '-b:a', '96k',
            '-movflags', '+faststart',
            $outputAbs,
        ]);

        VideoSegment::create([
            'video_id' => $video->id,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'text' => '',
            'translated_text' => '',
        ]);
    }

    /**
     * Separate stems and return both background and vocals paths.
     * @return array{bg: ?string, vocals: ?string}
     */
    private function separateStems(string $audioPath, string $outputDir): array
    {
        $result = ['bg' => null, 'vocals' => null];

        if (!file_exists($audioPath)) {
            return $result;
        }

        $duration = $this->endTime - $this->startTime;
        $localPath = "{$outputDir}/bg_music_{$this->chunkIndex}.wav";

        // Strategy 1: Try to extract from full stems (FASTEST)
        $fullStemsResult = $this->tryExtractFromFullStems($localPath, $duration);
        if ($fullStemsResult) {
            $result['bg'] = $fullStemsResult;
            // Also extract vocals from full stems if available
            $vocalsPath = $this->extractVocalsFromFullStems($outputDir, $duration);
            $result['vocals'] = $vocalsPath;
            return $result;
        }

        // Strategy 2: Use segment-based separation
        $segmentResult = $this->trySeparateSegment($audioPath, $outputDir, $localPath, $duration);
        if ($segmentResult) {
            $result['bg'] = $segmentResult['bg'] ?? null;
            $result['vocals'] = $segmentResult['vocals'] ?? null;
            return $result;
        }

        // Strategy 3: Fallback - use original audio with volume reduction
        $result['bg'] = $this->createReducedVolumeBackground($audioPath, $localPath);
        return $result;
    }

    /**
     * Extract vocals segment from full stems (for voice cloning).
     */
    private function extractVocalsFromFullStems(string $outputDir, float $duration): ?string
    {
        try {
            $stemsDir = Storage::disk('local')->path("audio/stems/{$this->videoId}");
            $vocalsSource = null;

            foreach (['.wav', '.flac'] as $ext) {
                $candidate = "{$stemsDir}/vocals{$ext}";
                if (file_exists($candidate)) {
                    $vocalsSource = $candidate;
                    break;
                }
            }

            if (!$vocalsSource) return null;

            $vocalsPath = "{$outputDir}/vocals_{$this->chunkIndex}.wav";

            $result = Process::timeout(30)->run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-ss', (string)$this->startTime,
                '-i', $vocalsSource,
                '-t', (string)$duration,
                '-ar', '44100', '-ac', '2', '-c:a', 'pcm_s16le',
                $vocalsPath,
            ]);

            if ($result->successful() && file_exists($vocalsPath) && filesize($vocalsPath) > 1000) {
                return $vocalsPath;
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return null;
    }

    /**
     * Fallback: Create background by reducing original audio volume
     * Much faster than stem separation when it fails
     */
    private function createReducedVolumeBackground(string $audioPath, string $outputPath): ?string
    {
        $result = Process::timeout(30)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $audioPath,
            '-af', 'volume=0.15,lowpass=f=8000',
            '-c:a', 'pcm_s16le',
            $outputPath,
        ]);

        if ($result->successful() && file_exists($outputPath)) {
            Log::info('Using reduced volume fallback', ['chunk' => $this->chunkIndex]);
            return $outputPath;
        }

        return null;
    }

    private function tryExtractFromFullStems(string $localPath, float $duration): ?string
    {
        try {
            $demucsUrl = config('services.demucs.url', 'http://demucs:8000');
            $checkResponse = Http::timeout(5)->get("{$demucsUrl}/stems-ready/{$this->videoId}");
            if (!$checkResponse->successful() || !($checkResponse->json()['ready'] ?? false)) {
                return null;
            }

            $response = Http::timeout(30)->post("{$demucsUrl}/extract-from-stems", [
                'video_id' => $this->videoId,
                'start_time' => $this->startTime,
                'duration' => $duration,
                'chunk_index' => $this->chunkIndex,
            ]);

            if (!$response->successful() || !($response->json()['ok'] ?? false)) {
                return null;
            }

            $noVocalsRel = $response->json()['no_vocals_rel'] ?? null;
            if (!$noVocalsRel) return null;

            $noVocalsPath = Storage::disk('local')->path($noVocalsRel);
            if (!file_exists($noVocalsPath) || filesize($noVocalsPath) < 500) {
                return null;
            }

            if ($noVocalsPath !== $localPath) {
                copy($noVocalsPath, $localPath);
                @unlink($noVocalsPath);
            }

            Log::info('Extracted from full stems (fast path)', ['chunk' => $this->chunkIndex]);
            return $localPath;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @return array{bg: ?string, vocals: ?string}|null
     */
    private function trySeparateSegment(string $audioPath, string $outputDir, string $localPath, float $duration): ?array
    {
        try {
            $originalAudioRel = "audio/original/{$this->videoId}.wav";
            if (!Storage::disk('local')->exists($originalAudioRel)) {
                return null;
            }

            $demucsUrl = config('services.demucs.url', 'http://demucs:8000');
            $response = Http::timeout(120)->post("{$demucsUrl}/separate-segment", [
                'video_id' => $this->videoId,
                'input_rel' => $originalAudioRel,
                'start_time' => $this->startTime,
                'duration' => $duration,
                'model' => 'htdemucs',
                'two_stems' => 'vocals',
            ]);

            if (!$response->successful() || !($response->json()['ok'] ?? false)) {
                return null;
            }

            $noVocalsRel = $response->json()['no_vocals_rel'] ?? null;
            $vocalsRel = $response->json()['vocals_rel'] ?? null;

            if (!$noVocalsRel) return null;

            $noVocalsPath = Storage::disk('local')->path($noVocalsRel);
            if (!file_exists($noVocalsPath) || filesize($noVocalsPath) < 500) {
                return null;
            }

            copy($noVocalsPath, $localPath);

            // Get vocals path for voice cloning
            $vocalsPath = null;
            if ($vocalsRel) {
                $vocalsPath = Storage::disk('local')->path($vocalsRel);
                if (!file_exists($vocalsPath) || filesize($vocalsPath) < 1000) {
                    $vocalsPath = null;
                }
            }

            Log::info('Segment separation successful', ['chunk' => $this->chunkIndex]);
            return ['bg' => $localPath, 'vocals' => $vocalsPath];

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Transcribe audio and return segments with optional speaker metadata.
     * @return array{segments: array, speaker_meta: array}
     */
    private function transcribe(string $audioPath): array
    {
        $result = $this->transcribeWhisperX($audioPath);
        if (!empty($result['segments'])) return $result;

        $text = $this->transcribeOpenAI($audioPath);
        if (!empty($text)) {
            return [
                'segments' => $this->splitTextIntoSegments($text, $this->endTime - $this->startTime),
                'speaker_meta' => [],
            ];
        }

        return ['segments' => [], 'speaker_meta' => []];
    }

    /**
     * @return array{segments: array, speaker_meta: array}
     */
    private function transcribeWhisperX(string $audioPath): array
    {
        try {
            $whisperxUrl = config('services.whisperx.url', 'http://whisperx:8000');

            // Try path-based first (shared filesystem), fall back to file upload (remote)
            $audioRel = str_replace(Storage::disk('local')->path(''), '', $audioPath);
            $response = Http::timeout(120)->post("{$whisperxUrl}/analyze", [
                'audio_path' => $audioRel,
            ]);

            // If path-based fails (404 = file not found on remote), try file upload
            if ($response->status() === 404 && file_exists($audioPath)) {
                Log::info('WhisperX path not found, using file upload', [
                    'audio_path' => $audioRel,
                ]);
                $response = Http::timeout(180)
                    ->attach('audio', file_get_contents($audioPath), basename($audioPath))
                    ->post("{$whisperxUrl}/analyze-upload");
            }

            if ($response->failed()) {
                Log::warning('WhisperX request failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
                return ['segments' => [], 'speaker_meta' => []];
            }

            // Check for error payload
            if ($response->json('error')) {
                Log::warning('WhisperX returned error', [
                    'error' => $response->json('error'),
                    'message' => $response->json('message'),
                ]);
                return ['segments' => [], 'speaker_meta' => []];
            }

            $segments = $response->json()['segments'] ?? [];
            $speakerMeta = $response->json()['speakers'] ?? [];

            $parsedSegments = collect($segments)
                ->map(fn($seg) => [
                    'start' => (float)($seg['start'] ?? 0),
                    'end' => (float)($seg['end'] ?? 0),
                    'text' => trim($seg['text'] ?? ''),
                    'speaker' => $seg['speaker'] ?? 'SPEAKER_00',
                    'emotion' => $seg['emotion'] ?? null,
                    'emotion_confidence' => $seg['emotion_confidence'] ?? null,
                ])
                ->filter(fn($s) => !empty($s['text']) && $s['end'] > $s['start'])
                ->values()
                ->all();

            Log::info('WhisperX transcription result', [
                'segments' => count($parsedSegments),
                'speakers' => count($speakerMeta),
            ]);

            return ['segments' => $parsedSegments, 'speaker_meta' => $speakerMeta];

        } catch (\Exception $e) {
            Log::error('WhisperX transcription exception', ['error' => $e->getMessage()]);
            return ['segments' => [], 'speaker_meta' => []];
        }
    }

    private function transcribeOpenAI(string $audioPath): string
    {
        $apiKey = config('services.openai.key');
        if (!$apiKey) return '';

        try {
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->attach('file', file_get_contents($audioPath), 'audio.wav')
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'response_format' => 'text',
                ]);

            return $response->successful() ? trim($response->body()) : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * OPTIMIZATION: Process segments with batched translations and caching
     */
    private function processSegmentsBatched(Video $video, array $segments, ?string $vocalsPath = null, array $speakerMeta = []): array
    {
        $processed = [];
        $speakerCache = [];
        $textsToTranslate = [];
        $segmentIndices = [];
        $xttsAvailable = $this->isXttsAvailable();

        // Collect all texts that need translation
        foreach ($segments as $i => $seg) {
            $speakerKey = $seg['speaker'];
            if (!isset($speakerCache[$speakerKey])) {
                $meta = $speakerMeta[$speakerKey] ?? [];
                $speaker = $this->getOrCreateSpeaker($video, $speakerKey, $meta);
                $speakerCache[$speakerKey] = $speaker;

                // Attempt voice cloning for new speakers (if XTTS available and not already cloned)
                if ($xttsAvailable && !$speaker->voice_cloned && $vocalsPath) {
                    $this->extractAndCloneVoice($video, $speaker, $seg, $vocalsPath);
                    $speaker->refresh(); // Reload to get updated voice_cloned status
                    $speakerCache[$speakerKey] = $speaker;
                }
            }

            $cacheKey = $this->getTranslationCacheKey($seg['text'], $video->target_language);
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                $processed[$i] = [
                    'start' => $seg['start'],
                    'end' => $seg['end'],
                    'duration' => $seg['end'] - $seg['start'],
                    'text' => $seg['text'],
                    'translated' => $cached,
                    'speaker' => $speakerCache[$speakerKey],
                    'emotion' => $this->detectSegmentEmotion($seg, $speakerCache[$speakerKey]),
                ];
            } else {
                $textsToTranslate[$i] = $seg['text'];
                $segmentIndices[] = $i;
            }
        }

        // Batch translate remaining texts
        if (!empty($textsToTranslate)) {
            $translations = $this->translateBatch(array_values($textsToTranslate), $video->target_language);

                // Validate Uzbek grammar on all new translations as a batch
            $isUzbek = in_array(strtolower($video->target_language), ['uz', 'uzbek']);
            if ($isUzbek && !empty($translations)) {
                $translations = $this->validateUzbekGrammar($translations);
            }

            foreach ($segmentIndices as $idx => $segmentIndex) {
                $originalText = $textsToTranslate[$segmentIndex];
                $translated = $translations[$idx] ?? $originalText;

                // Cache the translation
                $cacheKey = $this->getTranslationCacheKey($originalText, $video->target_language);
                Cache::put($cacheKey, $translated, self::TRANSLATION_CACHE_TTL);

                $seg = $segments[$segmentIndex];
                $speakerKey = $seg['speaker'];

                $processed[$segmentIndex] = [
                    'start' => $seg['start'],
                    'end' => $seg['end'],
                    'duration' => $seg['end'] - $seg['start'],
                    'text' => $seg['text'],
                    'translated' => $translated,
                    'speaker' => $speakerCache[$speakerKey],
                    'emotion' => $this->detectSegmentEmotion($seg, $speakerCache[$speakerKey]),
                ];
            }
        }

        // Sort by index to maintain order
        ksort($processed);
        return array_values($processed);
    }

    private function getTranslationCacheKey(string $text, string $lang): string
    {
        return 'translation:' . md5($text . ':' . $lang);
    }

    /**
     * OPTIMIZATION: Batch translate multiple texts in single API call
     */
    private function translateBatch(array $texts, string $targetLang): array
    {
        if (empty($texts)) return [];

        $uzbekGrammarRules = <<<'RULES'

UZBEK GRAMMAR RULES (CRITICAL - follow exactly):
- FORMALITY CONSISTENCY: Determine formality from the source text.
  If source uses informal "ты/you" (casual), use "sen" forms EVERYWHERE:
    * Imperative: bare verb stem (e.g. o'yna, qara, ket, ol)
    * Present: -san (e.g. o'ynaysan, borasan)
    * Past: -ng NEVER, use -Ø or -ng only for siz. For sen past: -ding (e.g. o'ynading, ko'rding)
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
  "Иди сюда" → "Bu yoqqa kel" (NOT keling)
- EXAMPLES (formal/siz):
  "Играйте своим камнем" → "O'z toshingiz bilan o'ynang"
  "Посмотрите на это" → "Bunga qarang"
  "Идите сюда" → "Bu yoqqa keling"
RULES;

        $langConfig = match (strtolower($targetLang)) {
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

        $apiKey = config('services.openai.key');
        if (!$apiKey) return $texts;

        // For small batches (1-3 texts), use individual translation
        if (count($texts) <= 3) {
            return array_map(fn($t) => $this->translateSingle($t, $langConfig), $texts);
        }

        // Batch translation for larger sets
        try {
            $numberedTexts = [];
            foreach ($texts as $i => $text) {
                $numberedTexts[] = "[" . ($i + 1) . "] " . $text;
            }

            $grammarSection = !empty($langConfig['grammar_rules']) ? "\n{$langConfig['grammar_rules']}" : '';

            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $langConfig['model'],
                    'temperature' => 0.3,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "You are a PROFESSIONAL MOVIE DUBBING TRANSLATOR. Translate each numbered line to {$langConfig['name']}.

CRITICAL RULES:
1. Keep the [N] numbering format in output
2. PRESERVE MEANING: Each translation MUST convey the EXACT same meaning as the original - do NOT lose any information
3. NATURAL SPEECH: Use natural {$langConfig['name']} expressions and conversational flow - this is for voice actors to speak
4. Translate ALL words - NO English/foreign words (except proper names)
5. Match original length and rhythm closely for lip-sync
6. Preserve emotion and tone - if angry, stay angry; if joking, keep humor
7. {$langConfig['script']}{$grammarSection}"
                        ],
                        ['role' => 'user', 'content' => implode("\n", $numberedTexts)],
                    ],
                ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content') ?? '';
                return $this->parseBatchTranslations($content, count($texts), $texts);
            }
        } catch (\Exception $e) {
            Log::warning('Batch translation failed', ['error' => $e->getMessage()]);
        }

        // Fallback to individual translations
        return array_map(fn($t) => $this->translateSingle($t, $langConfig), $texts);
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

        // Fill in missing translations with originals
        for ($i = 0; $i < $count; $i++) {
            if (!isset($results[$i])) {
                $results[$i] = $originals[$i];
            }
        }

        ksort($results);
        return array_values($results);
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

    /**
     * Post-translation grammar validation for Uzbek.
     * Checks formality consistency and fixes verb suffix mismatches.
     */
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
                $validated = $this->parseBatchTranslations($content, count($translations), $translations);

                Log::info('Uzbek grammar validation applied', [
                    'count' => count($translations),
                    'changes' => count(array_filter(array_map(
                        fn($orig, $val) => $orig !== $val,
                        $translations,
                        $validated
                    ))),
                ]);

                return $validated;
            }
        } catch (\Exception $e) {
            Log::warning('Uzbek grammar validation failed', ['error' => $e->getMessage()]);
        }

        return $translations;
    }

    /**
     * Detect emotion for a segment using WhisperX audio data, speaker-level emotion, and text heuristics.
     */
    private function detectSegmentEmotion(array $segment, Speaker $speaker): string
    {
        // Priority 1: Per-segment emotion from WhisperX (if confidence > 0.5)
        if (!empty($segment['emotion']) && $segment['emotion'] !== 'neutral'
            && ($segment['emotion_confidence'] ?? 0) > 0.5) {
            return $segment['emotion'];
        }

        // Priority 2: Speaker-level emotion from WhisperX (if confidence > 0.5)
        if ($speaker->emotion && $speaker->emotion !== 'neutral'
            && ($speaker->emotion_confidence ?? 0) > 0.5) {
            return $speaker->emotion;
        }

        // Priority 3: Text-based emotion detection
        return $this->detectEmotionFromText($segment['text'] ?? '', '');
    }

    /**
     * Detect emotion from text using heuristics (punctuation, keywords).
     * Copied from GenerateTtsSegmentsJobV2.
     */
    private function detectEmotionFromText(string $originalText, string $translatedText): string
    {
        $text = mb_strtolower($originalText . ' ' . $translatedText);

        // Angry indicators
        if (preg_match('/[!]{2,}|!!/', $originalText) ||
            preg_match('/\b(angry|furious|mad|hate|damn|hell|stop|shut up|get out)\b/i', $text)) {
            return 'angry';
        }

        // Excited/Happy indicators
        if (preg_match('/[!]{1,}.*[!]|wow|yay|great|amazing|wonderful|fantastic|love|happy|glad|excited/i', $text)) {
            return 'happy';
        }

        // Question with surprise
        if (preg_match('/\?{2,}|what\?|really\?|seriously\?/i', $text)) {
            return 'surprise';
        }

        // Sad indicators
        if (preg_match('/\b(sad|sorry|miss|cry|tears|lost|gone|died|dead|unfortunately|regret)\b/i', $text)) {
            return 'sad';
        }

        // Fear indicators
        if (preg_match('/\b(afraid|scared|fear|danger|help|run|careful|watch out|oh no)\b/i', $text)) {
            return 'fear';
        }

        // Excitement from exclamation marks
        if (substr_count($originalText, '!') >= 1) {
            return 'excited';
        }

        return 'neutral';
    }

    private function getOrCreateSpeaker(Video $video, string $speakerKey, array $whisperxMeta = []): Speaker
    {
        $speaker = Speaker::where('video_id', $video->id)
            ->where('external_key', $speakerKey)
            ->first();

        if ($speaker) {
            // Update with WhisperX metadata if we have new data and speaker lacks it
            if (!empty($whisperxMeta) && !$speaker->pitch_median_hz) {
                $speaker->update(array_filter([
                    'gender' => $whisperxMeta['gender'] ?? null,
                    'gender_confidence' => $whisperxMeta['gender_confidence'] ?? null,
                    'emotion' => $whisperxMeta['emotion'] ?? null,
                    'emotion_confidence' => $whisperxMeta['emotion_confidence'] ?? null,
                    'pitch_median_hz' => $whisperxMeta['pitch_median_hz'] ?? null,
                    'age_group' => $whisperxMeta['age_group'] ?? null,
                ], fn($v) => $v !== null));
                $speaker->refresh();
            }
            return $speaker;
        }

        preg_match('/(\d+)/', $speakerKey, $matches);
        $num = (int)($matches[1] ?? 0);

        // Use WhisperX-detected gender, fall back to alternating guess
        $gender = $whisperxMeta['gender'] ?? ($num % 2 === 0 ? 'male' : 'female');

        $lang = strtolower($video->target_language);
        $voices = self::VOICES[$lang] ?? self::VOICES['en'];
        $genderVoices = $voices[$gender] ?? $voices['male'];

        $existingCount = Speaker::where('video_id', $video->id)->where('gender', $gender)->count();
        $voice = $genderVoices[$existingCount % count($genderVoices)];
        $pitch = self::PITCH_VARIATIONS[$existingCount % count(self::PITCH_VARIATIONS)];
        $rate = self::RATE_VARIATIONS[$existingCount % count(self::RATE_VARIATIONS)];

        try {
            return Speaker::create(array_filter([
                'video_id' => $video->id,
                'external_key' => $speakerKey,
                'label' => 'Speaker ' . ($num + 1),
                'gender' => $gender,
                'gender_confidence' => $whisperxMeta['gender_confidence'] ?? null,
                'emotion' => $whisperxMeta['emotion'] ?? 'neutral',
                'emotion_confidence' => $whisperxMeta['emotion_confidence'] ?? null,
                'pitch_median_hz' => $whisperxMeta['pitch_median_hz'] ?? null,
                'age_group' => $whisperxMeta['age_group'] ?? 'unknown',
                'tts_voice' => $voice,
                'tts_pitch' => $pitch,
                'tts_rate' => $rate,
                'tts_driver' => 'edge',
            ], fn($v) => $v !== null));
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                return Speaker::where('video_id', $video->id)
                    ->where('external_key', $speakerKey)
                    ->firstOrFail();
            }
            throw $e;
        }
    }

    /**
     * Extract voice sample and clone voice for a speaker using XTTS.
     * This captures the original speaker's voice characteristics.
     * Uses segment-level vocals from chunk processing (more reliable than full stems).
     */
    private function extractAndCloneVoice(Video $video, Speaker $speaker, array $segment, ?string $vocalsPath = null): bool
    {
        // Skip if already cloned
        if ($speaker->voice_cloned && $speaker->xtts_voice_id) {
            return true;
        }

        // Need at least 3 seconds of speech for good cloning
        $segmentDuration = $segment['end'] - $segment['start'];
        if ($segmentDuration < 3.0) {
            return false;
        }

        try {
            // Find vocals file - either passed directly or find from stems directory
            $sampleAbsPath = null;

            if ($vocalsPath && file_exists($vocalsPath)) {
                // Use the vocals file from current chunk's stem separation
                $sampleAbsPath = $vocalsPath;
            } else {
                // Try to find segment vocals in stems directory
                $stemsDir = Storage::disk('local')->path("audio/stems/{$video->id}");
                $startMs = (int)(($this->startTime + $segment['start']) * 1000);

                // Look for vocals chunk file
                $candidates = glob("{$stemsDir}/vocals_chunk_{$video->id}_*.wav");
                foreach ($candidates as $candidate) {
                    if (file_exists($candidate) && filesize($candidate) > 10000) {
                        $sampleAbsPath = $candidate;
                        break;
                    }
                }
            }

            if (!$sampleAbsPath || !file_exists($sampleAbsPath) || filesize($sampleAbsPath) < 10000) {
                Log::info('No vocals sample available for cloning', [
                    'speaker' => $speaker->external_key,
                ]);
                return false;
            }

            // Prepare voice sample (clean and trim to optimal length)
            $samplesDir = Storage::disk('local')->path("audio/voice_samples/{$video->id}");
            @mkdir($samplesDir, 0755, true);
            $cleanSamplePath = "{$samplesDir}/speaker_{$speaker->id}_sample.wav";

            // Extract clean voice sample (6-10 seconds optimal for XTTS)
            $duration = min($segmentDuration, 10.0);
            $result = Process::timeout(30)->run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-i', $sampleAbsPath,
                '-t', (string)$duration,
                '-af', 'highpass=f=80,lowpass=f=12000,afftdn=nf=-25,volume=1.5',
                '-ar', '22050', '-ac', '1', '-c:a', 'pcm_s16le',
                $cleanSamplePath,
            ]);

            if (!$result->successful() || !file_exists($cleanSamplePath) || filesize($cleanSamplePath) < 5000) {
                return false;
            }

            // Clone voice using XTTS service
            $cloneResponse = Http::timeout(60)
                ->attach('audio', file_get_contents($cleanSamplePath), 'sample.wav')
                ->post('http://xtts:8000/clone', [
                    'name' => "Video_{$video->id}_Speaker_{$speaker->external_key}",
                    'description' => "Auto-cloned voice for dubbing",
                    'language' => $video->target_language ?? 'uz',
                ]);

            if (!$cloneResponse->successful() || !($cloneResponse->json()['ok'] ?? false)) {
                Log::warning('XTTS voice cloning failed', [
                    'speaker' => $speaker->external_key,
                    'error' => $cloneResponse->json()['detail'] ?? 'Unknown',
                ]);
                return false;
            }

            $voiceId = $cloneResponse->json()['voice_id'];
            $sampleRelPath = "audio/voice_samples/{$video->id}/speaker_{$speaker->id}_sample.wav";

            // Update speaker with cloned voice info
            $speaker->update([
                'xtts_voice_id' => $voiceId,
                'voice_sample_path' => $sampleRelPath,
                'voice_cloned' => true,
                'tts_driver' => 'xtts',
            ]);

            Log::info('Voice cloned successfully', [
                'speaker' => $speaker->external_key,
                'voice_id' => $voiceId,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::warning('Voice cloning exception', [
                'speaker' => $speaker->external_key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if XTTS service is available.
     */
    private function isXttsAvailable(): bool
    {
        static $available = null;
        if ($available !== null) return $available;

        try {
            $response = Http::timeout(5)->get('http://xtts:8000/health');
            $available = $response->successful();
        } catch (\Exception $e) {
            $available = false;
        }

        return $available;
    }

    /**
     * Generate TTS for all segments using the driver system.
     * Routes to XTTS for cloned voices, Edge-TTS for others.
     * Includes emotion, speed calculation, and natural speech processing.
     */
    private function generateAllTTSParallel(Video $video, array $segments, string $outputDir): array
    {
        $ttsFiles = [];
        $ttsManager = app(TtsManager::class);
        $naturalSpeech = new NaturalSpeechProcessor();
        $fallbackDriverName = config('dubber.tts.fallback', 'edge');

        foreach ($segments as $i => $seg) {
            $text = $seg['translated'];
            if (empty($text)) continue;

            if ($this->looksLikeEnglish($text) && strtolower($video->target_language) !== 'en') {
                continue;
            }

            $speaker = $seg['speaker'];
            $emotion = $seg['emotion'] ?? 'neutral';
            $targetDuration = $seg['duration'];

            // Natural speech processing: add breathing pauses, emotional markers
            $processedText = $naturalSpeech->process($text, $emotion);
            $processedText = TextNormalizer::normalize($processedText, $video->target_language);

            // Pre-calculate speed to fit time slot
            $speed = $this->calculateSpeed($processedText, $targetDuration);

            // Build options for driver
            $options = [
                'emotion' => $emotion,
                'speed' => $speed,
                'language' => $video->target_language ?? 'uz',
                'gain_db' => (float)($speaker->tts_gain_db ?? 0),
            ];

            // Add cloned voice ID if available
            if ($speaker->voice_cloned && $speaker->xtts_voice_id) {
                $options['voice_id'] = $speaker->xtts_voice_id;
            }

            // Create temporary VideoSegment for driver interface compatibility
            $tempSegment = new VideoSegment([
                'video_id' => $video->id,
                'start_time' => $this->startTime + $seg['start'],
                'end_time' => $this->startTime + $seg['end'],
                'translated_text' => $processedText,
                'emotion' => $emotion,
            ]);
            $tempSegment->id = $i;

            // Determine driver: XTTS for cloned voices, configured default for others
            $driverName = ($speaker->voice_cloned && $speaker->xtts_voice_id)
                ? 'xtts'
                : ($speaker->getEffectiveTtsDriver() ?? config('dubber.tts.default', 'edge'));

            $ttsPath = null;
            $usedDriver = $driverName;

            try {
                if ($ttsManager->hasDriver($driverName)) {
                    $ttsPath = $ttsManager->driver($driverName)->synthesize(
                        $processedText, $speaker, $tempSegment, $options
                    );
                }
            } catch (\Throwable $e) {
                Log::warning("TTS driver [{$driverName}] failed, trying fallback", [
                    'chunk' => $this->chunkIndex,
                    'segment' => $i,
                    'error' => $e->getMessage(),
                ]);
                $ttsPath = null;
            }

            // Fallback to secondary driver
            if (!$ttsPath || !file_exists($ttsPath)) {
                try {
                    if ($ttsManager->hasDriver($fallbackDriverName)) {
                        $ttsPath = $ttsManager->driver($fallbackDriverName)->synthesize(
                            $processedText, $speaker, $tempSegment, $options
                        );
                        $usedDriver = $fallbackDriverName;
                    }
                } catch (\Throwable $e) {
                    Log::error("Fallback TTS also failed for segment {$i}", [
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            if (!$ttsPath || !file_exists($ttsPath)) {
                continue;
            }

            // Post-synthesis speed correction if actual audio still exceeds slot
            $ttsDuration = $this->getAudioDuration($ttsPath);
            if ($ttsDuration > 0 && $targetDuration > 0 && $ttsDuration > $targetDuration * 1.1) {
                $speedRatio = min($ttsDuration / $targetDuration, 1.5);
                $adjustedPath = "{$outputDir}/tts_adj_{$this->chunkIndex}_{$i}.wav";
                $this->adjustTTSSpeed($ttsPath, $adjustedPath, $speedRatio);

                if (file_exists($adjustedPath)) {
                    @unlink($ttsPath);
                    $ttsPath = $adjustedPath;
                    $ttsDuration = $this->getAudioDuration($ttsPath);
                }
            }

            Log::info('TTS segment generated', [
                'chunk' => $this->chunkIndex,
                'segment' => $i,
                'driver' => $usedDriver,
                'emotion' => $emotion,
                'speed' => $speed,
            ]);

            $ttsFiles[] = [
                'path' => $ttsPath,
                'start' => $seg['start'],
                'end' => $seg['end'],
                'target_duration' => $targetDuration,
                'actual_duration' => $ttsDuration,
                'speaker' => $speaker,
                'driver' => $usedDriver,
            ];
        }

        return $ttsFiles;
    }

    /**
     * Calculate speech speed to fit the time slot.
     */
    private function calculateSpeed(string $text, float $slotDuration): float
    {
        if ($slotDuration <= 0) {
            return 1.0;
        }

        // Estimate: ~7 characters per second at normal speed
        $textLen = mb_strlen($text);
        $estimatedDuration = $textLen / 7.0;

        if ($estimatedDuration <= $slotDuration) {
            return 1.0;
        }

        $speedupFactor = $estimatedDuration / $slotDuration;
        return min(1.5, $speedupFactor);
    }

    private function adjustTTSSpeed(string $inputPath, string $outputPath, float $speedRatio): void
    {
        $atempo = min(max($speedRatio, 0.5), 2.0);

        Process::timeout(30)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $inputPath,
            '-af', "atempo={$atempo}",
            '-ar', '44100', '-ac', '2', '-c:a', 'pcm_s16le',
            $outputPath,
        ]);
    }

    private function createFinalAudio(array $ttsFiles, ?string $bgMusicPath, string $outputDir, float $duration): ?string
    {
        $finalPath = "{$outputDir}/final_{$this->chunkIndex}.wav";

        usort($ttsFiles, fn($a, $b) => $a['start'] <=> $b['start']);

        // Prepare background track
        $bgTrack = "{$outputDir}/bg_track_{$this->chunkIndex}.wav";
        if ($bgMusicPath && file_exists($bgMusicPath)) {
            Process::timeout(30)->run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-i', $bgMusicPath,
                '-af', 'volume=0.5',
                '-c:a', 'pcm_s16le',
                $bgTrack,
            ]);
        } else {
            Process::timeout(30)->run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-i', "anullsrc=r=44100:cl=stereo",
                '-t', (string)$duration,
                '-c:a', 'pcm_s16le',
                $bgTrack,
            ]);
        }

        if (empty($ttsFiles)) {
            rename($bgTrack, $finalPath);
            return $finalPath;
        }

        // Position TTS files
        $currentPosition = 0.0;
        $positionedTts = [];
        $gapBetweenSegments = 0.15;

        foreach ($ttsFiles as $tts) {
            $originalStart = $tts['start'];
            $actualDuration = $tts['actual_duration'] ?? $tts['target_duration'];

            $startPosition = max($originalStart, $currentPosition);
            $maxEndTime = min($startPosition + $actualDuration, $duration - 0.1);
            $allowedDuration = $maxEndTime - $startPosition;
            $needsTrim = $actualDuration > $allowedDuration + 0.1;

            $positionedTts[] = [
                'path' => $tts['path'],
                'start' => $startPosition,
                'duration' => $actualDuration,
                'needs_trim' => $needsTrim,
                'trim_duration' => $allowedDuration,
            ];

            $currentPosition = $startPosition + min($actualDuration, $allowedDuration) + $gapBetweenSegments;
        }

        // Build ffmpeg command
        $inputs = ["-i " . escapeshellarg($bgTrack)];
        $filterParts = ["[0:a]anull[bg]"];
        $overlayInputs = ['[bg]'];

        foreach ($positionedTts as $i => $tts) {
            $inputs[] = "-i " . escapeshellarg($tts['path']);
            $inputIdx = $i + 1;
            $delayMs = (int)($tts['start'] * 1000);

            $ttsFilter = "[{$inputIdx}:a]";
            if ($tts['needs_trim']) {
                $trimDur = $tts['trim_duration'];
                $fadeStart = max(0, $trimDur - 0.3);
                $ttsFilter .= "atrim=0:{$trimDur},afade=t=out:st={$fadeStart}:d=0.3,";
            }
            $ttsFilter .= "adelay={$delayMs}|{$delayMs},volume=1.2[v{$i}]";
            $filterParts[] = $ttsFilter;
            $overlayInputs[] = "[v{$i}]";
        }

        $inputCount = count($overlayInputs);
        $filterParts[] = implode('', $overlayInputs) . "amix=inputs={$inputCount}:duration=longest:dropout_transition=0:normalize=0[mixed]";
        $filterParts[] = "[mixed]loudnorm=I=-16:TP=-1.5:LRA=11[out]";

        $filterComplex = implode(';', $filterParts);

        $cmd = "ffmpeg -y -hide_banner -loglevel error " .
            implode(' ', $inputs) .
            " -filter_complex \"{$filterComplex}\" -map \"[out]\" " .
            "-ar 44100 -ac 2 -c:a pcm_s16le " .
            escapeshellarg($finalPath) . " 2>&1";

        exec($cmd, $output, $code);
        @unlink($bgTrack);

        if ($code !== 0 || !file_exists($finalPath)) {
            Log::error('Final audio creation failed', ['chunk' => $this->chunkIndex, 'code' => $code]);
            return null;
        }

        return $finalPath;
    }

    private function createVideoChunk(string $videoPath, ?string $audioPath, string $outputPath, float $start, float $duration): void
    {
        $outputAbs = Storage::disk('local')->path($outputPath);

        if ($audioPath && file_exists($audioPath)) {
            $result = Process::timeout(120)->run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-ss', (string)$start,
                '-i', $videoPath,
                '-i', $audioPath,
                '-t', (string)$duration,
                '-map', '0:v:0',
                '-map', '1:a:0',
                '-c:v', 'copy',
                '-c:a', 'aac', '-b:a', '192k',
                '-shortest',
                '-movflags', '+faststart',
                $outputAbs,
            ]);
            @unlink($audioPath);
        } else {
            $result = Process::timeout(120)->run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-ss', (string)$start,
                '-i', $videoPath,
                '-t', (string)$duration,
                '-c:v', 'copy',
                '-af', 'volume=0.05',
                '-c:a', 'aac', '-b:a', '64k',
                '-movflags', '+faststart',
                $outputAbs,
            ]);
        }

        if (!$result->successful()) {
            throw new \RuntimeException("Failed to create chunk: " . $result->errorOutput());
        }
    }

    private function saveSegments(Video $video, array $segments): void
    {
        foreach ($segments as $seg) {
            VideoSegment::updateOrCreate(
                [
                    'video_id' => $video->id,
                    'start_time' => $this->startTime + $seg['start'],
                ],
                [
                    'speaker_id' => $seg['speaker']->id,
                    'end_time' => $this->startTime + $seg['end'],
                    'text' => $seg['text'],
                    'translated_text' => $seg['translated'],
                    'tts_audio_path' => "videos/chunks/{$video->id}/seg_{$this->chunkIndex}.mp4",
                ]
            );
        }
    }

    private function getAudioDuration(string $path): float
    {
        $result = Process::timeout(10)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);

        return (float)trim($result->output());
    }

    /**
     * Split a block of text into sentence-based segments distributed across the duration.
     * Used as fallback when WhisperX is unavailable and OpenAI returns a single text block.
     */
    private function splitTextIntoSegments(string $text, float $duration): array
    {
        // Split by sentence boundaries
        $sentences = preg_split('/(?<=[.!?。])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($sentences)) {
            return [[
                'start' => 0,
                'end' => $duration,
                'text' => $text,
                'speaker' => 'SPEAKER_00',
            ]];
        }

        // Group sentences so each segment has reasonable length (max ~200 chars)
        $groups = [];
        $current = '';
        foreach ($sentences as $sentence) {
            if (mb_strlen($current) > 0 && mb_strlen($current . ' ' . $sentence) > 200) {
                $groups[] = trim($current);
                $current = $sentence;
            } else {
                $current .= ($current ? ' ' : '') . $sentence;
            }
        }
        if (mb_strlen($current) > 0) {
            $groups[] = trim($current);
        }

        if (empty($groups)) {
            return [[
                'start' => 0,
                'end' => $duration,
                'text' => $text,
                'speaker' => 'SPEAKER_00',
            ]];
        }

        // Distribute time proportionally based on text length
        $totalChars = array_sum(array_map('mb_strlen', $groups));
        $segments = [];
        $currentTime = 0.0;

        foreach ($groups as $i => $group) {
            $ratio = mb_strlen($group) / max(1, $totalChars);
            $segDuration = $ratio * $duration;
            $endTime = min($currentTime + $segDuration, $duration);

            $segments[] = [
                'start' => round($currentTime, 3),
                'end' => round($endTime, 3),
                'text' => $group,
                'speaker' => 'SPEAKER_00',
            ];

            $currentTime = $endTime;
        }

        return $segments;
    }
}
