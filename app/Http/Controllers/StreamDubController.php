<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadVideoFromUrlJob;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamDubController extends Controller
{
    /**
     * Start dubbing a video from URL.
     * Returns video ID to track progress and stream result.
     */
    public function dubFromUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url',
            'target_language' => 'required|string|in:uz,ru,en',
        ]);

        $url = $validated['url'];
        $targetLanguage = $validated['target_language'];

        // Check if we already have this URL being processed
        $existing = Video::where('source_url', $url)
            ->whereNotIn('status', ['failed', 'download_failed'])
            ->orderByDesc('id')
            ->first();

        if ($existing && in_array($existing->status, ['dubbed_complete', 'lipsync_done', 'done'])) {
            // Already dubbed, return immediately
            return response()->json([
                'video_id' => $existing->id,
                'status' => $existing->status,
                'message' => 'Video already dubbed',
                'stream_url' => route('api.stream.watch', $existing),
                'ready' => true,
            ]);
        }

        if ($existing && !in_array($existing->status, ['failed', 'download_failed'])) {
            // Still processing
            return response()->json([
                'video_id' => $existing->id,
                'status' => $existing->status,
                'message' => 'Video is being processed',
                'status_url' => route('api.stream.status', $existing),
                'stream_url' => route('api.stream.watch', $existing),
                'ready' => false,
            ]);
        }

        // Create new video record
        $video = Video::create([
            'source_url' => $url,
            'target_language' => $targetLanguage,
            'status' => 'pending',
        ]);

        Log::info('Starting URL dubbing', [
            'video_id' => $video->id,
            'url' => $url,
            'target_language' => $targetLanguage,
        ]);

        // Dispatch download job
        DownloadVideoFromUrlJob::dispatch($video->id);

        return response()->json([
            'video_id' => $video->id,
            'status' => 'pending',
            'message' => 'Dubbing started',
            'status_url' => route('api.stream.status', $video),
            'stream_url' => route('api.stream.watch', $video),
            'ready' => false,
        ]);
    }

    /**
     * Get dubbing status for a video.
     */
    public function status(Video $video): JsonResponse
    {
        [$progress, $label] = $this->getProgressInfo($video);
        $ready = in_array($video->status, ['dubbed_complete', 'lipsync_done', 'done']);

        return response()->json([
            'video_id' => $video->id,
            'status' => $video->status,
            'progress' => $progress,
            'label' => $label,
            'ready' => $ready,
            'stream_url' => $ready ? route('api.stream.watch', $video) : null,
            'source_url' => $video->source_url,
        ]);
    }

    /**
     * Stream the dubbed video.
     * Supports range requests for proper video streaming.
     */
    public function stream(Video $video, Request $request): StreamedResponse
    {
        if (!in_array($video->status, ['dubbed_complete', 'lipsync_done', 'done'])) {
            abort(404, 'Dubbed video not ready yet');
        }

        $path = $video->dubbed_path;

        if (!$path || !Storage::disk('local')->exists($path)) {
            abort(404, 'Dubbed video file not found');
        }

        $absolutePath = Storage::disk('local')->path($path);
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
        $response->headers->set('Content-Disposition', 'inline; filename="dubbed_' . $video->id . '.mp4"');

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
     * Get video info including embed player HTML.
     */
    public function info(Video $video): JsonResponse
    {
        [$progress, $label] = $this->getProgressInfo($video);
        $ready = in_array($video->status, ['dubbed_complete', 'lipsync_done', 'done']);

        $data = [
            'video_id' => $video->id,
            'status' => $video->status,
            'progress' => $progress,
            'label' => $label,
            'ready' => $ready,
            'source_url' => $video->source_url,
            'target_language' => $video->target_language,
            'created_at' => $video->created_at->toIso8601String(),
        ];

        if ($ready) {
            $data['stream_url'] = route('api.stream.watch', $video);
            $data['embed_html'] = $this->generateEmbedHtml($video);
        }

        return response()->json($data);
    }

    /**
     * Simple HTML page with video player for testing.
     */
    public function player(Video $video)
    {
        // Check if completed
        if (in_array($video->status, ['dubbed_complete', 'lipsync_done', 'done'])) {
            return response()->view('stream.player', [
                'video' => $video,
                'streamUrl' => route('api.stream.watch', $video),
            ]);
        }

        // Show waiting/progress page for all other statuses (including failed)
        return response()->view('stream.waiting', [
            'video' => $video,
            'progress' => $this->getProgressInfo($video)[0],
            'label' => $this->getProgressInfo($video)[1],
        ]);
    }

    private function getProgressInfo(Video $video): array
    {
        return match ($video->status) {
            'pending' => [0, 'Pending'],
            'downloading' => [5, 'Downloading video'],
            'download_failed' => [0, 'Download failed'],
            'uploaded' => [10, 'Downloaded'],
            'audio_extracted' => [20, 'Extracting audio'],
            'stems_separated' => [30, 'Separating audio tracks'],
            'transcribed' => [40, 'Transcribed'],
            'translated' => [55, 'Translated'],
            'tts_generated' => [70, 'Voice generated'],
            'mixed' => [80, 'Mixing audio'],
            'dubbed_complete' => [95, 'Dubbed'],
            'lipsync_processing' => [97, 'Lip-syncing'],
            'lipsync_done', 'done' => [100, 'Complete'],
            'failed' => [0, 'Failed'],
            default => [0, $video->status ?? 'Unknown'],
        };
    }

    private function generateEmbedHtml(Video $video): string
    {
        $streamUrl = route('api.stream.watch', $video);
        return <<<HTML
<video controls autoplay style="max-width:100%;height:auto;">
    <source src="{$streamUrl}" type="video/mp4">
    Your browser does not support the video tag.
</video>
HTML;
    }
}
