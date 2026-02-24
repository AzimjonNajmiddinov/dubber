<?php

namespace App\Jobs;

use App\Models\Video;
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
 * Resolves video source and dispatches chunk processing jobs IMMEDIATELY.
 *
 * For URL-based videos: resolves direct stream URLs via yt-dlp --get-url,
 * gets duration via ffprobe, and dispatches chunks that stream directly from CDN.
 * Full download runs in background (BackgroundDownloadJob) for stem separation.
 *
 * For file uploads: uses local file directly (original behavior).
 */
class StartChunkProcessingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 2;
    public int $uniqueFor = 3600;

    private const DEFAULT_CHUNK_DURATION = 10;

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
            // File upload path — use local file directly
            $this->handleLocalFile($video);
        } elseif ($video->source_url) {
            // URL path — resolve stream URLs, dispatch chunks immediately
            $this->handleStreamUrl($video);
        } else {
            throw new \RuntimeException('No video source (no local file, no source URL)');
        }
    }

    /**
     * Original flow for file uploads: local file exists, use it directly.
     */
    private function handleLocalFile(Video $video): void
    {
        $videoPath = Storage::disk('local')->path($video->original_path);
        $duration = $this->getMediaDuration($videoPath);

        if ($duration <= 0) {
            throw new \RuntimeException('Could not determine video duration');
        }

        $video->update(['duration' => $duration, 'status' => 'processing_chunks']);

        $chunks = $this->calculateChunks($duration);

        Log::info('Starting batched transcription + chunk dispatch', [
            'video_id' => $video->id,
            'duration' => $duration,
            'total_chunks' => count($chunks),
        ]);

        // Batched WhisperX transcription + progressive chunk dispatch
        $this->transcribeAndDispatchBatched($video, $videoPath, $duration, $chunks);

        // Start stem separation in background (extract audio + Demucs)
        $this->extractFullAudio($video);
        SeparateStemsJob::dispatch($video->id)->onQueue('default');
        Log::info('Stem separation dispatched to background', ['video_id' => $video->id]);
    }

    /**
     * Stream flow: resolve direct CDN URLs, get duration remotely, dispatch chunks.
     * Full download happens in background via BackgroundDownloadJob.
     */
    private function handleStreamUrl(Video $video): void
    {
        $video->update(['status' => 'resolving_stream']);

        // Step 1: Resolve direct stream URL(s) via yt-dlp --get-url
        $streamUrls = $this->resolveStreamUrls($video->source_url);

        if (!$streamUrls['video']) {
            // yt-dlp --get-url failed — fall back to full download
            Log::warning('Stream URL resolution failed, falling back to full download', [
                'video_id' => $video->id,
            ]);
            $this->downloadAndProcess($video);
            return;
        }

        // Step 2: Get duration via ffprobe on the stream URL
        $duration = $this->getMediaDuration($streamUrls['video']);

        if ($duration <= 0) {
            Log::warning('Could not get duration from stream URL, falling back to full download', [
                'video_id' => $video->id,
            ]);
            $this->downloadAndProcess($video);
            return;
        }

        // Step 3: Store stream info on video
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

        // Step 4: Batched WhisperX transcription + progressive chunk dispatch
        $chunks = $this->calculateChunks($duration);
        $audioSource = $streamUrls['audio'] ?? $streamUrls['video'];

        Log::info('Starting batched transcription + chunk dispatch', [
            'video_id' => $video->id,
            'duration' => $duration,
            'total_chunks' => count($chunks),
        ]);

        $this->transcribeAndDispatchBatched($video, $audioSource, $duration, $chunks);

        // Step 5: Start full download in background for stem separation
        BackgroundDownloadJob::dispatch($video->id)->onQueue('default');
        Log::info('Background download dispatched', ['video_id' => $video->id]);
    }

    /**
     * Resolve direct stream URLs using yt-dlp --get-url.
     * Returns 1 URL for muxed streams, 2 URLs for separate video+audio (DASH).
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
            // Separate video + audio streams (DASH)
            return ['video' => $urls[0], 'audio' => $urls[1]];
        } elseif (count($urls) === 1) {
            // Muxed stream (video + audio in one URL)
            return ['video' => $urls[0], 'audio' => null];
        }

        return ['video' => null, 'audio' => null];
    }

    /**
     * Fallback: full download then process (original behavior).
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

    private function dispatchChunks(Video $video, float $duration): void
    {
        $chunks = $this->calculateChunks($duration);
        $video->update(['status' => 'processing_chunks']);

        Log::info('Dispatching chunk jobs', [
            'video_id' => $video->id,
            'duration' => $duration,
            'total_chunks' => count($chunks),
        ]);

        // Dispatch ALL chunks immediately for parallel processing
        foreach ($chunks as $index => $chunk) {
            ProcessVideoChunkJob::dispatch(
                $video->id,
                $index,
                $chunk['start'],
                $chunk['end']
            )->onQueue('chunks');
        }

        Log::info('All chunk jobs dispatched', [
            'video_id' => $video->id,
            'total_chunks' => count($chunks),
        ]);
    }

    /**
     * Transcribe audio in WhisperX batches and progressively dispatch chunk jobs.
     *
     * Short videos (< 10 min): single batch = full audio.
     * Long videos: ~120s batches to stay within RunPod proxy timeout.
     * After each batch, segments are split by chunk boundaries and written as JSONs,
     * then chunk jobs are dispatched so TTS processing starts while next batch transcribes.
     */
    private function transcribeAndDispatchBatched(Video $video, string $source, float $duration, array $chunks): void
    {
        $maxBatchDuration = 3600.0; // Split only if > 1 hour

        $chunkDir = "videos/chunks/{$video->id}";
        Storage::disk('local')->makeDirectory($chunkDir);
        Storage::disk('local')->makeDirectory('audio/stt');

        // Send full audio to WhisperX; split into 1-hour pieces only for very long videos
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
            'chunks' => count($chunks),
        ]);

        $transcriptionStart = microtime(true);

        foreach ($batches as $batchIndex => $batch) {
            $batchStart = $batch['start'];
            $batchDuration = $batch['duration'];
            $batchEnd = $batchStart + $batchDuration;

            // Extract STT audio for this batch
            $audioPath = Storage::disk('local')->path("audio/stt/{$video->id}_batch_{$batchIndex}.wav");

            $extracted = $this->extractAudioSegment($source, $batchStart, $batchDuration, $audioPath);
            if (!$extracted) {
                Log::warning('Failed to extract batch audio, chunks will fall back to per-chunk transcription', [
                    'video_id' => $video->id,
                    'batch' => $batchIndex,
                ]);
                $this->dispatchChunksInRange($video, $chunks, $batchStart, $batchEnd);
                continue;
            }

            // Call WhisperX
            $transcription = $this->callWhisperX($audioPath, $batchDuration);
            @unlink($audioPath);

            if (empty($transcription['segments'])) {
                Log::info('No speech in batch, dispatching chunks without pre-transcription', [
                    'video_id' => $video->id,
                    'batch' => $batchIndex,
                    'batch_start' => $batchStart,
                ]);
                $this->dispatchChunksInRange($video, $chunks, $batchStart, $batchEnd);
                continue;
            }

            Log::info('WhisperX batch transcription result', [
                'video_id' => $video->id,
                'batch' => $batchIndex,
                'batch_start' => $batchStart,
                'segments' => count($transcription['segments']),
                'speakers' => count($transcription['speaker_meta']),
            ]);

            // Split segments by chunk boundaries and write JSONs
            $this->splitSegmentsByChunks(
                $video,
                $transcription['segments'],
                $transcription['speaker_meta'],
                $chunks,
                $batchStart,
                $batchEnd
            );

            // Dispatch chunks in this batch's range (progressive — TTS starts while next batch transcribes)
            $this->dispatchChunksInRange($video, $chunks, $batchStart, $batchEnd);
        }

        $elapsed = round(microtime(true) - $transcriptionStart, 1);
        Log::info('Batched transcription complete', [
            'video_id' => $video->id,
            'batches' => count($batches),
            'elapsed_seconds' => $elapsed,
        ]);
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
     * Call WhisperX service with an audio file (replicates ProcessVideoChunkJob logic).
     *
     * @return array{segments: array, speaker_meta: array}
     */
    private function callWhisperX(string $audioPath, float $audioDuration): array
    {
        try {
            $whisperxUrl = config('services.whisperx.url', 'http://whisperx:8000');
            $isRemote = str_contains($whisperxUrl, 'runpod.net') || str_contains($whisperxUrl, 'https://');

            $extraParams = ['lite' => 1];
            if ($audioDuration > 60) {
                $extraParams['min_speakers'] = 2;
            }

            // Scale timeout: ~2x audio duration, min 300s, max 3600s
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

    /**
     * Split WhisperX segments by chunk boundaries and write per-chunk JSON files.
     * Segment timestamps from WhisperX are batch-relative (0 to batchDuration).
     * They are converted to chunk-local timestamps in the output JSONs.
     */
    private function splitSegmentsByChunks(Video $video, array $segments, array $speakerMeta, array $chunks, float $batchStart, float $batchEnd): void
    {
        $chunkDir = "videos/chunks/{$video->id}";

        foreach ($chunks as $index => $chunk) {
            $chunkStart = $chunk['start'];
            $chunkEnd = $chunk['end'];

            // Skip chunks outside this batch's range
            if ($chunkEnd <= $batchStart || $chunkStart >= $batchEnd) {
                continue;
            }

            // Find segments whose start falls within this chunk (no overlaps — each segment belongs to exactly one chunk)
            $chunkSegments = [];
            foreach ($segments as $seg) {
                // Convert batch-relative timestamps to global video time
                $segGlobalStart = $batchStart + $seg['start'];
                $segGlobalEnd = $batchStart + $seg['end'];

                // Assign segment to chunk containing its start time only
                if ($segGlobalStart < $chunkStart || $segGlobalStart >= $chunkEnd) {
                    continue;
                }

                // Convert to chunk-local timestamps (end may extend beyond chunk — TTS will handle)
                $chunkSegments[] = [
                    'start' => round($segGlobalStart - $chunkStart, 3),
                    'end' => round(min($chunkEnd - $chunkStart, $segGlobalEnd - $chunkStart), 3),
                    'text' => $seg['text'],
                    'speaker' => $seg['speaker'],
                    'emotion' => $seg['emotion'] ?? null,
                    'emotion_confidence' => $seg['emotion_confidence'] ?? null,
                ];
            }

            // Write JSON for this chunk (even if empty — signals "no speech" vs "not transcribed")
            $jsonPath = "{$chunkDir}/transcription_{$index}.json";
            Storage::disk('local')->put($jsonPath, json_encode([
                'segments' => $chunkSegments,
                'speaker_meta' => $speakerMeta,
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Dispatch ProcessVideoChunkJob for chunks whose start falls within [rangeStart, rangeEnd).
     */
    private function dispatchChunksInRange(Video $video, array $chunks, float $rangeStart, float $rangeEnd): void
    {
        $dispatched = 0;
        foreach ($chunks as $index => $chunk) {
            if ($chunk['start'] >= $rangeStart && $chunk['start'] < $rangeEnd) {
                ProcessVideoChunkJob::dispatch(
                    $video->id,
                    $index,
                    $chunk['start'],
                    $chunk['end']
                )->onQueue('chunks');
                $dispatched++;
            }
        }

        Log::info('Chunk jobs dispatched for batch range', [
            'video_id' => $video->id,
            'range' => "{$rangeStart}-{$rangeEnd}",
            'dispatched' => $dispatched,
        ]);
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

    private function calculateChunks(float $duration): array
    {
        $chunks = [];
        $start = 0;
        $chunkDuration = $this->getChunkDuration($duration);

        while ($start < $duration) {
            $end = min($start + $chunkDuration, $duration);
            $chunks[] = ['start' => $start, 'end' => $end];
            $start = $end;
        }

        return $chunks;
    }

    private function getChunkDuration(float $totalDuration): float
    {
        $envDuration = env('DUBBER_CHUNK_DURATION');
        if ($envDuration !== null && is_numeric($envDuration)) {
            return max(5, min(30, (float)$envDuration));
        }

        if ($totalDuration <= 60) {
            return 8;
        } elseif ($totalDuration <= 300) {
            return 10;
        } elseif ($totalDuration <= 1800) {
            return 12;
        } else {
            return 15;
        }
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
        } elseif ($isHLS) {
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
                $response = \Illuminate\Support\Facades\Http::timeout(15)->get("{$instance}/api/v1/videos/{$videoId}");
                if (!$response->successful()) continue;

                $data = $response->json();
                $downloadUrl = null;

                foreach (($data['formatStreams'] ?? []) as $stream) {
                    if (!($stream['url'] ?? null) || !str_contains($stream['type'] ?? '', 'video/mp4')) continue;
                    $downloadUrl = $stream['url'];
                    if (str_contains($stream['qualityLabel'] ?? '', '720')) break;
                }

                if (!$downloadUrl) continue;

                \Illuminate\Support\Facades\Http::withOptions(['sink' => $outputPath, 'timeout' => 3600, 'connect_timeout' => 30])
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
