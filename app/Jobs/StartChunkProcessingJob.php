<?php

namespace App\Jobs;

use App\Models\Speaker;
use App\Models\Video;
use App\Models\VideoSegment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves video source, runs WhisperX transcription, saves segments to DB,
 * and dispatches per-segment TTS jobs in parallel.
 *
 * New pipeline (segments = chunks):
 * 1. Extract audio → WhisperX full transcription
 * 2. Save Speaker + VideoSegment records to DB
 * 3. Dispatch SeparateStemsJob (background)
 * 4. Dispatch ProcessSegmentTtsJob × N (parallel on 'chunks' queue)
 */
class StartChunkProcessingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 2;
    public int $uniqueFor = 3600;

    private const VOICES_EDGE = [
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

    private const VOICES_UZBEKVOICE = [
        'uz' => [
            'male' => ['uz-UZ-SardorNeural'],
            'female' => ['uz-UZ-MadinaNeural'],
        ],
    ];

    private const PITCH_VARIATIONS = ['+0Hz', '-3Hz', '+4Hz', '-2Hz', '+2Hz', '-4Hz'];
    private const RATE_VARIATIONS = ['+0%', '+0%', '+0%', '+0%', '+0%'];

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return 'chunk_start_' . $this->videoId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('StartChunkProcessingJob failed', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        Video::where('id', $this->videoId)->update(['status' => 'failed']);
    }

    public function handle(): void
    {
        $video = Video::findOrFail($this->videoId);

        $hasLocalFile = $video->original_path && Storage::disk('local')->exists($video->original_path);

        if ($hasLocalFile) {
            $this->handleLocalFile($video);
        } elseif ($video->source_url) {
            $this->handleStreamUrl($video);
        } else {
            throw new \RuntimeException('No video source (no local file, no source URL)');
        }
    }

    /**
     * File upload path: local file exists, use it directly.
     */
    private function handleLocalFile(Video $video): void
    {
        $videoPath = Storage::disk('local')->path($video->original_path);
        $duration = $this->getMediaDuration($videoPath);

        if ($duration <= 0) {
            throw new \RuntimeException('Could not determine video duration');
        }

        $video->update(['duration' => $duration, 'status' => 'processing_chunks']);

        Log::info('Starting segment-based transcription', [
            'video_id' => $video->id,
            'duration' => $duration,
        ]);

        // Transcribe full audio via WhisperX and save segments to DB
        $segmentCount = $this->transcribeAndSaveSegments($video, $videoPath, $duration);

        if ($segmentCount === 0) {
            Log::info('No speech detected, marking as complete with original audio', [
                'video_id' => $video->id,
            ]);
            $video->update(['status' => 'dubbed_complete']);
            return;
        }

        // Extract full audio for Demucs stem separation
        $this->extractFullAudio($video);
        SeparateStemsJob::dispatch($video->id)->onQueue('default');
        Log::info('Stem separation dispatched to background', ['video_id' => $video->id]);

        // Dispatch per-segment TTS jobs
        $this->dispatchSegmentJobs($video);
    }

    /**
     * Stream flow: resolve direct CDN URLs, get duration remotely, dispatch segments.
     */
    private function handleStreamUrl(Video $video): void
    {
        $video->update(['status' => 'resolving_stream']);

        $streamUrls = $this->resolveStreamUrls($video->source_url);

        if (!$streamUrls['video']) {
            Log::warning('Stream URL resolution failed, falling back to full download', [
                'video_id' => $video->id,
            ]);
            $this->downloadAndProcess($video);
            return;
        }

        $duration = $this->getMediaDuration($streamUrls['video']);

        if ($duration <= 0) {
            Log::warning('Could not get duration from stream URL, falling back to full download', [
                'video_id' => $video->id,
            ]);
            $this->downloadAndProcess($video);
            return;
        }

        $video->update([
            'stream_url' => $streamUrls['video'],
            'stream_audio_url' => $streamUrls['audio'],
            'duration' => $duration,
            'status' => 'processing_chunks',
        ]);

        Log::info('Stream URLs resolved', [
            'video_id' => $video->id,
            'has_separate_audio' => $streamUrls['audio'] !== null,
            'duration' => $duration,
        ]);

        $audioSource = $streamUrls['audio'] ?? $streamUrls['video'];

        // Transcribe and save segments
        $segmentCount = $this->transcribeAndSaveSegments($video, $audioSource, $duration);

        if ($segmentCount === 0) {
            Log::info('No speech detected, marking as complete with original audio', [
                'video_id' => $video->id,
            ]);
            $video->update(['status' => 'dubbed_complete']);
            return;
        }

        // Start full download in background for stem separation
        BackgroundDownloadJob::dispatch($video->id)->onQueue('default');
        Log::info('Background download dispatched', ['video_id' => $video->id]);

        // Dispatch per-segment TTS jobs
        $this->dispatchSegmentJobs($video);
    }

    /**
     * Transcribe audio via WhisperX (batched for >1hr), save Speaker + VideoSegment records.
     *
     * @return int Number of segments saved
     */
    private function transcribeAndSaveSegments(Video $video, string $source, float $duration): int
    {
        // RunPod proxy has ~100s timeout. WhisperX+diarization on GPU takes ~60-90s
        // per 120s of audio. Keep batches ≤120s for remote, full audio for local.
        $whisperxUrl = config('services.whisperx.url', 'http://whisperx:8000');
        $isRemote = str_contains($whisperxUrl, 'runpod.net') || str_contains($whisperxUrl, 'https://');
        $maxBatchDuration = $isRemote ? 120.0 : 3600.0;

        Storage::disk('local')->makeDirectory('audio/stt');

        $batches = [];
        if ($duration <= $maxBatchDuration) {
            $batches[] = ['start' => 0.0, 'duration' => $duration];
        } else {
            $batchStart = 0.0;
            while ($batchStart < $duration) {
                $batchDur = min($maxBatchDuration, $duration - $batchStart);
                $batches[] = ['start' => $batchStart, 'duration' => $batchDur];
                $batchStart += $maxBatchDuration;
            }
        }

        Log::info('Batched transcription starting', [
            'video_id' => $video->id,
            'duration' => $duration,
            'batches' => count($batches),
        ]);

        $transcriptionStart = microtime(true);

        // Clear existing segments from previous runs
        $video->segments()->delete();
        $video->speakers()->delete();

        $totalSegments = 0;

        foreach ($batches as $batchIndex => $batch) {
            $batchStart = $batch['start'];
            $batchDuration = $batch['duration'];

            $audioPath = Storage::disk('local')->path("audio/stt/{$video->id}_batch_{$batchIndex}.wav");

            $extracted = $this->extractAudioSegment($source, $batchStart, $batchDuration, $audioPath);
            if (!$extracted) {
                Log::warning('Failed to extract batch audio', [
                    'video_id' => $video->id,
                    'batch' => $batchIndex,
                ]);
                continue;
            }

            $transcription = $this->callWhisperX($audioPath, $batchDuration);
            @unlink($audioPath);

            if (empty($transcription['segments'])) {
                Log::info('No speech in batch', [
                    'video_id' => $video->id,
                    'batch' => $batchIndex,
                    'batch_start' => $batchStart,
                ]);
                continue;
            }

            Log::info('WhisperX batch transcription result', [
                'video_id' => $video->id,
                'batch' => $batchIndex,
                'batch_start' => $batchStart,
                'segments' => count($transcription['segments']),
                'speakers' => count($transcription['speaker_meta']),
            ]);

            $saved = $this->saveTranscriptionToDb(
                $video,
                $transcription['segments'],
                $transcription['speaker_meta'],
                $batchStart
            );

            $totalSegments += $saved;
        }

        $elapsed = round(microtime(true) - $transcriptionStart, 1);
        Log::info('Transcription complete, segments saved', [
            'video_id' => $video->id,
            'batches' => count($batches),
            'total_segments' => $totalSegments,
            'elapsed_seconds' => $elapsed,
        ]);

        return $totalSegments;
    }

    /**
     * Save WhisperX transcription results to DB as Speaker + VideoSegment records.
     *
     * @return int Number of segments created
     */
    private function saveTranscriptionToDb(Video $video, array $segments, array $speakerMeta, float $batchOffset): int
    {
        $speakerMap = [];
        $count = 0;

        foreach ($segments as $seg) {
            $speakerKey = $seg['speaker'] ?? 'SPEAKER_00';

            // Create/find speaker
            if (!isset($speakerMap[$speakerKey])) {
                $meta = $speakerMeta[$speakerKey] ?? [];
                $speakerMap[$speakerKey] = $this->getOrCreateSpeaker($video, $speakerKey, $meta);
            }

            $globalStart = $batchOffset + $seg['start'];
            $globalEnd = $batchOffset + $seg['end'];

            VideoSegment::create([
                'video_id' => $video->id,
                'speaker_id' => $speakerMap[$speakerKey]->id,
                'start_time' => round($globalStart, 3),
                'end_time' => round($globalEnd, 3),
                'text' => $seg['text'],
                'emotion' => $seg['emotion'] ?? null,
            ]);

            $count++;
        }

        Log::info('Saved segments to DB', [
            'video_id' => $video->id,
            'segments' => $count,
            'speakers' => count($speakerMap),
        ]);

        return $count;
    }

    /**
     * Dispatch ProcessSegmentTtsJob for each segment on the 'chunks' queue.
     */
    private function dispatchSegmentJobs(Video $video): void
    {
        $segmentIds = $video->segments()->pluck('id');
        $dispatched = 0;
        foreach ($segmentIds as $segmentId) {
            ProcessSegmentTtsJob::dispatch($segmentId)->onQueue('chunks');
            $dispatched++;
        }

        Log::info('Dispatched segment TTS jobs', [
            'video_id' => $video->id,
            'dispatched' => $dispatched,
        ]);
    }

    /**
     * Get or create a Speaker for this video.
     * Replicates logic from ProcessVideoChunkJob::getOrCreateSpeaker().
     */
    private function getOrCreateSpeaker(Video $video, string $speakerKey, array $whisperxMeta = []): Speaker
    {
        $speaker = Speaker::where('video_id', $video->id)
            ->where('external_key', $speakerKey)
            ->first();

        if ($speaker) {
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

        $metaGender = $whisperxMeta['gender'] ?? 'unknown';
        $gender = ($metaGender !== 'unknown') ? $metaGender : ($num % 2 === 0 ? 'male' : 'female');

        $lang = strtolower($video->target_language);
        $ttsDriver = config('dubber.tts.default', 'edge');

        // Select voice pool based on active TTS driver
        if ($ttsDriver === 'uzbekvoice' && isset(self::VOICES_UZBEKVOICE[$lang])) {
            $voices = self::VOICES_UZBEKVOICE[$lang];
        } else {
            $voices = self::VOICES_EDGE[$lang] ?? self::VOICES_EDGE['en'];
        }
        $genderVoices = $voices[$gender] ?? $voices['male'];

        $existingCount = Speaker::where('video_id', $video->id)->where('gender', $gender)->count();
        $voice = $genderVoices[$existingCount % count($genderVoices)];
        $pitch = self::PITCH_VARIATIONS[$existingCount % count(self::PITCH_VARIATIONS)];
        $rate = self::RATE_VARIATIONS[$existingCount % count(self::RATE_VARIATIONS)];

        try {
            return Speaker::firstOrCreate(
                ['video_id' => $video->id, 'external_key' => $speakerKey],
                array_filter([
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
                    'tts_driver' => $ttsDriver,
                ], fn($v) => $v !== null)
            );
        } catch (\Illuminate\Database\QueryException $e) {
            return Speaker::where('video_id', $video->id)
                ->where(fn($q) => $q->where('external_key', $speakerKey)->orWhere('label', 'Speaker ' . ($num + 1)))
                ->first()
                ?? Speaker::firstOrCreate(
                    ['video_id' => $video->id, 'label' => 'Speaker ' . ($num + 1)],
                    ['external_key' => $speakerKey, 'gender' => $gender, 'emotion' => 'neutral',
                     'age_group' => 'unknown', 'tts_voice' => $voice, 'tts_pitch' => $pitch,
                     'tts_rate' => $rate, 'tts_driver' => $ttsDriver]
                );
        }
    }

    /**
     * Extract mono 16kHz audio segment for WhisperX transcription.
     */
    private function extractAudioSegment(string $source, float $start, float $duration, string $outputPath): bool
    {
        $result = Process::timeout(120)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-ss', (string)$start,
            '-i', $source,
            '-t', (string)$duration,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le',
            $outputPath,
        ]);

        return $result->successful() && file_exists($outputPath) && filesize($outputPath) > 1000;
    }

    /**
     * Call WhisperX service with an audio file.
     *
     * @return array{segments: array, speaker_meta: array}
     */
    private function callWhisperX(string $audioPath, float $audioDuration): array
    {
        try {
            $whisperxUrl = config('services.whisperx.url', 'http://whisperx:8000');
            $isRemote = str_contains($whisperxUrl, 'runpod.net') || str_contains($whisperxUrl, 'https://');

            // lite=1 skips gender/emotion ML (not diarization) for speed on RunPod.
            // Diarization always runs when enabled in WhisperX service.
            $extraParams = ['lite' => 1];
            if ($audioDuration > 60) {
                $extraParams['min_speakers'] = 2;
            }

            $timeout = (int) min(3600, max(300, $audioDuration * 2));

            if ($isRemote) {
                $response = Http::timeout($timeout)
                    ->attach('audio', file_get_contents($audioPath), basename($audioPath))
                    ->post("{$whisperxUrl}/analyze-upload", $extraParams);
            } else {
                $audioRel = str_replace(Storage::disk('local')->path(''), '', $audioPath);
                $response = Http::timeout($timeout)->post("{$whisperxUrl}/analyze", array_merge(
                    ['audio_path' => $audioRel],
                    $extraParams
                ));

                if ($response->status() === 404 && file_exists($audioPath)) {
                    $response = Http::timeout($timeout)
                        ->attach('audio', file_get_contents($audioPath), basename($audioPath))
                        ->post("{$whisperxUrl}/analyze-upload", $extraParams);
                }
            }

            if ($response->failed()) {
                Log::warning('WhisperX batch request failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
                return ['segments' => [], 'speaker_meta' => []];
            }

            if ($response->json('error')) {
                Log::warning('WhisperX batch returned error', [
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

            return ['segments' => $parsedSegments, 'speaker_meta' => $speakerMeta];

        } catch (\Exception $e) {
            Log::error('WhisperX batch transcription exception', ['error' => $e->getMessage()]);
            return ['segments' => [], 'speaker_meta' => []];
        }
    }

    private function extractFullAudio(Video $video): void
    {
        $videoPath = Storage::disk('local')->path($video->original_path);
        $audioRel = "audio/original/{$video->id}.wav";
        Storage::disk('local')->makeDirectory('audio/original');
        $audioPath = Storage::disk('local')->path($audioRel);

        if (file_exists($audioPath)) {
            return;
        }

        $result = Process::timeout(300)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $videoPath,
            '-vn', '-ac', '2', '-ar', '48000', '-c:a', 'pcm_s16le',
            $audioPath,
        ]);

        if ($result->successful() && file_exists($audioPath)) {
            Log::info('Full audio extracted', ['video_id' => $video->id]);
        }
    }

    private function getMediaDuration(string $pathOrUrl): float
    {
        $result = Process::timeout(30)->run([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $pathOrUrl,
        ]);

        return $result->successful() ? (float) trim($result->output()) : 0;
    }

    /**
     * Fallback: full download then process.
     */
    private function downloadAndProcess(Video $video): void
    {
        $this->downloadVideo($video);
        $video->refresh();

        if (!$video->original_path) {
            throw new \RuntimeException('Video download failed');
        }

        $this->handleLocalFile($video);
    }

    /**
     * Resolve direct stream URLs using yt-dlp --get-url.
     *
     * @return array{video: ?string, audio: ?string}
     */
    private function resolveStreamUrls(string $sourceUrl): array
    {
        $cookiesFile = $this->findCookiesFile();

        $cmd = ['yt-dlp', '--get-url', '-f', 'bv[height<=720]+ba/b[height<=720]/b'];

        if ($cookiesFile) {
            $cmd[] = '--cookies';
            $cmd[] = $cookiesFile;
        }

        $cmd[] = '--no-playlist';
        $cmd[] = $sourceUrl;

        $result = Process::timeout(60)->run($cmd);

        if (!$result->successful()) {
            Log::warning('yt-dlp --get-url failed', [
                'error' => substr($result->errorOutput(), -300),
            ]);
            return ['video' => null, 'audio' => null];
        }

        $urls = array_filter(array_map('trim', explode("\n", trim($result->output()))));

        if (count($urls) >= 2) {
            return ['video' => $urls[0], 'audio' => $urls[1]];
        } elseif (count($urls) === 1) {
            return ['video' => $urls[0], 'audio' => null];
        }

        return ['video' => null, 'audio' => null];
    }

    private function downloadVideo(Video $video): void
    {
        if (!$video->source_url) {
            throw new \RuntimeException('No source URL');
        }

        $video->update(['status' => 'downloading']);

        Storage::disk('local')->makeDirectory('videos/originals');
        $filename = \Illuminate\Support\Str::random(16) . '.mp4';
        $relativePath = "videos/originals/{$filename}";
        $absolutePath = Storage::disk('local')->path($relativePath);

        $url = $video->source_url;
        $isYouTube = str_contains($url, 'youtube.com') || str_contains($url, 'youtu.be');
        $isHLS = str_contains($url, '.m3u8');
        $isDASH = str_contains($url, '.mpd');

        $downloaded = false;
        $lastError = '';

        $cookiesFile = $this->findCookiesFile();

        if ($isYouTube) {
            $clients = ['default', 'mweb', 'web', 'android'];
            foreach ($clients as $client) {
                @unlink($absolutePath);

                $cmd = [
                    'yt-dlp',
                    '-f', 'bestvideo[height<=720][ext=mp4]+bestaudio[ext=m4a]/best[height<=720][ext=mp4]/best',
                    '--merge-output-format', 'mp4',
                    '-o', $absolutePath,
                    '--no-playlist',
                ];

                if ($cookiesFile) {
                    $cmd[] = '--cookies';
                    $cmd[] = $cookiesFile;
                }

                if ($client !== 'default') {
                    $cmd[] = '--extractor-args';
                    $cmd[] = "youtube:player_client={$client}";
                }

                $cmd[] = $url;

                $result = Process::timeout(1800)->run($cmd);

                if ($result->successful() && file_exists($absolutePath) && filesize($absolutePath) > 10000) {
                    $downloaded = true;
                    break;
                }

                $lastError = $result->errorOutput() ?: $result->output();
            }
        } elseif ($isHLS || $isDASH) {
            // Try yt-dlp first for HLS/DASH streams
            $result = Process::timeout(1800)->run([
                'yt-dlp',
                '-f', 'bestvideo+bestaudio/best',
                '--merge-output-format', 'mp4',
                '-o', $absolutePath,
                $url,
            ]);

            if ($result->successful() && file_exists($absolutePath) && filesize($absolutePath) > 10000) {
                $downloaded = true;
            } else {
                $lastError = $result->errorOutput() ?: $result->output();

                // Fallback: ffmpeg direct stream copy for DASH/HLS
                Log::info('yt-dlp failed for stream URL, trying ffmpeg', ['url' => $url]);
                @unlink($absolutePath);

                $ffResult = Process::timeout(3600)->run([
                    'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                    '-i', $url,
                    '-c', 'copy',
                    '-movflags', '+faststart',
                    $absolutePath,
                ]);

                if ($ffResult->successful() && file_exists($absolutePath) && filesize($absolutePath) > 10000) {
                    $downloaded = true;
                } else {
                    $lastError = $ffResult->errorOutput() ?: $lastError;
                }
            }
        } else {
            $result = Process::timeout(1800)->run([
                'yt-dlp',
                '-f', 'bestvideo[height<=720]+bestaudio/best[height<=720]/best',
                '--merge-output-format', 'mp4',
                '-o', $absolutePath,
                $url,
            ]);

            if ($result->successful() && file_exists($absolutePath) && filesize($absolutePath) > 10000) {
                $downloaded = true;
            } else {
                $lastError = $result->errorOutput() ?: $result->output();
            }
        }

        if (!$downloaded) {
            $downloaded = $this->downloadWithInvidious($url, $absolutePath);
        }

        if (!$downloaded) {
            $video->update(['status' => 'download_failed']);
            throw new \RuntimeException('Video download failed: ' . substr($lastError, -200));
        }

        $video->update([
            'original_path' => $relativePath,
            'status' => 'uploaded',
        ]);
    }

    private function downloadWithInvidious(string $url, string $outputPath): bool
    {
        $videoId = $this->extractYouTubeId($url);
        if (!$videoId) {
            return false;
        }

        $instances = [
            'https://inv.nadeko.net',
            'https://invidious.nerdvpn.de',
            'https://invidious.jing.rocks',
            'https://iv.nboez.com',
        ];

        foreach ($instances as $instance) {
            try {
                $response = Http::timeout(15)->get("{$instance}/api/v1/videos/{$videoId}");
                if (!$response->successful()) continue;

                $data = $response->json();
                $downloadUrl = null;

                foreach (($data['formatStreams'] ?? []) as $stream) {
                    if (!($stream['url'] ?? null) || !str_contains($stream['type'] ?? '', 'video/mp4')) continue;
                    $downloadUrl = $stream['url'];
                    if (str_contains($stream['qualityLabel'] ?? '', '720')) break;
                }

                if (!$downloadUrl) continue;

                Http::withOptions(['sink' => $outputPath, 'timeout' => 3600, 'connect_timeout' => 30])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get($downloadUrl);

                if (file_exists($outputPath) && filesize($outputPath) > 10000) {
                    return true;
                }
            } catch (\Throwable $e) {
                Log::warning('Invidious error', ['instance' => $instance, 'error' => $e->getMessage()]);
            }
        }

        return false;
    }

    private function extractYouTubeId(string $url): ?string
    {
        $patterns = [
            '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) return $m[1];
        }
        return null;
    }

    private function findCookiesFile(): ?string
    {
        $home = is_string($_SERVER['HOME'] ?? null) ? $_SERVER['HOME'] : (getenv('HOME') ?: '/home/' . get_current_user());

        $paths = [
            base_path('cookies.txt'),
            storage_path('app/cookies.txt'),
            $home . '/.config/yt-dlp/cookies.txt',
            $home . '/cookies.txt',
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && filesize($path) > 100) {
                return $path;
            }
        }

        return null;
    }
}
