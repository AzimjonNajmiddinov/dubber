<?php

namespace App\Jobs;

use App\Models\Speaker;
use App\Models\Video;
use App\Models\VideoSegment;
use App\Services\SpeakerTuning;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Transcribes audio for the streaming pipeline using WhisperX.
 * After transcription, dispatches ProcessSingleSegmentJob for the first segment.
 */
class TranscribeForStreamingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1200;
    public array $backoff = [30, 60, 120];

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return 'streaming_transcribe_' . $this->videoId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('TranscribeForStreamingJob failed', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        Video::where('id', $this->videoId)->update(['status' => 'transcription_failed']);
    }

    public function handle(): void
    {
        $lock = Cache::lock("video:{$this->videoId}:streaming_transcribe", 1200);
        if (!$lock->get()) {
            return;
        }

        try {
            $video = Video::findOrFail($this->videoId);

            $audioRel = $video->audio_path ?: "audio/stt/{$video->id}.wav";
            if (!str_starts_with($audioRel, "audio/stt/")) {
                $audioRel = "audio/stt/{$video->id}.wav";
            }

            if (!Storage::disk('local')->exists($audioRel)) {
                throw new \RuntimeException("Audio file not found: {$audioRel}");
            }

            $video->update(['status' => 'transcribing']);

            Log::info('Starting WhisperX transcription for streaming', [
                'video_id' => $video->id,
                'audio_path' => $audioRel,
            ]);

            // Call WhisperX service
            $res = Http::timeout(300)
                ->connectTimeout(5)
                ->retry(3, 500)
                ->post('http://whisperx:8000/analyze', [
                    'audio_path' => $audioRel,
                ]);

            if ($res->failed()) {
                throw new \RuntimeException('WhisperX request failed');
            }

            $data = $res->json();
            if (!is_array($data) || isset($data['error'])) {
                throw new \RuntimeException('WhisperX returned error');
            }

            if (!isset($data['segments']) || !is_array($data['segments'])) {
                throw new \RuntimeException('WhisperX returned no segments');
            }

            $segments = $data['segments'];
            $speakerMeta = $data['speakers'] ?? [];
            $now = now();

            // Create speakers and segments
            DB::transaction(function () use ($video, $segments, $speakerMeta, $now) {
                $tuner = app(SpeakerTuning::class);

                // Clear existing data (idempotent)
                VideoSegment::where('video_id', $video->id)->delete();
                Speaker::where('video_id', $video->id)->delete();

                $speakerIdByExternal = [];

                // Create speakers
                foreach ($speakerMeta as $external => $meta) {
                    $external = (string) $external;
                    if ($external === '' || $external === 'unknown') {
                        $external = 'SPEAKER_UNKNOWN';
                    }

                    $gender = is_array($meta) ? strtolower($meta['gender'] ?? 'unknown') : 'unknown';
                    if (!in_array($gender, ['male', 'female', 'unknown'])) {
                        $gender = 'unknown';
                    }

                    $speaker = new Speaker([
                        'video_id' => $video->id,
                        'external_key' => $external,
                        'label' => $external,
                        'gender' => $gender,
                        'age_group' => is_array($meta) ? ($meta['age_group'] ?? 'unknown') : 'unknown',
                        'emotion' => is_array($meta) ? ($meta['emotion'] ?? 'neutral') : 'neutral',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $tuner->applyDefaults($video, $speaker);
                    $speaker->save();
                    $speakerIdByExternal[$external] = $speaker->id;
                }

                // Create segments
                $rows = [];
                foreach ($segments as $seg) {
                    if (!is_array($seg)) continue;

                    $start = $seg['start'] ?? null;
                    $end = $seg['end'] ?? null;
                    $text = $seg['text'] ?? null;

                    if (!is_numeric($start) || !is_numeric($end) || !is_string($text)) continue;

                    $start = (float) $start;
                    $end = (float) $end;
                    $text = trim($text);

                    if ($text === '' || $start >= $end) continue;

                    $external = (string) ($seg['speaker'] ?? 'SPEAKER_UNKNOWN');
                    if ($external === '' || $external === 'unknown') {
                        $external = 'SPEAKER_UNKNOWN';
                    }

                    if (!isset($speakerIdByExternal[$external])) {
                        $speaker = new Speaker([
                            'video_id' => $video->id,
                            'external_key' => $external,
                            'label' => $external,
                            'gender' => 'unknown',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $tuner->applyDefaults($video, $speaker);
                        $speaker->save();
                        $speakerIdByExternal[$external] = $speaker->id;
                    }

                    $rows[] = [
                        'video_id' => $video->id,
                        'speaker_id' => $speakerIdByExternal[$external],
                        'start_time' => round($start, 3),
                        'end_time' => round($end, 3),
                        'text' => $text,
                        'translated_text' => null,
                        'tts_audio_path' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (!empty($rows)) {
                    VideoSegment::insert($rows);
                }

                $video->update(['status' => 'transcribed']);
            });

            Log::info('Transcription complete for streaming', [
                'video_id' => $video->id,
                'segment_count' => count($segments),
            ]);

            // Start processing first segment
            $firstSegment = $video->segments()->orderBy('start_time')->first();
            if ($firstSegment) {
                Log::info('Dispatching first segment for processing', [
                    'video_id' => $video->id,
                    'segment_id' => $firstSegment->id,
                ]);

                ProcessSingleSegmentJob::dispatch($firstSegment->id, true)
                    ->onQueue('segment-processing');
            }

        } finally {
            optional($lock)->release();
        }
    }
}
