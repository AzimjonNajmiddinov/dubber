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

            // Split long audio into chunks to avoid proxy timeouts
            if ($dur !== null && $dur > 120) {
                Log::info('Audio exceeds 120s, splitting into chunks', [
                    'video_id' => $video->id,
                    'duration' => $dur,
                ]);

                $chunks = $this->splitAudioIntoChunks($audioAbs, $dur);

                try {
                    $chunkResults = [];
                    foreach ($chunks as $i => [$chunkPath, $offset]) {
                        Log::info("Transcribing chunk {$i}", [
                            'video_id' => $video->id,
                            'chunk_path' => $chunkPath,
                            'offset' => $offset,
                        ]);
                        $chunkResults[] = [
                            'data' => $this->transcribeChunk($chunkPath, $whisperxUrl),
                            'offset' => $offset,
                        ];
                    }

                    $merged = $this->mergeChunkResults($chunkResults);
                    $segments = $merged['segments'];
                    $speakerMeta = $merged['speakers'];
                } finally {
                    $this->cleanupChunks(array_map(fn($c) => $c[0], $chunks));
                }
            } else {
                // Short audio — single request (original flow)
                $res = Http::timeout(300)
                    ->connectTimeout(5)
                    ->retry(5, 500)
                    ->post("{$whisperxUrl}/analyze", [
                        'audio_path' => $audioRel,
                    ]);

                if ($res->status() === 404 && file_exists($audioAbs)) {
                    Log::info('WhisperX path not found, using file upload', [
                        'video_id' => $video->id,
                        'audio_path' => $audioRel,
                    ]);
                    $res = Http::timeout(300)
                        ->connectTimeout(5)
                        ->attach('audio', file_get_contents($audioAbs), basename($audioAbs))
                        ->post("{$whisperxUrl}/analyze-upload");
                }

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
            }
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

    /**
     * Split audio into ~120s chunks using ffmpeg.
     *
     * @return array<int, array{0: string, 1: float}> Array of [chunkPath, offsetSeconds]
     */
    private function splitAudioIntoChunks(string $audioAbs, float $duration): array
    {
        $chunkDir = Storage::disk('local')->path("audio/stt/chunks/{$this->videoId}");
        if (!is_dir($chunkDir)) {
            mkdir($chunkDir, 0755, true);
        }

        $chunkDuration = 120;
        $chunks = [];
        $offset = 0.0;
        $index = 0;

        while ($offset < $duration) {
            $chunkPath = "{$chunkDir}/chunk_{$index}.wav";
            $cmd = sprintf(
                'ffmpeg -y -ss %s -i %s -t %d -c:a pcm_s16le %s 2>&1',
                escapeshellarg((string) $offset),
                escapeshellarg($audioAbs),
                $chunkDuration,
                escapeshellarg($chunkPath)
            );

            $output = shell_exec($cmd);

            if (!file_exists($chunkPath) || filesize($chunkPath) === 0) {
                Log::error('Failed to create audio chunk', [
                    'video_id' => $this->videoId,
                    'chunk_index' => $index,
                    'cmd' => $cmd,
                    'output' => mb_substr((string) $output, 0, 2000),
                ]);
                throw new \RuntimeException("Failed to split audio chunk {$index}");
            }

            $chunks[] = [$chunkPath, $offset];
            $offset += $chunkDuration;
            $index++;
        }

        Log::info('Split audio into chunks', [
            'video_id' => $this->videoId,
            'chunk_count' => count($chunks),
            'duration' => $duration,
        ]);

        return $chunks;
    }

    /**
     * Send a single audio chunk to WhisperX via file upload.
     *
     * @return array WhisperX response data
     */
    private function transcribeChunk(string $chunkPath, string $whisperxUrl): array
    {
        $res = Http::timeout(300)
            ->connectTimeout(5)
            ->attach('audio', file_get_contents($chunkPath), basename($chunkPath))
            ->post("{$whisperxUrl}/analyze-upload");

        if ($res->failed()) {
            throw new \RuntimeException("WhisperX chunk request failed (HTTP {$res->status()})");
        }

        $data = $res->json();

        if (!is_array($data)) {
            throw new \RuntimeException('WhisperX chunk returned invalid JSON');
        }

        if (isset($data['error'])) {
            throw new \RuntimeException('WhisperX chunk error: ' . ($data['error'] ?? 'unknown'));
        }

        if (!isset($data['segments']) || !is_array($data['segments'])) {
            throw new \RuntimeException('WhisperX chunk missing segments');
        }

        return $data;
    }

    /**
     * Merge results from multiple chunks: offset timestamps and deduplicate speakers.
     *
     * @param array $chunkResults Array of ['data' => whisperxResponse, 'offset' => float]
     * @return array{segments: array, speakers: array}
     */
    private function mergeChunkResults(array $chunkResults): array
    {
        $allSegments = [];
        // Collect all speaker metadata keyed by "chunkIndex:localSpeakerId"
        $allSpeakerMeta = []; // globalKey => meta
        // Map from chunk-local speaker ID to global speaker ID
        $speakerRemap = []; // "chunkIndex:localId" => globalId

        // First pass: collect all speaker metadata from all chunks
        $chunkSpeakers = [];
        foreach ($chunkResults as $ci => $cr) {
            $speakers = (isset($cr['data']['speakers']) && is_array($cr['data']['speakers']))
                ? $cr['data']['speakers']
                : [];
            $chunkSpeakers[$ci] = $speakers;
        }

        // Build global speaker list by matching across chunks using gender + pitch proximity
        $globalSpeakers = []; // globalId => ['gender' => ..., 'pitch' => ..., 'meta' => ...]
        $nextGlobalId = 0;

        foreach ($chunkSpeakers as $ci => $speakers) {
            foreach ($speakers as $localId => $meta) {
                $localId = (string) $localId;
                if ($localId === '' || $localId === 'unknown') {
                    $localId = 'SPEAKER_UNKNOWN';
                }

                $gender = is_array($meta) ? strtolower($meta['gender'] ?? 'unknown') : 'unknown';
                $pitch = is_array($meta) ? ($meta['pitch_median_hz'] ?? null) : null;
                $pitch = is_numeric($pitch) ? (float) $pitch : null;

                // Try to match to an existing global speaker
                $matchedGlobal = null;
                foreach ($globalSpeakers as $gid => $gs) {
                    // Gender must match (or one is unknown)
                    $genderMatch = ($gender === $gs['gender'])
                        || $gender === 'unknown'
                        || $gs['gender'] === 'unknown';

                    // Pitch must be within ±30Hz (if both available)
                    $pitchMatch = true;
                    if ($pitch !== null && $gs['pitch'] !== null) {
                        $pitchMatch = abs($pitch - $gs['pitch']) <= 30;
                    }

                    if ($genderMatch && $pitchMatch) {
                        $matchedGlobal = $gid;
                        // Update pitch with more recent data if global had none
                        if ($gs['pitch'] === null && $pitch !== null) {
                            $globalSpeakers[$gid]['pitch'] = $pitch;
                        }
                        // Update gender if global was unknown
                        if ($gs['gender'] === 'unknown' && $gender !== 'unknown') {
                            $globalSpeakers[$gid]['gender'] = $gender;
                            $globalSpeakers[$gid]['meta']['gender'] = $gender;
                        }
                        break;
                    }
                }

                if ($matchedGlobal !== null) {
                    $globalId = "SPEAKER_{$matchedGlobal}";
                } else {
                    $gid = $nextGlobalId++;
                    $globalId = "SPEAKER_{$gid}";
                    $globalSpeakers[$gid] = [
                        'gender' => $gender,
                        'pitch' => $pitch,
                        'meta' => is_array($meta) ? $meta : [],
                    ];
                }

                $speakerRemap["{$ci}:{$localId}"] = $globalId;
            }
        }

        // Build merged speaker metadata
        $mergedSpeakerMeta = [];
        foreach ($globalSpeakers as $gid => $gs) {
            $mergedSpeakerMeta["SPEAKER_{$gid}"] = $gs['meta'];
        }

        // Second pass: merge segments with offset timestamps and remapped speakers
        foreach ($chunkResults as $ci => $cr) {
            $offset = $cr['offset'];
            $segments = $cr['data']['segments'] ?? [];

            foreach ($segments as $seg) {
                if (!is_array($seg)) continue;

                $localSpeaker = (string) ($seg['speaker'] ?? 'SPEAKER_UNKNOWN');
                if ($localSpeaker === '' || $localSpeaker === 'unknown') {
                    $localSpeaker = 'SPEAKER_UNKNOWN';
                }

                $remapKey = "{$ci}:{$localSpeaker}";
                $globalSpeaker = $speakerRemap[$remapKey] ?? $localSpeaker;

                // Ensure unknown speakers that weren't in chunk metadata also get mapped
                if (!isset($speakerRemap[$remapKey]) && !isset($mergedSpeakerMeta[$globalSpeaker])) {
                    $mergedSpeakerMeta[$globalSpeaker] = [];
                }

                $seg['start'] = round(((float) ($seg['start'] ?? 0)) + $offset, 3);
                $seg['end'] = round(((float) ($seg['end'] ?? 0)) + $offset, 3);
                $seg['speaker'] = $globalSpeaker;

                $allSegments[] = $seg;
            }
        }

        // Sort segments by start time
        usort($allSegments, fn($a, $b) => ($a['start'] ?? 0) <=> ($b['start'] ?? 0));

        Log::info('Merged chunk results', [
            'video_id' => $this->videoId,
            'total_segments' => count($allSegments),
            'global_speakers' => count($mergedSpeakerMeta),
        ]);

        return [
            'segments' => $allSegments,
            'speakers' => $mergedSpeakerMeta,
        ];
    }

    /**
     * Remove temporary chunk files and directory.
     */
    private function cleanupChunks(array $chunkPaths): void
    {
        foreach ($chunkPaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        $chunkDir = Storage::disk('local')->path("audio/stt/chunks/{$this->videoId}");
        if (is_dir($chunkDir)) {
            @rmdir($chunkDir); // only removes if empty
        }
    }
}
