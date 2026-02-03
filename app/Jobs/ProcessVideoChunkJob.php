<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoSegment;
use App\Models\Speaker;
use App\Services\TextNormalizer;
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
        $segments = $this->transcribe($audioTranscribePath);

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
        $processedSegments = $this->processSegmentsBatched($video, $segments, $vocalsPath);

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

    private function transcribe(string $audioPath): array
    {
        $segments = $this->transcribeWhisperX($audioPath);
        if (!empty($segments)) return $segments;

        $text = $this->transcribeOpenAI($audioPath);
        if (!empty($text)) {
            // Split text into sentences and distribute evenly across chunk duration
            return $this->splitTextIntoSegments($text, $this->endTime - $this->startTime);
        }

        return [];
    }

    private function transcribeWhisperX(string $audioPath): array
    {
        try {
            $audioRel = str_replace(Storage::disk('local')->path(''), '', $audioPath);
            $whisperxUrl = config('services.whisperx.url', 'http://whisperx:8000');

            $response = Http::timeout(120)->post("{$whisperxUrl}/analyze", [
                'audio_path' => $audioRel,
                'diarize' => true,
                'min_speakers' => 1,
                'max_speakers' => 6,
            ]);

            if ($response->failed()) return [];

            $segments = $response->json()['segments'] ?? [];

            return collect($segments)
                ->map(fn($seg) => [
                    'start' => (float)($seg['start'] ?? 0),
                    'end' => (float)($seg['end'] ?? 0),
                    'text' => trim($seg['text'] ?? ''),
                    'speaker' => $seg['speaker'] ?? 'SPEAKER_00',
                ])
                ->filter(fn($s) => !empty($s['text']) && $s['end'] > $s['start'])
                ->values()
                ->all();

        } catch (\Exception $e) {
            return [];
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
    private function processSegmentsBatched(Video $video, array $segments, ?string $vocalsPath = null): array
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
                $speaker = $this->getOrCreateSpeaker($video, $speakerKey);
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
                ];
            } else {
                $textsToTranslate[$i] = $seg['text'];
                $segmentIndices[] = $i;
            }
        }

        // Batch translate remaining texts
        if (!empty($textsToTranslate)) {
            $translations = $this->translateBatch(array_values($textsToTranslate), $video->target_language);

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

        $langConfig = match (strtolower($targetLang)) {
            'uz', 'uzbek' => [
                'name' => 'Uzbek',
                'script' => 'Use ONLY Latin script (like: ko\'rganingiz, rahmat, yaxshi). NEVER use Cyrillic letters.',
            ],
            'ru', 'russian' => [
                'name' => 'Russian',
                'script' => 'Use Cyrillic script.',
            ],
            'en', 'english' => [
                'name' => 'English',
                'script' => '',
            ],
            default => ['name' => $targetLang, 'script' => ''],
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

            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
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
7. {$langConfig['script']}"
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
            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'temperature' => 0.3,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "You are a professional movie dubbing translator. Translate to {$langConfig['name']}.\n\nRULES:\n- PRESERVE the exact meaning - do NOT lose any information\n- Use natural spoken {$langConfig['name']} expressions\n- Match the length closely for lip-sync\n- Output ONLY the translation, nothing else\n{$langConfig['script']}"
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

    private function getOrCreateSpeaker(Video $video, string $speakerKey): Speaker
    {
        $speaker = Speaker::where('video_id', $video->id)
            ->where('external_key', $speakerKey)
            ->first();

        if ($speaker) return $speaker;

        preg_match('/(\d+)/', $speakerKey, $matches);
        $num = (int)($matches[1] ?? 0);
        $gender = $num % 2 === 0 ? 'male' : 'female';

        $lang = strtolower($video->target_language);
        $voices = self::VOICES[$lang] ?? self::VOICES['en'];
        $genderVoices = $voices[$gender] ?? $voices['male'];

        $existingCount = Speaker::where('video_id', $video->id)->where('gender', $gender)->count();
        $voice = $genderVoices[$existingCount % count($genderVoices)];
        $pitch = self::PITCH_VARIATIONS[$existingCount % count(self::PITCH_VARIATIONS)];
        $rate = self::RATE_VARIATIONS[$existingCount % count(self::RATE_VARIATIONS)];

        try {
            return Speaker::create([
                'video_id' => $video->id,
                'external_key' => $speakerKey,
                'label' => 'Speaker ' . ($num + 1),
                'gender' => $gender,
                'tts_voice' => $voice,
                'tts_pitch' => $pitch,
                'tts_rate' => $rate,
                'tts_driver' => 'edge', // Default, will be upgraded to xtts if cloning works
            ]);
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
     * OPTIMIZATION: Generate TTS in parallel using multiple processes
     * Uses XTTS for cloned voices, Edge-TTS for others
     */
    private function generateAllTTSParallel(Video $video, array $segments, string $outputDir): array
    {
        $ttsFiles = [];
        $ttsJobs = [];
        $xttsJobs = [];

        // Separate jobs by TTS driver
        foreach ($segments as $i => $seg) {
            $text = $seg['translated'];
            if (empty($text)) continue;

            if ($this->looksLikeEnglish($text) && strtolower($video->target_language) !== 'en') {
                continue;
            }

            $text = TextNormalizer::normalize($text, $video->target_language);
            $speaker = $seg['speaker'];

            $job = [
                'index' => $i,
                'text' => $text,
                'speaker' => $speaker,
                'segment' => $seg,
            ];

            // Use XTTS for speakers with cloned voices
            if ($speaker->voice_cloned && $speaker->xtts_voice_id) {
                $xttsJobs[] = $job;
            } else {
                $ttsJobs[] = $job;
            }
        }

        // Process XTTS jobs (cloned voices - sequential for API)
        foreach ($xttsJobs as $job) {
            $result = $this->generateXttsTTS($video, $job, $outputDir);
            if ($result) {
                $ttsFiles[] = $result;
            }
        }

        // Process Edge-TTS jobs in parallel (fallback voices)
        if (!empty($ttsJobs)) {
            $edgeResults = $this->generateEdgeTTSParallel($video, $ttsJobs, $outputDir);
            $ttsFiles = array_merge($ttsFiles, $edgeResults);
        }

        return $ttsFiles;
    }

    /**
     * Generate TTS using XTTS with cloned voice.
     */
    private function generateXttsTTS(Video $video, array $job, string $outputDir): ?array
    {
        $speaker = $job['speaker'];
        $outputRel = "videos/chunks/{$video->id}/xtts_{$this->chunkIndex}_{$job['index']}.wav";

        try {
            $response = Http::timeout(60)->post('http://xtts:8000/synthesize', [
                'text' => $job['text'],
                'voice_id' => $speaker->xtts_voice_id,
                'language' => $video->target_language ?? 'uz',
                'emotion' => 'neutral',
                'speed' => 1.0,
                'output_path' => $outputRel,
            ]);

            if (!$response->successful() || !($response->json()['ok'] ?? false)) {
                Log::warning('XTTS synthesis failed, falling back to Edge-TTS', [
                    'speaker' => $speaker->external_key,
                    'error' => $response->json()['detail'] ?? 'Unknown',
                ]);
                // Fallback to Edge-TTS
                return $this->generateEdgeTTSSingle($video, $job, $outputDir);
            }

            $ttsPath = Storage::disk('local')->path($outputRel);
            if (!file_exists($ttsPath) || filesize($ttsPath) < 500) {
                return $this->generateEdgeTTSSingle($video, $job, $outputDir);
            }

            // Process the XTTS output
            $processedPath = "{$outputDir}/tts_{$this->chunkIndex}_{$job['index']}.wav";
            $finalPath = $this->processTTSAudio($ttsPath, $processedPath);
            @unlink($ttsPath);

            if (!$finalPath) {
                return $this->generateEdgeTTSSingle($video, $job, $outputDir);
            }

            $ttsDuration = $this->getAudioDuration($finalPath);
            $targetDuration = $job['segment']['duration'];

            // Speed adjustment if needed
            if ($ttsDuration > 0 && $targetDuration > 0 && $ttsDuration > $targetDuration * 1.1) {
                $speedRatio = min($ttsDuration / $targetDuration, 1.5);
                $adjustedPath = "{$outputDir}/tts_adj_{$this->chunkIndex}_{$job['index']}.wav";
                $this->adjustTTSSpeed($finalPath, $adjustedPath, $speedRatio);

                if (file_exists($adjustedPath)) {
                    @unlink($finalPath);
                    $finalPath = $adjustedPath;
                    $ttsDuration = $this->getAudioDuration($finalPath);
                }
            }

            Log::info('XTTS synthesis successful', [
                'speaker' => $speaker->external_key,
                'voice_id' => $speaker->xtts_voice_id,
            ]);

            return [
                'path' => $finalPath,
                'start' => $job['segment']['start'],
                'end' => $job['segment']['end'],
                'target_duration' => $targetDuration,
                'actual_duration' => $ttsDuration,
                'speaker' => $speaker,
                'driver' => 'xtts',
            ];

        } catch (\Exception $e) {
            Log::warning('XTTS exception, falling back to Edge-TTS', [
                'error' => $e->getMessage(),
            ]);
            return $this->generateEdgeTTSSingle($video, $job, $outputDir);
        }
    }

    /**
     * Generate single Edge-TTS (fallback).
     */
    private function generateEdgeTTSSingle(Video $video, array $job, string $outputDir): ?array
    {
        $speaker = $job['speaker'];
        $rawPath = "{$outputDir}/tts_raw_{$this->chunkIndex}_{$job['index']}.mp3";
        $textFile = "/tmp/tts_{$this->videoId}_{$this->chunkIndex}_{$job['index']}.txt";

        file_put_contents($textFile, $job['text']);

        $cmd = sprintf(
            'edge-tts -f %s --voice %s --rate=%s --pitch=%s --write-media %s 2>/dev/null',
            escapeshellarg($textFile),
            escapeshellarg($speaker->tts_voice),
            escapeshellarg($speaker->tts_rate ?? '+0%'),
            escapeshellarg($speaker->tts_pitch ?? '+0Hz'),
            escapeshellarg($rawPath)
        );

        exec($cmd, $output, $code);
        @unlink($textFile);

        if (!file_exists($rawPath) || filesize($rawPath) < 500) {
            return null;
        }

        $outputPath = "{$outputDir}/tts_{$this->chunkIndex}_{$job['index']}.wav";
        $ttsPath = $this->processTTSAudio($rawPath, $outputPath);

        if (!$ttsPath) {
            return null;
        }

        $ttsDuration = $this->getAudioDuration($ttsPath);
        $targetDuration = $job['segment']['duration'];

        if ($ttsDuration > 0 && $targetDuration > 0 && $ttsDuration > $targetDuration * 1.1) {
            $speedRatio = min($ttsDuration / $targetDuration, 1.5);
            $adjustedPath = "{$outputDir}/tts_adj_{$this->chunkIndex}_{$job['index']}.wav";
            $this->adjustTTSSpeed($ttsPath, $adjustedPath, $speedRatio);

            if (file_exists($adjustedPath)) {
                @unlink($ttsPath);
                $ttsPath = $adjustedPath;
                $ttsDuration = $this->getAudioDuration($ttsPath);
            }
        }

        return [
            'path' => $ttsPath,
            'start' => $job['segment']['start'],
            'end' => $job['segment']['end'],
            'target_duration' => $targetDuration,
            'actual_duration' => $ttsDuration,
            'speaker' => $speaker,
            'driver' => 'edge',
        ];
    }

    /**
     * Generate Edge-TTS in parallel for multiple jobs.
     */
    private function generateEdgeTTSParallel(Video $video, array $ttsJobs, string $outputDir): array
    {
        $ttsFiles = [];
        $chunks = array_chunk($ttsJobs, 3);

        foreach ($chunks as $chunk) {
            $paths = [];

            // Start all processes in this chunk
            foreach ($chunk as $job) {
                $rawPath = "{$outputDir}/tts_raw_{$this->chunkIndex}_{$job['index']}.mp3";
                $textFile = "/tmp/tts_{$this->videoId}_{$this->chunkIndex}_{$job['index']}.txt";

                file_put_contents($textFile, $job['text']);

                $cmd = sprintf(
                    'edge-tts -f %s --voice %s --rate=%s --pitch=%s --write-media %s 2>/dev/null &',
                    escapeshellarg($textFile),
                    escapeshellarg($job['speaker']->tts_voice),
                    escapeshellarg($job['speaker']->tts_rate ?? '+0%'),
                    escapeshellarg($job['speaker']->tts_pitch ?? '+0Hz'),
                    escapeshellarg($rawPath)
                );

                exec($cmd);
                $paths[$job['index']] = [
                    'raw' => $rawPath,
                    'text_file' => $textFile,
                    'job' => $job,
                ];
            }

            // Wait for all to complete
            usleep(500000);
            $maxWait = 10;
            $waited = 0;
            while ($waited < $maxWait) {
                $allDone = true;
                foreach ($paths as $p) {
                    if (!file_exists($p['raw']) || filesize($p['raw']) < 100) {
                        $allDone = false;
                        break;
                    }
                }
                if ($allDone) break;
                usleep(200000);
                $waited += 0.2;
            }

            // Process completed TTS files
            foreach ($paths as $idx => $p) {
                @unlink($p['text_file']);

                if (!file_exists($p['raw']) || filesize($p['raw']) < 500) {
                    continue;
                }

                $outputPath = "{$outputDir}/tts_{$this->chunkIndex}_{$idx}.wav";
                $ttsPath = $this->processTTSAudio($p['raw'], $outputPath);

                if ($ttsPath) {
                    $job = $p['job'];
                    $ttsDuration = $this->getAudioDuration($ttsPath);
                    $targetDuration = $job['segment']['duration'];

                    if ($ttsDuration > 0 && $targetDuration > 0 && $ttsDuration > $targetDuration * 1.1) {
                        $speedRatio = min($ttsDuration / $targetDuration, 1.5);
                        $adjustedPath = "{$outputDir}/tts_adj_{$this->chunkIndex}_{$idx}.wav";
                        $this->adjustTTSSpeed($ttsPath, $adjustedPath, $speedRatio);

                        if (file_exists($adjustedPath)) {
                            @unlink($ttsPath);
                            $ttsPath = $adjustedPath;
                            $ttsDuration = $this->getAudioDuration($ttsPath);
                        }
                    }

                    $ttsFiles[] = [
                        'path' => $ttsPath,
                        'start' => $job['segment']['start'],
                        'end' => $job['segment']['end'],
                        'target_duration' => $targetDuration,
                        'actual_duration' => $ttsDuration,
                        'speaker' => $job['speaker'],
                        'driver' => 'edge',
                    ];
                }
            }
        }

        return $ttsFiles;
    }

    private function processTTSAudio(string $rawPath, string $outputPath): ?string
    {
        $audioFilter = implode(',', [
            'highpass=f=80',
            'lowpass=f=12000',
            'acompressor=threshold=-18dB:ratio=2:attack=10:release=100',
            'loudnorm=I=-16:TP=-1.5:LRA=11',
        ]);

        $result = Process::timeout(30)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $rawPath,
            '-af', $audioFilter,
            '-ar', '44100', '-ac', '2', '-c:a', 'pcm_s16le',
            $outputPath,
        ]);

        @unlink($rawPath);

        return $result->successful() && file_exists($outputPath) ? $outputPath : null;
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
