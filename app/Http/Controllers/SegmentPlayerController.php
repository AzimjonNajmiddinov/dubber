<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateSegmentVideoJob;
use App\Models\Video;
use App\Models\VideoSegment;
use App\Services\SegmentVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SegmentPlayerController extends Controller
{
    public function __construct(
        private SegmentVideoService $segmentService
    ) {}

    /**
     * Render the segment player page.
     * For live streaming, allows rendering even when no segments exist yet.
     */
    public function player(Video $video)
    {
        $segments = $video->segments()->orderBy('start_time')->get();

        // For live streaming, we allow the player to load even with no segments
        // The frontend will poll for status and segments

        return view('player.segments', [
            'video' => $video,
            'segments' => $segments,
        ]);
    }

    /**
     * Return JSON manifest with all segments metadata.
     */
    public function manifest(Video $video): JsonResponse
    {
        $segments = $video->segments()->orderBy('start_time')->get();

        $segmentData = $segments->map(function ($segment) use ($video) {
            return [
                'id' => $segment->id,
                'start_time' => $segment->start_time,
                'end_time' => $segment->end_time,
                'duration' => $segment->end_time - $segment->start_time,
                'text' => $segment->text,
                'translated_text' => $segment->translated_text,
                'speaker_id' => $segment->speaker_id,
                'has_tts' => !empty($segment->tts_audio_path),
                'ready' => $this->segmentService->isSegmentReady($segment),
                'generating' => $this->segmentService->isSegmentGenerating($segment),
                'stream_url' => route('api.player.segment', [$video, $segment]),
                'status_url' => route('api.player.segment.status', [$video, $segment]),
            ];
        });

        return response()->json([
            'video_id' => $video->id,
            'total_segments' => $segments->count(),
            'segments' => $segmentData,
        ]);
    }

    /**
     * Stream a segment video (generate on-demand if not cached).
     */
    public function streamSegment(Video $video, VideoSegment $segment, Request $request): StreamedResponse
    {
        // Verify segment belongs to video
        if ($segment->video_id !== $video->id) {
            abort(404, 'Segment not found for this video');
        }

        // Get or generate segment
        $absolutePath = $this->segmentService->getOrGenerateSegment($segment);

        if (!$absolutePath || !file_exists($absolutePath)) {
            abort(500, 'Failed to generate segment video');
        }

        $fileSize = filesize($absolutePath);
        $mimeType = 'video/mp4';

        // Handle range requests for video streaming
        $start = 0;
        $end = $fileSize - 1;
        $statusCode = 200;

        $rangeHeader = $request->header('Range');
        if ($rangeHeader) {
            $statusCode = 206;

            // Parse range header
            preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches);
            $start = isset($matches[1]) ? (int)$matches[1] : 0;
            $end = isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;

            // Clamp values
            $start = max(0, min($start, $fileSize - 1));
            $end = max($start, min($end, $fileSize - 1));
        }

        $length = $end - $start + 1;

        $response = new StreamedResponse(function () use ($absolutePath, $start, $length) {
            $handle = fopen($absolutePath, 'rb');
            fseek($handle, $start);

            $bufferSize = 1024 * 1024; // 1MB chunks
            $remaining = $length;

            while ($remaining > 0 && !feof($handle)) {
                $readSize = min($bufferSize, $remaining);
                echo fread($handle, $readSize);
                $remaining -= $readSize;
                flush();
            }

            fclose($handle);
        }, $statusCode);

        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Content-Length', $length);
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->headers->set('Content-Disposition', 'inline; filename="segment_' . $segment->id . '.mp4"');

        if ($statusCode === 206) {
            $response->headers->set('Content-Range', "bytes {$start}-{$end}/{$fileSize}");
        }

        // CORS headers for cross-origin streaming
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Range');

        return $response;
    }

    /**
     * Trigger background generation of next segments.
     */
    public function prefetch(Video $video, Request $request): JsonResponse
    {
        $currentSegmentId = $request->input('current_segment_id');

        if (!$currentSegmentId) {
            // Start from first segment
            $currentSegment = $video->segments()->orderBy('start_time')->first();
        } else {
            $currentSegment = VideoSegment::find($currentSegmentId);
        }

        if (!$currentSegment || $currentSegment->video_id !== $video->id) {
            return response()->json(['error' => 'Segment not found'], 404);
        }

        $segmentsToPrefetch = $this->segmentService->getSegmentsToPrefetch($currentSegment);
        $dispatched = [];

        foreach ($segmentsToPrefetch as $segment) {
            GenerateSegmentVideoJob::dispatch($segment->id)
                ->onQueue('segment-generation');
            $dispatched[] = $segment->id;
        }

        return response()->json([
            'current_segment_id' => $currentSegment->id,
            'prefetch_dispatched' => $dispatched,
        ]);
    }

    /**
     * Check if a segment is ready.
     */
    public function segmentStatus(Video $video, VideoSegment $segment): JsonResponse
    {
        if ($segment->video_id !== $video->id) {
            return response()->json(['error' => 'Segment not found'], 404);
        }

        $ready = $this->segmentService->isSegmentReady($segment);
        $generating = $this->segmentService->isSegmentGenerating($segment);

        return response()->json([
            'segment_id' => $segment->id,
            'ready' => $ready,
            'generating' => $generating,
            'status' => $ready ? 'ready' : ($generating ? 'generating' : 'pending'),
            'stream_url' => $ready ? route('api.player.segment', [$video, $segment]) : null,
        ]);
    }

    /**
     * Download a single chunk by index.
     */
    public function downloadChunk(Video $video, int $index): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $chunkPath = "videos/chunks/{$video->id}/seg_{$index}.mp4";

        if (!Storage::disk('local')->exists($chunkPath)) {
            return response()->json(['error' => 'Chunk not ready yet'], 404);
        }

        $absolutePath = Storage::disk('local')->path($chunkPath);
        $fileSize = filesize($absolutePath);

        // Support range requests for video streaming
        $request = request();
        $rangeHeader = $request->header('Range');

        if (!$rangeHeader) {
            // Full download
            return response()->download($absolutePath, "chunk_{$index}.mp4", [
                'Content-Type' => 'video/mp4',
            ]);
        }

        // Partial content for streaming
        $start = 0;
        $end = $fileSize - 1;

        preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches);
        $start = isset($matches[1]) ? (int)$matches[1] : 0;
        $end = isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;

        $start = max(0, min($start, $fileSize - 1));
        $end = max($start, min($end, $fileSize - 1));
        $length = $end - $start + 1;

        $response = new StreamedResponse(function () use ($absolutePath, $start, $length) {
            $handle = fopen($absolutePath, 'rb');
            fseek($handle, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = min(1024 * 1024, $remaining);
                echo fread($handle, $chunk);
                $remaining -= $chunk;
                flush();
            }
            fclose($handle);
        }, 206);

        $response->headers->set('Content-Type', 'video/mp4');
        $response->headers->set('Content-Length', $length);
        $response->headers->set('Content-Range', "bytes {$start}-{$end}/{$fileSize}");
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }

    /**
     * Get download status - which chunks are ready.
     */
    public function downloadStatus(Video $video): JsonResponse
    {
        $segments = $video->segments()->orderBy('start_time')->get();
        $totalChunks = $segments->count();
        $readyChunks = 0;
        $chunks = [];

        // Check chunk files
        $chunkIndex = 0;
        foreach ($segments->groupBy(fn($s) => floor($s->start_time / 12)) as $group) {
            $chunkPath = "videos/chunks/{$video->id}/seg_{$chunkIndex}.mp4";
            $ready = Storage::disk('local')->exists($chunkPath);

            if ($ready) $readyChunks++;

            $chunks[] = [
                'index' => $chunkIndex,
                'ready' => $ready,
                'download_url' => $ready ? route('api.player.chunk.download', [$video, $chunkIndex]) : null,
            ];

            $chunkIndex++;
        }

        // If no segments yet, estimate based on video duration
        if ($totalChunks === 0) {
            $totalChunks = $this->estimateChunkCount($video);
        }

        $canDownloadFull = $readyChunks > 0 && $readyChunks === count($chunks);

        return response()->json([
            'video_id' => $video->id,
            'status' => $video->status,
            'total_chunks' => count($chunks) ?: $totalChunks,
            'ready_chunks' => $readyChunks,
            'progress' => count($chunks) > 0 ? round(($readyChunks / count($chunks)) * 100) : 0,
            'can_download_full' => $canDownloadFull,
            'full_download_url' => $canDownloadFull ? route('api.player.download', $video) : null,
            'chunks' => $chunks,
        ]);
    }

    /**
     * Download full concatenated video.
     */
    public function downloadFull(Video $video): BinaryFileResponse|JsonResponse
    {
        // Check if already concatenated
        $outputPath = "videos/dubbed/{$video->id}_dubbed.mp4";

        if (!Storage::disk('local')->exists($outputPath)) {
            // Concatenate all chunks
            $success = $this->concatenateChunks($video, $outputPath);

            if (!$success) {
                return response()->json(['error' => 'Failed to create full video'], 500);
            }
        }

        $absolutePath = Storage::disk('local')->path($outputPath);

        return response()->download(
            $absolutePath,
            "dubbed_video_{$video->id}.mp4",
            ['Content-Type' => 'video/mp4']
        );
    }

    /**
     * Concatenate all chunks into a single video.
     */
    private function concatenateChunks(Video $video, string $outputPath): bool
    {
        Storage::disk('local')->makeDirectory('videos/dubbed');

        // Find all chunk files
        $chunkDir = "videos/chunks/{$video->id}";
        $chunkDirAbs = Storage::disk('local')->path($chunkDir);

        if (!is_dir($chunkDirAbs)) {
            return false;
        }

        // Get chunks in order
        $chunks = [];
        $index = 0;
        while (true) {
            $chunkFile = "{$chunkDirAbs}/seg_{$index}.mp4";
            if (!file_exists($chunkFile)) break;
            $chunks[] = $chunkFile;
            $index++;
        }

        if (empty($chunks)) {
            return false;
        }

        // Create concat file
        $concatFile = "{$chunkDirAbs}/concat.txt";
        $content = implode("\n", array_map(fn($f) => "file '" . basename($f) . "'", $chunks));
        file_put_contents($concatFile, $content);

        // Concatenate with ffmpeg
        $outputAbs = Storage::disk('local')->path($outputPath);

        $result = Process::timeout(600)->path($chunkDirAbs)->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'concat', '-safe', '0',
            '-i', 'concat.txt',
            '-c', 'copy',
            '-movflags', '+faststart',
            $outputAbs,
        ]);

        @unlink($concatFile);

        return $result->successful() && file_exists($outputAbs);
    }

    private function estimateChunkCount(Video $video): int
    {
        if (!$video->original_path || !Storage::disk('local')->exists($video->original_path)) {
            return 0;
        }

        $path = Storage::disk('local')->path($video->original_path);
        $result = Process::timeout(10)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);

        $duration = (float)trim($result->output());
        return (int)ceil($duration / 12); // 12-second chunks
    }

    /**
     * Generate HLS playlist (m3u8) with ready segments.
     * Updates dynamically as segments become ready.
     */
    public function hlsPlaylist(Video $video): \Illuminate\Http\Response
    {
        $segments = $video->segments()->orderBy('start_time')->get();

        // Calculate target duration (max segment length)
        $targetDuration = 10;
        foreach ($segments as $seg) {
            $duration = $seg->end_time - $seg->start_time;
            if ($duration > $targetDuration) {
                $targetDuration = ceil($duration);
            }
        }

        // Check which segments are ready
        $readySegments = [];
        $allReady = true;
        $hasAnyReady = false;

        foreach ($segments as $seg) {
            $isReady = $this->segmentService->isSegmentReady($seg);
            if ($isReady) {
                $hasAnyReady = true;
                $readySegments[] = $seg;
            } else {
                $allReady = false;
                // Stop at first non-ready segment for continuous playback
                break;
            }
        }

        // Build m3u8 playlist
        $lines = [];
        $lines[] = '#EXTM3U';
        $lines[] = '#EXT-X-VERSION:3';
        $lines[] = '#EXT-X-TARGETDURATION:' . (int)$targetDuration;
        $lines[] = '#EXT-X-MEDIA-SEQUENCE:0';

        // If still processing, mark as live playlist (EVENT type allows appending)
        if (!$allReady) {
            $lines[] = '#EXT-X-PLAYLIST-TYPE:EVENT';
        }

        foreach ($readySegments as $seg) {
            $duration = round($seg->end_time - $seg->start_time, 3);
            $lines[] = '#EXTINF:' . $duration . ',';
            $lines[] = route('api.player.hls.segment', [$video, $seg]);
        }

        // Only add ENDLIST if all segments are ready
        if ($allReady && $segments->isNotEmpty()) {
            $lines[] = '#EXT-X-ENDLIST';
        }

        $content = implode("\n", $lines);

        return response($content, 200)
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', $allReady ? 'public, max-age=3600' : 'no-cache, no-store');
    }

    /**
     * Serve a segment as MPEG-TS for HLS playback.
     */
    public function hlsSegment(Video $video, VideoSegment $segment): StreamedResponse|JsonResponse
    {
        if ($segment->video_id !== $video->id) {
            return response()->json(['error' => 'Segment not found'], 404);
        }

        // Get or generate the segment video
        $mp4Path = $this->segmentService->getOrGenerateSegment($segment);

        if (!$mp4Path || !file_exists($mp4Path)) {
            return response()->json(['error' => 'Segment not ready'], 404);
        }

        // Check if we have a cached .ts version
        $tsPath = str_replace('.mp4', '.ts', $mp4Path);

        if (!file_exists($tsPath)) {
            // Convert MP4 to MPEG-TS on the fly
            $result = Process::timeout(60)->run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-i', $mp4Path,
                '-c:v', 'copy',
                '-c:a', 'aac',
                '-b:a', '128k',
                '-muxdelay', '0',
                '-f', 'mpegts',
                $tsPath,
            ]);

            if (!$result->successful() || !file_exists($tsPath)) {
                // Fallback: stream MP4 directly (most players handle this)
                return $this->streamFile($mp4Path, 'video/mp4');
            }
        }

        return $this->streamFile($tsPath, 'video/mp2t');
    }

    /**
     * Stream a file with proper headers.
     */
    private function streamFile(string $path, string $mimeType): StreamedResponse
    {
        $fileSize = filesize($path);

        $response = new StreamedResponse(function () use ($path) {
            $handle = fopen($path, 'rb');
            while (!feof($handle)) {
                echo fread($handle, 1024 * 1024);
                flush();
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Content-Length', $fileSize);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }
}
