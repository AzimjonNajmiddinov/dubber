<?php

namespace App\Jobs;

use App\Models\Speaker;
use App\Models\Video;
use App\Models\VideoSegment;
use App\Services\NaturalSpeechProcessor;
use App\Services\Tts\TtsManager;
use App\Traits\DetectsEnglish;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Generate TTS audio for a single segment.
 * Dispatched in parallel by GenerateTtsSegmentsJobV2 for speed.
 */
class GenerateTtsForSegmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use DetectsEnglish;

    public int $timeout = 120;
    public int $tries = 2;
    public array $backoff = [10, 30];

    public function __construct(
        public int $segmentId,
        public int $videoId,
    ) {}

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateTtsForSegmentJob failed', [
            'segment_id' => $this->segmentId,
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function handle(TtsManager $ttsManager): void
    {
        $seg = VideoSegment::with('speaker')->find($this->segmentId);
        if (!$seg) return;

        // Skip if already processed
        if ($seg->tts_audio_path && file_exists(Storage::disk('local')->path($seg->tts_audio_path))) {
            return;
        }

        $video = Video::find($this->videoId);
        if (!$video) return;

        $speaker = $seg->speaker;
        $text = trim((string) $seg->translated_text);
        if ($text === '') return;

        // Guard: avoid accidental English TTS
        if ($this->looksLikeEnglish($text)) {
            Log::error('TTS blocked: translated_text looks English', [
                'segment_id' => $seg->id,
                'sample' => mb_substr($text, 0, 180),
            ]);
            throw new \RuntimeException("Translated text looks English for segment {$seg->id}");
        }

        $driverName = config('dubber.tts.default', 'hybrid_uzbek');
        $driver = $ttsManager->driver($driverName);

        // Detect emotion
        $emotion = strtolower((string) ($seg->emotion ?: ($speaker?->emotion ?: '')));
        if (empty($emotion) || $emotion === 'neutral') {
            $emotion = $this->detectEmotionFromText($seg->text ?? '', $text);
        }

        $slotDuration = (float) $seg->end_time - (float) $seg->start_time;

        // Get direction
        $direction = strtolower((string) ($seg->direction ?? 'normal'));
        $validDirections = [
            'whisper', 'soft', 'normal', 'loud', 'shout',
            'breathy', 'tense', 'trembling', 'strained', 'pleading', 'matter_of_fact',
            'sarcastic', 'playful', 'cold', 'warm'
        ];
        if (!in_array($direction, $validDirections)) {
            $direction = 'normal';
        }

        $intent = strtolower((string) ($seg->intent ?? 'inform'));
        $audioSource = strtolower((string) ($seg->audio_source ?? 'direct'));
        if (!in_array($audioSource, ['direct', 'phone', 'tv', 'voiceover'])) {
            $audioSource = 'direct';
        }

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

        if ($speaker && $speaker->hasClonedVoice()) {
            $options['voice_id'] = $speaker->getVoiceIdForDriver($driver->name());
        }

        Log::info('TTS generating segment', [
            'video_id' => $video->id,
            'segment_id' => $seg->id,
            'driver' => $driverName,
            'emotion' => $emotion,
            'direction' => $direction,
            'slot_duration' => $slotDuration,
            'voice_cloned' => $speaker?->voice_cloned ?? false,
        ]);

        try {
            $outputPath = $driver->synthesize($text, $speaker, $seg, $options);

            // Post-synthesis: natural speech processing
            $naturalProcessor = app(NaturalSpeechProcessor::class);
            $naturalProcessor->process($outputPath, $actingDirection);

            // Fit audio to slot
            $this->fitAudioToSlot($outputPath, $slotDuration);

            $relPath = str_replace(Storage::disk('local')->path(''), '', $outputPath);

            $seg->update([
                'tts_audio_path' => $relPath,
                'tts_gain_db' => $options['gain_db'],
            ]);

            Log::info('TTS segment ready', [
                'segment_id' => $seg->id,
                'path' => $relPath,
            ]);

            GenerateSegmentVideoJob::dispatch($seg->id)->onQueue('default');

        } catch (\Throwable $e) {
            Log::error('TTS synthesis failed for segment', [
                'segment_id' => $seg->id,
                'error' => $e->getMessage(),
            ]);

            // Try fallback driver
            $fallbackName = config('dubber.tts.fallback');
            if ($fallbackName && $fallbackName !== $driver->name()) {
                $fallbackDriver = $ttsManager->driver($fallbackName);
                $outputPath = $fallbackDriver->synthesize($text, $speaker, $seg, $options);
                $this->fitAudioToSlot($outputPath, $slotDuration);
                $relPath = str_replace(Storage::disk('local')->path(''), '', $outputPath);
                $seg->update(['tts_audio_path' => $relPath, 'tts_gain_db' => $options['gain_db']]);
                GenerateSegmentVideoJob::dispatch($seg->id)->onQueue('default');
            } else {
                throw $e;
            }
        }
    }

    protected function fitAudioToSlot(string $audioPath, float $slotDuration): void
    {
        if ($slotDuration <= 0 || !file_exists($audioPath)) return;

        $this->stripSilence($audioPath);

        $audioDuration = $this->probeAudioDuration($audioPath);
        if ($audioDuration <= 0) return;

        $ratio = $audioDuration / $slotDuration;
        if ($ratio >= 0.88 && $ratio <= 1.12) return; // 12% tolerance — avoid unnecessary tempo changes

        $tempoFactor = min(1.5, max(0.85, $ratio)); // Max 1.5x to keep speech intelligible

        Log::info('TTS tempo fitting', [
            'path' => basename($audioPath),
            'audio' => round($audioDuration, 2),
            'slot' => round($slotDuration, 2),
            'tempo' => round($tempoFactor, 3),
        ]);

        $atempoChain = $this->buildAtempoChain($tempoFactor);
        if (empty($atempoChain)) return;

        $tmpPath = $audioPath . '.fitted.wav';
        $result = Process::timeout(30)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $audioPath, '-af', $atempoChain,
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le', $tmpPath,
        ]);

        if ($result->successful() && file_exists($tmpPath) && filesize($tmpPath) > 0) {
            rename($tmpPath, $audioPath);
        } else {
            @unlink($tmpPath);
        }
    }

    protected function stripSilence(string $audioPath): void
    {
        $tmpPath = $audioPath . '.trimmed.wav';
        $result = Process::timeout(15)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $audioPath,
            '-af', 'silenceremove=start_periods=1:start_threshold=-50dB:start_duration=0.1',
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le', $tmpPath,
        ]);

        if ($result->successful() && file_exists($tmpPath) && filesize($tmpPath) > 1000) {
            $origSize = filesize($audioPath);
            $newSize = filesize($tmpPath);
            $origDuration = $this->probeAudioDuration($audioPath);
            $newDuration = $this->probeAudioDuration($tmpPath);

            $sizeReductionPct = (1 - $newSize / $origSize) * 100;
            $durationReductionPct = $origDuration > 0 ? (1 - $newDuration / $origDuration) * 100 : 0;

            if ($sizeReductionPct <= 80 && $durationReductionPct <= 80 && $newDuration > 0.1) {
                rename($tmpPath, $audioPath);
                return;
            }
        }
        @unlink($tmpPath);
    }

    protected function probeAudioDuration(string $audioPath): float
    {
        $probe = Process::timeout(15)->run([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $audioPath,
        ]);
        return $probe->successful() ? (float) trim($probe->output()) : 0;
    }

    protected function buildAtempoChain(float $ratio): string
    {
        if (abs($ratio - 1.0) < 0.01) return '';

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
     * Detect emotion from text.
     */
    protected function detectEmotionFromText(string $originalText, string $translatedText): string
    {
        $text = mb_strtolower($originalText . ' ' . $translatedText);
        $original = $originalText;

        $scores = ['angry' => 0, 'happy' => 0, 'sad' => 0, 'fear' => 0, 'surprise' => 0, 'excited' => 0];

        if (substr_count($original, '!') >= 2) { $scores['angry'] += 2; $scores['excited'] += 2; }
        elseif (substr_count($original, '!') == 1) { $scores['excited'] += 1; }

        if (preg_match('/\?{2,}/', $original)) $scores['surprise'] += 3;
        if (preg_match('/\b[A-Z]{3,}\b/', $original)) { $scores['angry'] += 2; $scores['excited'] += 1; }

        if (preg_match('/\b(angry|mad|furious|hate|damn|hell|shut up)\b/i', $text)) $scores['angry'] += 3;
        if (preg_match('/\b(happy|joy|wonderful|amazing|love|great)\b/i', $text)) $scores['happy'] += 3;
        if (preg_match('/\b(sad|sorry|cry|tears|died|lost|grief)\b/i', $text)) $scores['sad'] += 3;
        if (preg_match('/\b(afraid|fear|scared|terrified|danger)\b/i', $text)) $scores['fear'] += 3;
        if (preg_match('/\b(what|really|wow|incredible|unbelievable)\b/i', $text)) $scores['surprise'] += 2;

        $maxScore = max($scores);
        if ($maxScore < 2) return 'neutral';

        return array_search($maxScore, $scores) ?: 'neutral';
    }

    protected function getEmotionIntensity(string $text, string $emotion): float
    {
        if ($emotion === 'neutral') return 0.0;
        $intensity = 0.5;
        if (substr_count($text, '!') >= 2) $intensity += 0.2;
        if (preg_match('/\b[A-Z]{3,}\b/', $text)) $intensity += 0.15;
        return min(1.0, $intensity);
    }

    protected function getVocalQualities(string $emotion, string $direction): array
    {
        $qualities = [];
        if (in_array($direction, ['whisper', 'soft', 'breathy'])) $qualities[] = 'breathy';
        if (in_array($direction, ['tense', 'strained'])) $qualities[] = 'tense';
        if (in_array($emotion, ['sad', 'tender'])) $qualities[] = 'warm';
        if (in_array($emotion, ['angry', 'excited'])) $qualities[] = 'sharp';
        return $qualities;
    }
}
