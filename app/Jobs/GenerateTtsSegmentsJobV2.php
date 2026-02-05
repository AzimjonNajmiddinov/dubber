<?php

namespace App\Jobs;

use App\Contracts\TtsDriverInterface;
use App\Models\Speaker;
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

            // Step 3: Generate TTS for each segment
            foreach ($segments as $seg) {
                $this->processSegment($seg, $driver, $video);
            }

            Log::info('TTS generation complete', [
                'video_id' => $video->id,
                'segments_processed' => $segments->count(),
                'driver' => $driverName,
            ]);

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

        $options = [
            'emotion' => $emotion,
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
            'slot_duration' => $slotDuration,
            'voice_cloned' => $speaker?->voice_cloned ?? false,
        ]);

        try {
            $outputPath = $driver->synthesize($text, $speaker, $seg, $options);

            // Post-synthesis: fit audio to time slot if it overflows
            $this->fitAudioToSlot($outputPath, $slotDuration);

            // Update segment with output path
            $relPath = str_replace(Storage::disk('local')->path(''), '', $outputPath);

            $seg->update([
                'tts_audio_path' => $relPath,
                'tts_gain_db' => $options['gain_db'],
            ]);

            Log::info('TTS segment ready', [
                'segment_id' => $seg->id,
                'path' => $relPath,
            ]);

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
        $relPath = str_replace(Storage::disk('local')->path(''), '', $outputPath);

        $seg->update([
            'tts_audio_path' => $relPath,
            'tts_gain_db' => $options['gain_db'],
        ]);
    }

    /**
     * Fit TTS audio to the available time slot (bidirectional).
     *
     * Strategy:
     * - Too short (ratio 0.75–1.0): slow down with atempo to fill the slot
     * - Already fits (ratio ~1.0): no change
     * - Too long (ratio 1.0–2.3): speed up with atempo
     * - Way too long (>2.3x): apply 2.3x atempo + trim with fade-out
     */
    protected function fitAudioToSlot(string $audioPath, float $slotDuration): void
    {
        if ($slotDuration <= 0 || !file_exists($audioPath)) {
            return;
        }

        // Step 1: Strip trailing silence (Edge TTS adds heavy padding)
        $this->stripSilence($audioPath);

        // Probe actual TTS duration (after silence removal)
        $probe = Process::timeout(15)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'csv=p=0',
            $audioPath,
        ]);

        if (!$probe->successful()) {
            Log::warning('ffprobe failed for TTS audio', [
                'path' => $audioPath,
                'error' => $probe->errorOutput(),
            ]);
            return;
        }

        $audioDuration = (float) trim($probe->output());

        if ($audioDuration <= 0) {
            return;
        }

        $ratio = $audioDuration / $slotDuration;
        $maxTempo = 2.3;
        $minTempo = 0.75;

        // Skip if already close enough (within 5%)
        if ($ratio >= 0.95 && $ratio <= 1.05) {
            return;
        }

        // Build the filter chain
        if ($ratio < 1.0) {
            // Audio is too short — slow down to fill the slot
            $effectiveRatio = max($ratio, $minTempo);
            $filter = 'atempo=' . number_format($effectiveRatio, 4, '.', '');
        } elseif ($ratio <= $maxTempo) {
            // Moderate speedup — atempo alone is enough
            $filter = $this->buildAtempoChain($ratio);
        } else {
            // Audio is way too long — apply max safe atempo, then trim to fit
            $atempoChain = $this->buildAtempoChain($maxTempo);
            $fadeOut = max(0.05, min(0.3, $slotDuration * 0.1));
            $fadeStart = max(0, $slotDuration - $fadeOut);
            $filter = "{$atempoChain},atrim=0:{$slotDuration},asetpts=PTS-STARTPTS,afade=t=out:st={$fadeStart}:d={$fadeOut}";

            Log::warning('TTS audio too long for slot, applying atempo+trim', [
                'path' => $audioPath,
                'audio_duration' => $audioDuration,
                'slot_duration' => $slotDuration,
                'ratio' => round($ratio, 3),
                'effective_tempo' => $maxTempo,
            ]);
        }

        if ($filter === '') {
            return;
        }

        $tmpPath = $audioPath . '.fitted.wav';

        $result = Process::timeout(30)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $audioPath,
            '-af', $filter,
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
            $tmpPath,
        ]);

        if ($result->successful() && file_exists($tmpPath) && filesize($tmpPath) > 0) {
            rename($tmpPath, $audioPath);

            Log::info('Fitting TTS audio to slot', [
                'path' => $audioPath,
                'original_duration' => $audioDuration,
                'slot_duration' => $slotDuration,
                'tempo_ratio' => round($ratio, 3),
                'direction' => $ratio < 1.0 ? 'slowdown' : 'speedup',
                'trimmed' => $ratio > $maxTempo,
                'filter' => $filter,
            ]);
        } else {
            @unlink($tmpPath);
            Log::warning('atempo fitting failed', [
                'path' => $audioPath,
                'error' => $result->errorOutput(),
            ]);
        }
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
     * Strip leading and trailing silence from TTS audio.
     * Edge TTS often pads output with several seconds of silence.
     */
    protected function stripSilence(string $audioPath): void
    {
        $tmpPath = $audioPath . '.trimmed.wav';

        // silenceremove: strip leading silence, then reverse+strip trailing silence
        // -40dB threshold catches Edge TTS low-level noise padding
        $result = Process::timeout(15)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $audioPath,
            '-af', 'silenceremove=start_periods=1:start_threshold=-40dB:start_duration=0.02,areverse,silenceremove=start_periods=1:start_threshold=-40dB:start_duration=0.02,areverse',
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
            $tmpPath,
        ]);

        if ($result->successful() && file_exists($tmpPath) && filesize($tmpPath) > 1000) {
            $origSize = filesize($audioPath);
            $newSize = filesize($tmpPath);

            // Accept trimmed version if it's smaller (any amount of silence removed helps)
            if ($newSize < $origSize) {
                Log::info('Stripped silence from TTS audio', [
                    'path' => $audioPath,
                    'original_size' => $origSize,
                    'trimmed_size' => $newSize,
                    'reduced_pct' => round((1 - $newSize / $origSize) * 100, 1),
                ]);
                rename($tmpPath, $audioPath);
                return;
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
     * Detect emotion from text using simple heuristics.
     * Analyzes punctuation, keywords, and patterns to guess emotion.
     */
    protected function detectEmotionFromText(string $originalText, string $translatedText): string
    {
        $text = mb_strtolower($originalText . ' ' . $translatedText);

        // Check for strong emotions first

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

        // Questions are slightly varied
        if (str_contains($originalText, '?')) {
            return 'neutral'; // Questions stay neutral but could be curious
        }

        return 'neutral';
    }
}
