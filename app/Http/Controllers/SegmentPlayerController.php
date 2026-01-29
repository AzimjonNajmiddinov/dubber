<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateSegmentVideoJob;
use App\Models\Video;
use App\Models\VideoSegment;
use App\Services\SegmentVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
