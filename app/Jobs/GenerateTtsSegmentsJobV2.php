<?php

namespace App\Jobs;

use App\Contracts\TtsDriverInterface;
use App\Models\Speaker;
use App\Models\TtsQualityMetric;
use App\Models\Video;
use App\Models\VideoSegment;
use App\Services\Tts\Drivers\HybridUzbekDriver;
use App\Services\Tts\TtsManager;
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

            // Step 1a: Always assign Voice DNA (profiles, rate, expressiveness)
            // so speakers sound different even without voice cloning
            $speakers = Speaker::where('video_id', $video->id)->get();
            if ($speakers->isNotEmpty()) {
                $this->assignVoiceDna($video, $speakers);
            }

            // Step 1b: Clone voices if enabled and driver supports it
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

            // Step 3: Dispatch per-segment TTS jobs in parallel
            // Each segment is processed by its own queue job for ~4x speedup
            $segmentList = $segments->values();
            $segmentCount = $segmentList->count();

            Log::info('Dispatching parallel TTS jobs', [
                'video_id' => $video->id,
                'segment_count' => $segmentCount,
            ]);

            foreach ($segmentList as $seg) {
                GenerateTtsForSegmentJob::dispatch($seg->id, $video->id)
                    ->onQueue('segment-generation');
            }

            // Poll until all segments have TTS audio (with timeout)
            $maxWait = 1800; // 30 min max
            $waited = 0;
            $pollInterval = 5;

            while ($waited < $maxWait) {
                sleep($pollInterval);
                $waited += $pollInterval;

                $done = VideoSegment::where('video_id', $video->id)
                    ->whereNotNull('tts_audio_path')
                    ->count();

                if ($done >= $segmentCount) {
                    break;
                }

                // Log progress every 30s
                if ($waited % 30 === 0) {
                    Log::info('TTS progress', [
                        'video_id' => $video->id,
                        'done' => $done,
                        'total' => $segmentCount,
                        'waited' => $waited,
                    ]);
                }
            }

            // Verify all segments completed
            $completedCount = VideoSegment::where('video_id', $video->id)
                ->whereNotNull('tts_audio_path')
                ->count();

            if ($completedCount < $segmentCount) {
                throw new \RuntimeException(
                    "TTS generation incomplete: {$completedCount}/{$segmentCount} segments after {$waited}s"
                );
            }

            Log::info('TTS generation complete', [
                'video_id' => $video->id,
                'segments_processed' => $completedCount,
                'driver' => $driverName,
                'total_wait' => $waited,
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
