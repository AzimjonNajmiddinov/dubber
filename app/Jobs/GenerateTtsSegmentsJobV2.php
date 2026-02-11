<?php

namespace App\Jobs;

use App\Contracts\TtsDriverInterface;
use App\Models\Speaker;
use App\Models\TtsQualityMetric;
use App\Models\Video;
use App\Models\VideoSegment;
use App\Services\Tts\Drivers\XttsDriver;
use App\Services\Tts\TtsManager;
use App\Traits\DetectsEnglish;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Generate TTS audio segments using the configured TTS driver.
 *
 * Supports:
 * - Multiple TTS engines (Edge, ElevenLabs, OpenAI, XTTS)
 * - Automatic voice cloning per speaker
 * - Emotion-aware synthesis
 * - Time-fitting with speed adjustment
 */
class GenerateTtsSegmentsJobV2 implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use DetectsEnglish;

    public int $timeout = 3600; // Increased for voice cloning
    public int $tries = 3;
    public int $uniqueFor = 3600;

    public array $backoff = [30, 60, 120];

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateTtsSegmentsJobV2 failed permanently', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $video = Video::find($this->videoId);
            if ($video) {
                $video->update(['status' => 'tts_failed']);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to update video status', [
                'video_id' => $this->videoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(TtsManager $ttsManager): void
    {
        $lock = Cache::lock("video:{$this->videoId}:tts", 3600);
        if (!$lock->get()) {
            return;
        }

        try {
            /** @var Video $video */
            $video = Video::query()->findOrFail($this->videoId);

            // Get configured TTS driver
            $driverName = config('dubber.tts.default', 'xtts');
            $driver = $ttsManager->driver($driverName);
            $autoClone = config('dubber.tts.auto_clone', true);

            Log::info('TTS generation starting', [
                'video_id' => $video->id,
                'driver' => $driverName,
                'auto_clone' => $autoClone,
            ]);

            // Step 1: Clone voices if enabled and driver supports it
            if ($autoClone && $driver->supportsVoiceCloning()) {
                $this->cloneSpeakerVoices($video, $driver);
            }

            // Step 2: Get segments to process
            $segments = VideoSegment::query()
                ->with('speaker')
                ->where('video_id', $video->id)
                ->whereNotNull('translated_text')
                ->where('translated_text', '!=', '')
                ->orderBy('start_time')
                ->get();

            if ($segments->isEmpty()) {
                throw new \RuntimeException("No translated segments found for video {$video->id}");
            }

            $outDirRel = "audio/tts/{$video->id}";
            Storage::disk('local')->makeDirectory($outDirRel);

            // Step 3: Generate TTS for each segment sequentially
            // Pass next segment start time to allow audio to extend into gaps
            $segmentList = $segments->values();
            $segmentCount = $segmentList->count();

            for ($i = 0; $i < $segmentCount; $i++) {
                $seg = $segmentList[$i];
                $nextStart = ($i < $segmentCount - 1) ? (float) $segmentList[$i + 1]->start_time : null;
                $this->processSegment($seg, $driver, $video, $nextStart);
            }

            Log::info('TTS generation complete', [
                'video_id' => $video->id,
                'segments_processed' => $segments->count(),
                'driver' => $driverName,
            ]);

            // Check voice consistency and log any outliers
            $this->checkVoiceConsistency($video->id);

            $video->update(['status' => 'tts_generated']);
            MixDubbedAudioJob::dispatch($video->id);

        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Clone voices for all speakers in the video.
     */
    protected function cloneSpeakerVoices(Video $video, TtsDriverInterface $driver): void
    {
        $speakers = Speaker::where('video_id', $video->id)
            ->where('voice_cloned', false)
            ->get();

        if ($speakers->isEmpty()) {
            return;
        }

        Log::info('Starting voice cloning for speakers', [
            'video_id' => $video->id,
            'speaker_count' => $speakers->count(),
        ]);

        // Get the XTTS driver for voice extraction
        $xttsDriver = app(XttsDriver::class);

        foreach ($speakers as $speaker) {
            try {
                // Extract voice sample from original audio
                $samplePath = $xttsDriver->extractVoiceSample($video->id, $speaker->id);

                if (!file_exists($samplePath) || filesize($samplePath) < 5000) {
                    Log::warning('Voice sample extraction failed', [
                        'speaker_id' => $speaker->id,
                    ]);
                    continue;
                }

                // Clone the voice
                $voiceId = $driver->cloneVoice($samplePath, "speaker_{$speaker->id}", [
                    'language' => $video->target_language ?? 'uz',
                    'description' => "Cloned voice for {$speaker->label}",
                ]);

                // Update speaker record
                $speaker->update([
                    'voice_cloned' => true,
                    'voice_sample_path' => $samplePath,
                    $this->getVoiceIdColumn($driver) => $voiceId,
                    'tts_driver' => $driver->name(),
                ]);

                Log::info('Voice cloned successfully', [
                    'speaker_id' => $speaker->id,
                    'voice_id' => $voiceId,
                    'driver' => $driver->name(),
                ]);

            } catch (\Throwable $e) {
                Log::warning('Voice cloning failed for speaker', [
                    'speaker_id' => $speaker->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue with default voice for this speaker
            }
        }
    }

    /**
     * Process a single segment.
     *
     * @param VideoSegment $seg The segment to process
     * @param TtsDriverInterface $driver TTS driver to use
     * @param Video $video The video being processed
     * @param float|null $nextSegmentStart Start time of next segment (null if last)
     */
    protected function processSegment(VideoSegment $seg, TtsDriverInterface $driver, Video $video, ?float $nextSegmentStart = null): void
    {
        $speaker = $seg->speaker;
        $text = trim((string) $seg->translated_text);

        if ($text === '') {
            return;
        }

        // Guard: avoid accidental English TTS
        if ($this->looksLikeEnglish($text)) {
            Log::error('TTS blocked: translated_text looks English', [
                'video_id' => $video->id,
                'segment_id' => $seg->id,
                'sample' => mb_substr($text, 0, 180),
            ]);
            throw new \RuntimeException("Translated text looks English for segment {$seg->id}");
        }

        // Detect emotion from segment, speaker, or text analysis
        $emotion = strtolower((string) ($seg->emotion ?: ($speaker?->emotion ?: '')));
        if (empty($emotion) || $emotion === 'neutral') {
            $emotion = $this->detectEmotionFromText($seg->text ?? '', $text);
        }
        $slotDuration = (float) $seg->end_time - (float) $seg->start_time;

        // Calculate actual available time until next segment starts
        // This allows TTS to extend into gaps between segments
        $segEnd = (float) $seg->end_time;
        $availableTime = $slotDuration;
        if ($nextSegmentStart !== null && $nextSegmentStart > $segEnd) {
            // Add gap time (max 1 second extra to prevent too long pauses)
            $gap = min(1.0, $nextSegmentStart - $segEnd);
            $availableTime = $slotDuration + $gap;
        }

        // Get direction from segment or default to normal
        $direction = strtolower((string) ($seg->direction ?? 'normal'));
        $validDirections = ['whisper', 'soft', 'normal', 'loud', 'shout', 'sarcastic', 'playful', 'cold', 'warm'];
        if (!in_array($direction, $validDirections)) {
            $direction = 'normal';
        }

        $options = [
            'emotion' => $emotion,
            'direction' => $direction,
            'speed' => 1.0,
            'gain_db' => (float) ($speaker?->tts_gain_db ?? 0),
            'language' => $video->target_language ?? 'uz',
        ];

        // Use cloned voice if available
        if ($speaker && $speaker->hasClonedVoice()) {
            $options['voice_id'] = $speaker->getVoiceIdForDriver($driver->name());
        }

        Log::info('TTS generating segment', [
            'video_id' => $video->id,
            'segment_id' => $seg->id,
            'driver' => $driver->name(),
            'emotion' => $emotion,
            'direction' => $direction,
            'slot_duration' => $slotDuration,
            'voice_cloned' => $speaker?->voice_cloned ?? false,
        ]);

        try {
            $outputPath = $driver->synthesize($text, $speaker, $seg, $options);

            // Post-synthesis: fit audio to available time (slot + gap until next segment)
            // This allows words to be fully pronounced without cutting off
            $fitResult = $this->fitAudioToSlot($outputPath, $availableTime);

            // Update segment with output path
            $relPath = str_replace(Storage::disk('local')->path(''), '', $outputPath);

            $seg->update([
                'tts_audio_path' => $relPath,
                'tts_gain_db' => $options['gain_db'],
            ]);

            // Record quality metrics for voice consistency tracking
            if ($speaker) {
                $this->recordQualityMetrics($seg, $speaker, $outputPath, $slotDuration, $fitResult);
            }

            Log::info('TTS segment ready', [
                'segment_id' => $seg->id,
                'path' => $relPath,
            ]);

            // Generate segment video immediately for progressive HLS playback
            GenerateSegmentVideoJob::dispatch($seg->id)->onQueue('default');

        } catch (\Throwable $e) {
            Log::error('TTS synthesis failed for segment', [
                'segment_id' => $seg->id,
                'error' => $e->getMessage(),
            ]);

            // Try fallback driver
            $fallbackName = config('dubber.tts.fallback');
            if ($fallbackName && $fallbackName !== $driver->name()) {
                $this->processSegmentWithFallback($seg, $speaker, $text, $options, $video);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Process segment with fallback TTS driver.
     */
    protected function processSegmentWithFallback(
        VideoSegment $seg,
        ?Speaker $speaker,
        string $text,
        array $options,
        Video $video
    ): void {
        $fallbackName = config('dubber.tts.fallback', 'edge');
        $fallbackDriver = app(TtsManager::class)->driver($fallbackName);

        Log::info('Using fallback TTS driver', [
            'segment_id' => $seg->id,
            'fallback' => $fallbackName,
        ]);

        $outputPath = $fallbackDriver->synthesize($text, $speaker, $seg, $options);

        // Time-fit the fallback TTS as well
        $slotDuration = ((float) $seg->end_time) - ((float) $seg->start_time);
        $this->fitAudioToSlot($outputPath, $slotDuration);

        $relPath = str_replace(Storage::disk('local')->path(''), '', $outputPath);

        $seg->update([
            'tts_audio_path' => $relPath,
            'tts_gain_db' => $options['gain_db'],
        ]);

        Log::info('TTS segment ready (fallback)', [
            'segment_id' => $seg->id,
            'path' => $relPath,
        ]);

        // Generate segment video for HLS
        GenerateSegmentVideoJob::dispatch($seg->id)->onQueue('default');
    }

    /**
     * Fit TTS audio to the available time slot using HYBRID strategy.
     *
     * CRITICAL: NEVER cut or trim audio - sentences must ALWAYS be fully spoken!
     *
     * Strategy (in order):
     * 1. Strip only TRUE silence (very conservative threshold)
     * 2. Apply gentle atempo speedup (max 1.5x) if needed
     * 3. If still too long: signal for re-translation with shorter text
     * 4. Ultimate fallback: allow overflow (better than cutting words)
     *
     * @return array{duration_ratio: float, was_trimmed: bool, tempo_applied: float|null, needs_retranslation: bool, final_duration: float}
     */
    protected function fitAudioToSlot(string $audioPath, float $slotDuration): array
    {
        $result = [
            'duration_ratio' => 1.0,
            'was_trimmed' => false,
            'tempo_applied' => null,
            'needs_retranslation' => false,
            'final_duration' => 0,
        ];

        if ($slotDuration <= 0 || !file_exists($audioPath)) {
            return $result;
        }

        // Step 1: Strip ONLY true silence (very conservative -60dB threshold)
        // This protects quiet speech endings from being cut
        $this->stripSilence($audioPath);

        // Probe actual TTS duration (after silence removal)
        $audioDuration = $this->probeAudioDuration($audioPath);
        if ($audioDuration <= 0) {
            return $result;
        }

        $result['duration_ratio'] = $audioDuration / $slotDuration;
        $result['final_duration'] = $audioDuration;

        // CASE 1: Audio fits within slot (+5% tolerance) - perfect!
        if ($audioDuration <= $slotDuration * 1.05) {
            Log::debug('TTS audio fits slot perfectly', [
                'path' => basename($audioPath),
                'audio' => round($audioDuration, 2),
                'slot' => round($slotDuration, 2),
                'fill' => round($audioDuration / $slotDuration * 100) . '%',
            ]);
            return $result;
        }

        $speedupNeeded = $audioDuration / $slotDuration;

        // CASE 2: Minor overflow (5-20%) - gentle speedup
        // CASE 3: Moderate overflow (20-50%) - moderate speedup
        // CASE 4: Significant overflow (>50%) - max speedup + flag for review

        // Use higher max speedup (1.5x) for better fitting
        // 1.5x is still natural-sounding for most speech
        $maxSpeedup = 1.50;
        $actualSpeedup = min($speedupNeeded, $maxSpeedup);

        // Only apply speedup if actually needed (>5% overflow)
        if ($speedupNeeded > 1.05) {
            Log::info('TTS applying tempo adjustment', [
                'path' => basename($audioPath),
                'audio_duration' => round($audioDuration, 2),
                'slot_duration' => round($slotDuration, 2),
                'speedup_needed' => round($speedupNeeded, 2),
                'speedup_applied' => round($actualSpeedup, 2),
                'overflow_pct' => round(($speedupNeeded - 1.0) * 100) . '%',
            ]);

            // Apply tempo adjustment
            $atempoChain = $this->buildAtempoChain($actualSpeedup);
            $result['tempo_applied'] = $actualSpeedup;

            if (!empty($atempoChain)) {
                $tmpPath = $audioPath . '.fitted.wav';

                $processResult = Process::timeout(30)->run([
                    'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                    '-i', $audioPath,
                    '-af', $atempoChain,
                    '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
                    $tmpPath,
                ]);

                if ($processResult->successful() && file_exists($tmpPath) && filesize($tmpPath) > 0) {
                    rename($tmpPath, $audioPath);

                    // Update final duration after speedup
                    $result['final_duration'] = $this->probeAudioDuration($audioPath);
                    $result['duration_ratio'] = $result['final_duration'] / $slotDuration;

                    Log::debug('TTS audio after tempo adjustment', [
                        'path' => basename($audioPath),
                        'final_duration' => round($result['final_duration'], 2),
                        'slot_duration' => round($slotDuration, 2),
                        'final_fill' => round($result['final_duration'] / $slotDuration * 100) . '%',
                    ]);
                } else {
                    @unlink($tmpPath);
                    Log::warning('TTS tempo adjustment failed, keeping original', [
                        'path' => basename($audioPath),
                    ]);
                }
            }
        }

        // CASE 5: If after max speedup we're still >20% over, flag for potential re-translation
        // But NEVER cut - allow overflow if necessary
        if ($result['final_duration'] > $slotDuration * 1.20) {
            $result['needs_retranslation'] = true;
            Log::warning('TTS audio overflow - translation may be too long', [
                'path' => basename($audioPath),
                'final_duration' => round($result['final_duration'], 2),
                'slot_duration' => round($slotDuration, 2),
                'overflow_pct' => round(($result['final_duration'] / $slotDuration - 1.0) * 100) . '%',
                'suggestion' => 'Consider re-translating with shorter character limit',
            ]);
        }

        return $result;
    }

    /**
     * Probe audio file duration in seconds.
     */
    protected function probeAudioDuration(string $audioPath): float
    {
        $probe = Process::timeout(15)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'csv=p=0',
            $audioPath,
        ]);

        if (!$probe->successful()) {
            return 0;
        }

        return (float) trim($probe->output());
    }

    /**
     * Build chained atempo filter string for a given speedup ratio.
     * Each atempo filter supports 0.5–2.0, so chain multiple for larger ratios.
     */
    protected function buildAtempoChain(float $ratio): string
    {
        if ($ratio <= 1.0) {
            return '';
        }

        $filters = [];
        $remaining = $ratio;

        while ($remaining > 1.0 && count($filters) < 3) {
            $step = min($remaining, 2.0);
            $filters[] = 'atempo=' . number_format($step, 4, '.', '');
            $remaining = $remaining / $step;
        }

        return implode(',', $filters);
    }

    /**
     * Strip ONLY true silence from TTS audio - very conservative!
     *
     * Edge TTS often pads output with several seconds of silence.
     * CRITICAL: Use very conservative threshold to NEVER cut actual speech!
     * It's better to keep extra silence than to cut word endings.
     */
    protected function stripSilence(string $audioPath): void
    {
        $tmpPath = $audioPath . '.trimmed.wav';

        // VERY CONSERVATIVE settings:
        // -60dB threshold: only removes absolute silence, protects quiet speech
        // 0.15s duration: requires 150ms of silence before cutting (protects word endings)
        // This is safer than cutting words
        $result = Process::timeout(15)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $audioPath,
            '-af', 'silenceremove=start_periods=1:start_threshold=-60dB:start_duration=0.15,areverse,silenceremove=start_periods=1:start_threshold=-60dB:start_duration=0.15,areverse',
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
            $tmpPath,
        ]);

        if ($result->successful() && file_exists($tmpPath) && filesize($tmpPath) > 1000) {
            $origSize = filesize($audioPath);
            $newSize = filesize($tmpPath);
            $origDuration = $this->probeAudioDuration($audioPath);
            $newDuration = $this->probeAudioDuration($tmpPath);

            // Safety checks - NEVER accept if we removed too much
            // Max 40% size reduction (silence should never be >40% of audio)
            // Max 40% duration reduction
            $sizeReductionPct = (1 - $newSize / $origSize) * 100;
            $durationReductionPct = $origDuration > 0 ? (1 - $newDuration / $origDuration) * 100 : 0;

            if ($sizeReductionPct <= 40 && $durationReductionPct <= 40 && $newDuration > 0.1) {
                Log::debug('Stripped silence from TTS audio', [
                    'path' => basename($audioPath),
                    'orig_duration' => round($origDuration, 2),
                    'new_duration' => round($newDuration, 2),
                    'removed_sec' => round($origDuration - $newDuration, 2),
                ]);
                rename($tmpPath, $audioPath);
                return;
            } else {
                Log::warning('Silence removal rejected - would remove too much', [
                    'path' => basename($audioPath),
                    'size_reduction_pct' => round($sizeReductionPct, 1),
                    'duration_reduction_pct' => round($durationReductionPct, 1),
                ]);
            }
        }

        @unlink($tmpPath);
    }

    /**
     * Get the voice ID column name for a driver.
     */
    protected function getVoiceIdColumn(TtsDriverInterface $driver): string
    {
        return match ($driver->name()) {
            'elevenlabs' => 'elevenlabs_voice_id',
            'xtts' => 'xtts_voice_id',
            default => 'tts_voice',
        };
    }

    /**
     * Detect emotion from text using comprehensive analysis.
     * Combines punctuation, keywords, sentence structure, and context patterns.
     */
    protected function detectEmotionFromText(string $originalText, string $translatedText): string
    {
        $text = mb_strtolower($originalText . ' ' . $translatedText);
        $original = $originalText;

        // Score-based detection for nuanced emotions
        $scores = [
            'angry' => 0,
            'happy' => 0,
            'sad' => 0,
            'fear' => 0,
            'surprise' => 0,
            'excited' => 0,
            'serious' => 0,
            'tender' => 0,
        ];

        // === PUNCTUATION ANALYSIS ===

        // Multiple exclamation marks = strong emotion (anger or excitement)
        $exclamationCount = substr_count($original, '!');
        if ($exclamationCount >= 2) {
            $scores['angry'] += 2;
            $scores['excited'] += 2;
        } elseif ($exclamationCount == 1) {
            $scores['excited'] += 1;
        }

        // Multiple question marks = surprise/disbelief
        if (preg_match('/\?{2,}/', $original)) {
            $scores['surprise'] += 3;
        }

        // Ellipsis = hesitation, sadness, or tension
        if (str_contains($original, '...') || str_contains($original, '…')) {
            $scores['sad'] += 1;
            $scores['serious'] += 1;
        }

        // ALL CAPS = shouting (anger or excitement)
        if (preg_match('/\b[A-Z]{3,}\b/', $original)) {
            $scores['angry'] += 2;
            $scores['excited'] += 1;
        }

        // === KEYWORD ANALYSIS ===

        // Anger keywords (weighted by intensity)
        if (preg_match('/\b(furious|rage|hate|loathe|despise)\b/i', $text)) $scores['angry'] += 4;
        if (preg_match('/\b(angry|mad|pissed|annoyed|frustrated)\b/i', $text)) $scores['angry'] += 3;
        if (preg_match('/\b(damn|hell|shut up|get out|stop it|enough)\b/i', $text)) $scores['angry'] += 2;

        // Happy/Joy keywords
        if (preg_match('/\b(ecstatic|overjoyed|thrilled|delighted)\b/i', $text)) $scores['happy'] += 4;
        if (preg_match('/\b(happy|joy|wonderful|amazing|fantastic|great|love)\b/i', $text)) $scores['happy'] += 3;
        if (preg_match('/\b(glad|pleased|nice|good|smile|laugh)\b/i', $text)) $scores['happy'] += 2;

        // Sad keywords
        if (preg_match('/\b(devastated|heartbroken|grief|mourn|tragic)\b/i', $text)) $scores['sad'] += 4;
        if (preg_match('/\b(sad|sorry|miss|cry|tears|died|dead|lost)\b/i', $text)) $scores['sad'] += 3;
        if (preg_match('/\b(unfortunately|regret|alone|lonely|pain)\b/i', $text)) $scores['sad'] += 2;

        // Fear keywords
        if (preg_match('/\b(terrified|horrified|petrified|panic)\b/i', $text)) $scores['fear'] += 4;
        if (preg_match('/\b(afraid|scared|fear|terror|danger|threat)\b/i', $text)) $scores['fear'] += 3;
        if (preg_match('/\b(worried|nervous|anxious|careful|watch out|run|help)\b/i', $text)) $scores['fear'] += 2;

        // Surprise keywords
        if (preg_match('/\b(shocked|astonished|stunned|incredible)\b/i', $text)) $scores['surprise'] += 4;
        if (preg_match('/\b(surprised|unexpected|unbelievable|what|really)\b/i', $text)) $scores['surprise'] += 2;
        if (preg_match('/\b(wow|oh|whoa|no way|seriously)\b/i', $text)) $scores['surprise'] += 2;

        // Excitement keywords
        if (preg_match('/\b(yes|yeah|awesome|incredible|let\'s go|can\'t wait)\b/i', $text)) $scores['excited'] += 3;
        if (preg_match('/\b(exciting|excited|thrilling|adventure)\b/i', $text)) $scores['excited'] += 2;

        // Tender/Gentle keywords
        if (preg_match('/\b(dear|darling|sweetheart|honey|love you|care)\b/i', $text)) $scores['tender'] += 3;
        if (preg_match('/\b(gentle|soft|quiet|peaceful|calm)\b/i', $text)) $scores['tender'] += 2;

        // Serious/Grave keywords
        if (preg_match('/\b(must|important|serious|critical|urgent|need to)\b/i', $text)) $scores['serious'] += 2;
        if (preg_match('/\b(listen|understand|remember|never|always)\b/i', $text)) $scores['serious'] += 1;

        // === SENTENCE STRUCTURE ANALYSIS ===

        // Questions ending with specific words indicate curiosity/surprise
        if (preg_match('/\b(really|seriously|actually)\s*\?/i', $text)) {
            $scores['surprise'] += 2;
        }

        // Short sentences with exclamation = urgency
        if (strlen($original) < 20 && $exclamationCount > 0) {
            $scores['excited'] += 1;
            $scores['angry'] += 1;
        }

        // Commands (imperative sentences)
        if (preg_match('/^(go|come|run|stop|wait|look|listen|help|don\'t|do|get)\b/i', trim($original))) {
            $scores['serious'] += 2;
        }

        // === FIND DOMINANT EMOTION ===

        $maxScore = 0;
        $dominantEmotion = 'neutral';

        foreach ($scores as $emotion => $score) {
            if ($score > $maxScore) {
                $maxScore = $score;
                $dominantEmotion = $emotion;
            }
        }

        // Need minimum score to override neutral (threshold = 2)
        if ($maxScore < 2) {
            // Check for simple question without other emotion
            if (str_contains($original, '?')) {
                return 'neutral';
            }
            return 'neutral';
        }

        // Map additional emotions to XTTS-supported set
        return match($dominantEmotion) {
            'tender' => 'sad',      // Soft, gentle delivery
            'serious' => 'neutral', // Steady, measured delivery
            'excited' => 'happy',   // Energetic delivery
            default => $dominantEmotion,
        };
    }

    /**
     * Record quality metrics for voice consistency tracking.
     */
    protected function recordQualityMetrics(
        VideoSegment $segment,
        Speaker $speaker,
        string $audioPath,
        float $slotDuration,
        array $fitResult
    ): void {
        try {
            // Measure RMS volume
            $rmsDb = $this->measureRms($audioPath);

            // Measure fundamental frequency (pitch)
            $pitchHz = $this->measurePitch($audioPath);

            TtsQualityMetric::create([
                'video_segment_id' => $segment->id,
                'speaker_id' => $speaker->id,
                'duration_ratio' => $fitResult['duration_ratio'] ?? null,
                'rms_db' => $rmsDb,
                'pitch_hz' => $pitchHz,
                'tempo_applied' => $fitResult['tempo_applied'] ?? null,
                'was_trimmed' => $fitResult['was_trimmed'] ?? false,
            ]);

            Log::debug('TTS quality metrics recorded', [
                'segment_id' => $segment->id,
                'speaker_id' => $speaker->id,
                'rms_db' => $rmsDb,
                'pitch_hz' => $pitchHz,
                'duration_ratio' => $fitResult['duration_ratio'] ?? null,
            ]);

        } catch (\Throwable $e) {
            Log::warning('Failed to record quality metrics', [
                'segment_id' => $segment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Measure RMS volume in dB.
     */
    protected function measureRms(string $audioPath): ?float
    {
        $result = Process::timeout(10)->run([
            'ffmpeg', '-i', $audioPath, '-af', 'volumedetect',
            '-f', 'null', '-',
        ]);

        // Parse output for mean_volume
        if (preg_match('/mean_volume:\s*([-\d.]+)\s*dB/', $result->errorOutput(), $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /**
     * Measure fundamental frequency (pitch) in Hz.
     * Uses FFmpeg's ESOLA analysis for a rough pitch estimate.
     */
    protected function measurePitch(string $audioPath): ?float
    {
        // Use crepe or basic analysis if available, otherwise fallback to null
        // For now, we use a simple spectral centroid as a proxy for pitch
        $result = Process::timeout(10)->run([
            'ffprobe', '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=sample_rate',
            '-of', 'csv=p=0',
            $audioPath,
        ]);

        // This is a placeholder - real pitch detection would use crepe or praat
        // For now, return null as accurate pitch detection requires additional tools
        return null;
    }

    /**
     * Check voice consistency across all segments for a speaker and log outliers.
     * Called after all segments are processed.
     */
    protected function checkVoiceConsistency(int $videoId): void
    {
        $speakers = Speaker::where('video_id', $videoId)->get();

        foreach ($speakers as $speaker) {
            $metrics = TtsQualityMetric::where('speaker_id', $speaker->id)
                ->whereHas('videoSegment', fn($q) => $q->where('video_id', $videoId))
                ->get();

            if ($metrics->count() < 3) {
                continue;
            }

            // Check RMS consistency
            $rmsValues = $metrics->pluck('rms_db')->filter()->values();
            if ($rmsValues->count() >= 3) {
                $rmsMean = $rmsValues->avg();
                $rmsStd = $this->standardDeviation($rmsValues->toArray());

                if ($rmsStd > 6) { // More than 6dB variance is concerning
                    Log::warning('Voice volume inconsistency detected', [
                        'speaker_id' => $speaker->id,
                        'video_id' => $videoId,
                        'rms_mean' => round($rmsMean, 1),
                        'rms_std' => round($rmsStd, 1),
                    ]);
                }
            }

            // Check pitch consistency (if available)
            $pitchValues = $metrics->pluck('pitch_hz')->filter()->values();
            if ($pitchValues->count() >= 3) {
                $pitchMean = $pitchValues->avg();
                $pitchStd = $this->standardDeviation($pitchValues->toArray());

                // Flag if pitch varies more than 15% from mean
                if ($pitchMean > 0 && ($pitchStd / $pitchMean) > 0.15) {
                    $outliers = $metrics->filter(fn($m) =>
                        $m->pitch_hz && abs($m->pitch_hz - $pitchMean) > 2 * $pitchStd
                    );

                    Log::warning('Voice pitch inconsistency detected', [
                        'speaker_id' => $speaker->id,
                        'video_id' => $videoId,
                        'pitch_mean' => round($pitchMean, 1),
                        'pitch_std' => round($pitchStd, 1),
                        'outlier_segments' => $outliers->pluck('video_segment_id')->toArray(),
                    ]);
                }
            }

            // Check duration ratio (too many trimmed segments is bad)
            $trimmedCount = $metrics->where('was_trimmed', true)->count();
            $trimmedPercent = ($trimmedCount / $metrics->count()) * 100;

            if ($trimmedPercent > 30) {
                Log::warning('High segment trimming rate', [
                    'speaker_id' => $speaker->id,
                    'video_id' => $videoId,
                    'trimmed_percent' => round($trimmedPercent, 1),
                    'trimmed_count' => $trimmedCount,
                    'total_segments' => $metrics->count(),
                ]);
            }
        }
    }

    /**
     * Calculate standard deviation.
     */
    protected function standardDeviation(array $values): float
    {
        if (count($values) < 2) {
            return 0;
        }

        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($v) => pow($v - $mean, 2), $values);

        return sqrt(array_sum($squaredDiffs) / count($values));
    }
}
