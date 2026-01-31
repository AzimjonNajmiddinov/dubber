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

class TranscribeWithWhisperXJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1200;

    /**
     * Exponential backoff between retries (seconds).
     */
    public array $backoff = [30, 60, 120];

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    /**
     * Handle job failure - mark video as failed.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TranscribeWithWhisperXJob failed permanently', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $video = Video::find($this->videoId);
            if ($video) {
                $video->update(['status' => 'transcription_failed']);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to update video status after TranscribeWithWhisperXJob failure', [
                'video_id' => $this->videoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(): void
    {
        $lock = Cache::lock("video:{$this->videoId}:transcribe", 1200);
        if (! $lock->get()) {
            return;
        }

        try {
            /** @var Video $video */
            $video = Video::query()->findOrFail($this->videoId);

            // 1) Resolve STT wav path deterministically
            $audioRel = $video->audio_path ?: "audio/stt/{$video->id}.wav";

            // Strong guardrail: WhisperX must run on STT wav, not original bed.
            // If you *really* need other inputs, loosen this, but for correctness keep it strict.
            if (!str_starts_with($audioRel, "audio/stt/")) {
                Log::warning("WhisperX input not under audio/stt; forcing to audio/stt/{id}.wav", [
                    'video_id' => $video->id,
                    'audio_path_db' => $video->audio_path,
                    'forced' => "audio/stt/{$video->id}.wav",
                ]);
                $audioRel = "audio/stt/{$video->id}.wav";
            }

            if (!Storage::disk('local')->exists($audioRel)) {
                throw new \RuntimeException("Audio file not found for WhisperX: {$audioRel}");
            }

            $audioAbs = Storage::disk('local')->path($audioRel);

            // Debug metadata of the exact file sent to WhisperX
            $md5 = @md5_file($audioAbs) ?: null;
            $size = @filesize($audioAbs) ?: null;

            // Optional duration check via ffprobe if available in container
            $dur = null;
            try {
                $cmd = "ffprobe -hide_banner -loglevel error -show_entries format=duration -of default=nw=1:nk=1 " . escapeshellarg($audioAbs);
                $out = @shell_exec($cmd);
                if (is_string($out)) {
                    $dur = (float) trim($out);
                    if ($dur <= 0) $dur = null;
                }
            } catch (\Throwable $e) {
                // ignore
            }

            Log::info('WhisperX analyze request', [
                'video_id' => $video->id,
                'audio_rel' => $audioRel,
                'audio_abs' => $audioAbs,
                'audio_size' => $size,
                'audio_md5' => $md5,
                'audio_duration' => $dur,
            ]);

            // 2) Call WhisperX service
            $whisperxUrl = config('services.whisperx.url', 'http://whisperx:8000');
            $res = Http::timeout(300)
                ->connectTimeout(5)
                ->retry(5, 500)
                ->post("{$whisperxUrl}/analyze", [
                    'audio_path' => $audioRel, // RELATIVE to /var/www/storage/app inside whisperx container
                ]);

            if ($res->failed()) {
                Log::error('WhisperX HTTP failed', [
                    'video_id' => $video->id,
                    'http_status' => $res->status(),
                    'body' => mb_substr($res->body(), 0, 4000),
                ]);
                throw new \RuntimeException('WhisperX request failed (HTTP)');
            }

            $data = $res->json();

            if (!is_array($data)) {
                Log::error('WhisperX invalid JSON', [
                    'video_id' => $video->id,
                    'http_status' => $res->status(),
                    'body' => mb_substr($res->body(), 0, 4000),
                ]);
                throw new \RuntimeException('WhisperX returned invalid JSON');
            }

            if (isset($data['error'])) {
                Log::error('WhisperX returned error payload', [
                    'video_id' => $video->id,
                    'error' => $data['error'] ?? null,
                    'message' => $data['message'] ?? null,
                ]);
                throw new \RuntimeException('WhisperX error payload');
            }

            if (!isset($data['segments']) || !is_array($data['segments'])) {
                Log::error('WhisperX missing segments', [
                    'video_id' => $video->id,
                    'keys' => array_keys($data),
                ]);
                throw new \RuntimeException('WhisperX missing segments');
            }

            $segments = $data['segments'];
            $speakerMeta = (isset($data['speakers']) && is_array($data['speakers'])) ? $data['speakers'] : [];
            $now = now();

            DB::transaction(function () use ($video, $segments, $speakerMeta, $now) {
                $tuner = app(SpeakerTuning::class);

                // full replace (idempotent)
                VideoSegment::where('video_id', $video->id)->delete();
                Speaker::where('video_id', $video->id)->delete();

                $speakerIdByExternal = [];

                // 1) speakers from meta
                foreach ($speakerMeta as $external => $meta) {
                    $external = (string) $external;
                    if ($external === '' || $external === 'unknown') {
                        $external = 'SPEAKER_UNKNOWN';
                    }

                    $gender = is_array($meta) ? ($meta['gender'] ?? 'unknown') : 'unknown';
                    $gender = is_string($gender) ? strtolower($gender) : 'unknown';
                    if (!in_array($gender, ['male', 'female', 'unknown'], true)) {
                        $gender = 'unknown';
                    }

                    $ageGroup = is_array($meta) ? ($meta['age_group'] ?? 'unknown') : 'unknown';
                    $ageGroup = is_string($ageGroup) ? strtolower($ageGroup) : 'unknown';

                    $emotion = is_array($meta) ? ($meta['emotion'] ?? 'neutral') : 'neutral';
                    $emotion = is_string($emotion) ? strtolower($emotion) : 'neutral';

                    $genderConf = is_array($meta) ? ($meta['gender_confidence'] ?? null) : null;
                    $genderConf = is_numeric($genderConf) ? (float) $genderConf : null;

                    $emotionConf = is_array($meta) ? ($meta['emotion_confidence'] ?? null) : null;
                    $emotionConf = is_numeric($emotionConf) ? (float) $emotionConf : null;

                    $pitchMed = is_array($meta) ? ($meta['pitch_median_hz'] ?? null) : null;
                    $pitchMed = is_numeric($pitchMed) ? (float) $pitchMed : null;

                    $speaker = new Speaker([
                        'video_id' => $video->id,
                        'external_key' => $external,
                        'label' => $external,

                        'gender' => $gender,
                        'age_group' => $ageGroup,
                        'emotion' => $emotion,
                        'gender_confidence' => $genderConf,
                        'emotion_confidence' => $emotionConf,
                        'pitch_median_hz' => $pitchMed,

                        'tts_voice' => null,
                        'tts_gain_db' => 0,
                        'tts_rate' => '+0%',
                        'tts_pitch' => '+0Hz',

                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $tuner->applyDefaults($video, $speaker);
                    $speaker->save();

                    $speakerIdByExternal[$external] = $speaker->id;
                }

                // 1b) If there's only one real speaker and SPEAKER_UNKNOWN exists,
                // copy gender from the real speaker to SPEAKER_UNKNOWN
                $realSpeakers = collect($speakerMeta)->filter(function ($meta, $key) {
                    $key = (string) $key;
                    return $key !== 'SPEAKER_UNKNOWN' && $key !== 'unknown' && $key !== '';
                });

                if ($realSpeakers->count() === 1 && isset($speakerIdByExternal['SPEAKER_UNKNOWN'])) {
                    $mainSpeakerMeta = $realSpeakers->first();
                    $mainGender = is_array($mainSpeakerMeta) ? ($mainSpeakerMeta['gender'] ?? 'unknown') : 'unknown';

                    if ($mainGender !== 'unknown') {
                        $unknownSpeaker = Speaker::find($speakerIdByExternal['SPEAKER_UNKNOWN']);
                        if ($unknownSpeaker && $unknownSpeaker->gender === 'unknown') {
                            $unknownSpeaker->gender = $mainGender;
                            $tuner->applyDefaults($video, $unknownSpeaker);
                            $unknownSpeaker->save();

                            Log::info('Inherited gender for SPEAKER_UNKNOWN from main speaker', [
                                'video_id' => $video->id,
                                'inherited_gender' => $mainGender,
                            ]);
                        }
                    }
                }

                // 2) segments
                $rows = [];

                foreach ($segments as $seg) {
                    if (!is_array($seg)) continue;

                    $start = $seg['start'] ?? null;
                    $end   = $seg['end'] ?? null;
                    $text  = $seg['text'] ?? null;

                    if (!is_numeric($start) || !is_numeric($end) || !is_string($text)) continue;

                    $start = (float) $start;
                    $end   = (float) $end;
                    $text  = trim($text);

                    if ($text === '' || $start >= $end) continue;

                    $external = (string) ($seg['speaker'] ?? 'SPEAKER_UNKNOWN');
                    if ($external === '' || $external === 'unknown') $external = 'SPEAKER_UNKNOWN';

                    if (!isset($speakerIdByExternal[$external])) {
                        $speaker = new Speaker([
                            'video_id' => $video->id,
                            'external_key' => $external,
                            'label' => $external,
                            'gender' => 'unknown',
                            'age_group' => 'unknown',
                            'emotion' => 'neutral',
                            'gender_confidence' => null,
                            'emotion_confidence' => null,
                            'pitch_median_hz' => null,
                            'tts_voice' => null,
                            'tts_gain_db' => 0,
                            'tts_rate' => '+0%',
                            'tts_pitch' => '+0Hz',
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

                        'gender' => null,
                        'emotion' => null,

                        'translated_text' => null,
                        'tts_audio_path' => null,
                        'tts_gain_db' => null,
                        'tts_lufs' => null,

                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (!empty($rows)) {
                    VideoSegment::insert($rows);
                }

                $video->update(['status' => 'transcribed']);
            });

            TranslateAudioJob::dispatch($video->id);

        } finally {
            optional($lock)->release();
        }
    }
}
