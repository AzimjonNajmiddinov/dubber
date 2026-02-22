<?php

namespace App\Jobs;

use App\Contracts\TtsDriverInterface;
use App\Models\Speaker;
use App\Models\TtsQualityMetric;
use App\Models\Video;
use App\Models\VideoSegment;
use App\Services\ActingDirector;
use App\Services\NaturalSpeechProcessor;
use App\Services\Tts\Drivers\HybridUzbekDriver;
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
 * - Edge TTS + OpenVoice voice conversion (hybrid_uzbek)
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
            $driverName = config('dubber.tts.default', 'hybrid_uzbek');
            $autoClone = config('dubber.tts.auto_clone', true);

            $driver = $ttsManager->driver($driverName);

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
            $segmentList = $segments->values();
            $segmentCount = $segmentList->count();

            for ($i = 0; $i < $segmentCount; $i++) {
                $seg = $segmentList[$i];
                $this->processSegment($seg, $driver, $video);
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

        $hybridDriver = app(HybridUzbekDriver::class);

        // Assign unique voice DNA to speakers before cloning
        $this->assignVoiceDna($video, $speakers);

        foreach ($speakers as $speaker) {
            try {
                // Extract voice sample from original audio
                $samplePath = $hybridDriver->extractVoiceSample($video->id, $speaker->id);

                if (!file_exists($samplePath) || filesize($samplePath) < 5000) {
                    Log::warning('Voice sample extraction failed', [
                        'speaker_id' => $speaker->id,
                    ]);
                    continue;
                }

                // Measure voice sample duration for tau calculation
                $sampleDuration = $this->probeAudioDuration($samplePath);

                // Clone the voice
                $voiceId = $driver->cloneVoice($samplePath, "speaker_{$speaker->id}", [
                    'language' => $video->target_language ?? 'uz',
                    'description' => "Cloned voice for {$speaker->label}",
                ]);

                // Update speaker record with cloning info + voice DNA tau
                $tau = 0.15 + min(0.35, $sampleDuration / 60); // Same formula as HybridUzbekDriver
                $speaker->update([
                    'voice_cloned' => true,
                    'voice_sample_path' => $samplePath,
                    'voice_sample_duration' => $sampleDuration,
                    'openvoice_tau' => round($tau, 2),
                    $this->getVoiceIdColumn($driver) => $voiceId,
                    'tts_driver' => $driver->name(),
                ]);

                Log::info('Voice cloned successfully', [
                    'speaker_id' => $speaker->id,
                    'voice_id' => $voiceId,
                    'driver' => $driver->name(),
                    'sample_duration' => round($sampleDuration, 1),
                    'tau' => round($tau, 2),
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
     * Assign unique voice DNA to each speaker so they sound distinct.
     *
     * Voice DNA includes:
     * - voice_profile: pitch character (deep, bright, warm, etc.)
     * - speaking_rate_factor: how fast they naturally speak
     * - expressiveness: how much emotion shows in their voice
     *
     * Assignment ensures no two same-gender speakers share the same profile.
     */
    protected function assignVoiceDna(Video $video, $speakers): void
    {
        $profiles = ['default', 'deep', 'bright', 'bass', 'thin', 'warm'];

        // Rate factors that sound distinctly different
        // Spread across range: slow speakers vs fast speakers
        $rateFactors = [1.0, 0.92, 1.08, 0.95, 1.05, 0.88, 1.12];

        // Expressiveness levels: reserved → theatrical
        $expressLevels = [0.5, 0.3, 0.8, 0.4, 0.7, 0.6, 0.9];

        // Track used profiles per gender to avoid duplicates
        $usedByGender = ['male' => [], 'female' => []];

        foreach ($speakers as $i => $speaker) {
            // Skip if voice DNA already assigned
            if ($speaker->voice_profile && $speaker->speaking_rate_factor) {
                $gender = strtolower($speaker->gender ?? 'male');
                $usedByGender[$gender][] = $speaker->voice_profile;
                continue;
            }

            $gender = strtolower($speaker->gender ?? 'male');

            // Pick an unused profile for this gender
            $available = array_diff($profiles, $usedByGender[$gender] ?? []);
            if (empty($available)) {
                $available = $profiles; // All used, allow repeats
            }
            $profile = array_values($available)[$i % count($available)];
            $usedByGender[$gender][] = $profile;

            // Assign rate and expressiveness — spread across speakers
            $rateIdx = $i % count($rateFactors);
            $expressIdx = $i % count($expressLevels);

            // Use detected pitch to inform profile if available
            if ($speaker->pitch_median_hz && !$speaker->voice_profile) {
                $pitch = $speaker->pitch_median_hz;
                if ($gender === 'male') {
                    $profile = match (true) {
                        $pitch < 100 => 'bass',
                        $pitch < 120 => 'deep',
                        $pitch < 150 => 'warm',
                        default => 'bright',
                    };
                } else {
                    $profile = match (true) {
                        $pitch < 190 => 'warm',
                        $pitch < 220 => 'default',
                        $pitch < 260 => 'bright',
                        default => 'thin',
                    };
                }
            }

            $speaker->update([
                'voice_profile' => $profile,
                'speaking_rate_factor' => $rateFactors[$rateIdx],
                'expressiveness' => $expressLevels[$expressIdx],
            ]);

            Log::info('Voice DNA assigned', [
                'speaker_id' => $speaker->id,
                'label' => $speaker->label,
                'gender' => $gender,
                'profile' => $profile,
                'rate_factor' => $rateFactors[$rateIdx],
                'expressiveness' => $expressLevels[$expressIdx],
            ]);
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
    protected function processSegment(VideoSegment $seg, TtsDriverInterface $driver, Video $video): void
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

        // Get direction from segment or default to normal
        $direction = strtolower((string) ($seg->direction ?? 'normal'));
        $validDirections = [
            'whisper', 'soft', 'normal', 'loud', 'shout',
            'breathy', 'tense', 'trembling', 'strained', 'pleading', 'matter_of_fact',
            'sarcastic', 'playful', 'cold', 'warm'
        ];
        if (!in_array($direction, $validDirections)) {
            $direction = 'normal';
        }

        // Get intent for enhanced TTS control
        $intent = strtolower((string) ($seg->intent ?? 'inform'));

        // Get audio source (phone, tv, voiceover, or direct)
        $audioSource = strtolower((string) ($seg->audio_source ?? 'direct'));
        if (!in_array($audioSource, ['direct', 'phone', 'tv', 'voiceover'])) {
            $audioSource = 'direct';
        }

        // Build comprehensive acting direction
        $actingDirection = [
            'emotion' => $emotion,
            'emotion_intensity' => $this->getEmotionIntensity($seg->text ?? '', $emotion),
            'delivery' => $direction,
            'intent' => $intent,
            'audio_source' => $audioSource,
            'vocal_quality' => $this->getVocalQualities($emotion, $direction),
            'acting_note' => $seg->acting_note ?? null,
            'paralinguistics' => [],
        ];

        $options = [
            'emotion' => $emotion,
            'direction' => $direction,
            'intent' => $intent,
            'acting_direction' => $actingDirection,
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

            // Post-synthesis: Apply natural speech processing for human-like sound
            // Breath insertion removed - pink noise sounds artificial and shifts timing.
            // Voice cloning includes natural breathing; Edge TTS doesn't benefit.
            $naturalProcessor = app(NaturalSpeechProcessor::class);
            $naturalProcessor->process($outputPath, $actingDirection);

            // Post-synthesis Step 2: Fit audio to available time (slot + gap until next segment)
            // This allows words to be fully pronounced without cutting off
            $fitResult = $this->fitAudioToSlot($outputPath, $slotDuration);

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
     * Fit TTS audio to the exact slot duration for lip sync.
     *
     * Strategy:
     * 1. Strip leading silence
     * 2. Speed up if TTS is longer than slot (max 1.8x)
     * 3. Slow down if TTS is shorter than slot (min 0.8x)
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

        // Strip leading silence from TTS output
        $this->stripSilence($audioPath);

        $audioDuration = $this->probeAudioDuration($audioPath);
        if ($audioDuration <= 0) {
            return $result;
        }

        $result['duration_ratio'] = $audioDuration / $slotDuration;
        $result['final_duration'] = $audioDuration;

        $ratio = $audioDuration / $slotDuration;

        // Within 5% tolerance — good enough for lip sync
        if ($ratio >= 0.95 && $ratio <= 1.05) {
            return $result;
        }

        // Determine tempo factor: >1 = speed up, <1 = slow down
        // Clamp: max 1.5x speedup, min 0.8x slowdown
        $tempoFactor = min(1.8, max(0.8, $ratio));

        Log::info('TTS tempo fitting for lip sync', [
            'path' => basename($audioPath),
            'audio' => round($audioDuration, 2),
            'slot' => round($slotDuration, 2),
            'ratio' => round($ratio, 2),
            'tempo' => round($tempoFactor, 3),
        ]);

        $atempoChain = $this->buildAtempoChain($tempoFactor);
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
                $result['final_duration'] = $this->probeAudioDuration($audioPath);
                $result['duration_ratio'] = $result['final_duration'] / $slotDuration;
                $result['tempo_applied'] = $tempoFactor;
            } else {
                @unlink($tmpPath);
            }
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
        // atempo supports 0.5–2.0 per filter; chain for larger ratios
        if (abs($ratio - 1.0) < 0.01) {
            return '';
        }

        $filters = [];
        $remaining = $ratio;

        if ($ratio > 1.0) {
            while ($remaining > 1.0 && count($filters) < 3) {
                $step = min($remaining, 2.0);
                $filters[] = 'atempo=' . number_format($step, 4, '.', '');
                $remaining /= $step;
            }
        } else {
            while ($remaining < 1.0 && count($filters) < 3) {
                $step = max($remaining, 0.5);
                $filters[] = 'atempo=' . number_format($step, 4, '.', '');
                $remaining /= $step;
            }
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

        // ONLY STRIP LEADING SILENCE - NEVER touch the end!
        // Edge TTS sometimes adds leading silence but word endings must be preserved.
        // Trailing silence stripping was cutting off words like "etilgan" -> "etil"
        $result = Process::timeout(15)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $audioPath,
            '-af', 'silenceremove=start_periods=1:start_threshold=-50dB:start_duration=0.1',
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
            $tmpPath,
        ]);

        if ($result->successful() && file_exists($tmpPath) && filesize($tmpPath) > 1000) {
            $origSize = filesize($audioPath);
            $newSize = filesize($tmpPath);
            $origDuration = $this->probeAudioDuration($audioPath);
            $newDuration = $this->probeAudioDuration($tmpPath);

            // Safety checks - NEVER accept if we removed too much
            // Max 20% duration reduction - leading silence should be minimal
            // If more is removed, something is wrong - keep original
            $sizeReductionPct = (1 - $newSize / $origSize) * 100;
            $durationReductionPct = $origDuration > 0 ? (1 - $newDuration / $origDuration) * 100 : 0;

            if ($sizeReductionPct <= 80 && $durationReductionPct <= 80 && $newDuration > 0.1) {
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
            'hybrid_uzbek' => 'openvoice_speaker_key',
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

        // Map only unsupported emotions — keep all that EmotionDSP handles
        return match($dominantEmotion) {
            'serious' => 'neutral', // Steady, measured delivery
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

    /**
     * Estimate emotion intensity from text characteristics.
     * Returns 0.0 - 1.0 scale.
     */
    protected function getEmotionIntensity(string $text, string $emotion): float
    {
        $baseIntensity = 0.5;

        // Punctuation intensity
        $exclamations = substr_count($text, '!');
        if ($exclamations >= 3) $baseIntensity += 0.3;
        elseif ($exclamations >= 2) $baseIntensity += 0.2;
        elseif ($exclamations >= 1) $baseIntensity += 0.1;

        // ALL CAPS words indicate intensity
        if (preg_match('/\b[A-Z]{3,}\b/', $text)) {
            $baseIntensity += 0.2;
        }

        // Multiple question marks
        if (preg_match('/\?{2,}/', $text)) {
            $baseIntensity += 0.15;
        }

        // Emotion-specific adjustments
        $baseIntensity = match($emotion) {
            'angry', 'fear' => $baseIntensity + 0.1,
            'excited' => $baseIntensity + 0.15,
            'neutral' => max(0.3, $baseIntensity - 0.2),
            default => $baseIntensity,
        };

        return min(1.0, max(0.1, $baseIntensity));
    }

    /**
     * Determine vocal qualities based on emotion and delivery.
     */
    protected function getVocalQualities(string $emotion, string $delivery): array
    {
        $qualities = [];

        // Delivery-based qualities
        switch ($delivery) {
            case 'whisper':
            case 'breathy':
                $qualities[] = 'breathy';
                break;
            case 'tense':
            case 'strained':
                $qualities[] = 'tense';
                break;
            case 'trembling':
                $qualities[] = 'trembling';
                break;
            case 'shout':
                $qualities[] = 'strained';
                break;
        }

        // Emotion-based qualities
        switch ($emotion) {
            case 'sad':
                if (!in_array('breathy', $qualities)) {
                    $qualities[] = 'nasal';
                }
                break;
            case 'fear':
                if (!in_array('trembling', $qualities)) {
                    $qualities[] = 'tense';
                }
                break;
            case 'angry':
                if (!in_array('tense', $qualities)) {
                    $qualities[] = 'tense';
                }
                break;
            case 'tender':
                $qualities[] = 'breathy';
                break;
        }

        return array_unique($qualities);
    }
}
