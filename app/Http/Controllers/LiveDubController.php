<?php

namespace App\Http\Controllers;

use App\Jobs\StartChunkProcessingJob;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LiveDubController extends Controller
{
    /**
     * Show the streaming landing page.
     */
    public function index()
    {
        return view('stream.index');
    }

    /**
     * Start live dubbing from URL using chunk-based processing.
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url',
            'target_language' => 'required|string|in:uz,ru,en',
        ]);

        $url = $validated['url'];
        $targetLanguage = $validated['target_language'];

        // Check if we already have this URL being processed
        $existing = Video::where('source_url', $url)
            ->where('target_language', $targetLanguage)
            ->whereNotIn('status', ['failed', 'download_failed'])
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $readyChunks = $this->countReadyChunks($existing);

            return response()->json([
                'video_id' => $existing->id,
                'status' => $existing->status,
                'message' => $readyChunks > 0 ? 'Video has chunks ready' : 'Video is being processed',
                'ready_chunks' => $readyChunks,
                'player_url' => route('player.segments', $existing),
            ]);
        }

        // Create new video record
        $video = Video::create([
            'source_url' => $url,
            'target_language' => $targetLanguage,
            'status' => 'pending',
        ]);

        Log::info('Starting chunk-based live dubbing', [
            'video_id' => $video->id,
            'url' => $url,
            'target_language' => $targetLanguage,
        ]);

        // Dispatch chunk processing pipeline
        StartChunkProcessingJob::dispatch($video->id);

        return response()->json([
            'video_id' => $video->id,
            'status' => 'pending',
            'message' => 'Live dubbing started',
            'player_url' => route('player.segments', $video),
        ]);
    }

    /**
     * Get streaming status including chunk readiness.
     */
    public function status(Video $video): JsonResponse
    {
        $totalChunks = $video->segments()->count();
        $readyChunks = $this->countReadyChunks($video);

        // Calculate progress
        $progress = $this->calculateProgress($video, $totalChunks, $readyChunks);

        return response()->json([
            'video_id' => $video->id,
            'status' => $video->status,
            'progress' => $progress,
            'total_segments' => $totalChunks,
            'ready_segments' => $readyChunks,
            'can_play' => $readyChunks > 0,
            'player_url' => route('player.segments', $video),
        ]);
    }

    private function countReadyChunks(Video $video): int
    {
        // A chunk is ready if it has TTS audio OR if it's an empty segment (no speech)
        return $video->segments()
            ->where(function ($q) {
                $q->whereNotNull('tts_audio_path')
                  ->orWhere('text', '');
            })
            ->count();
    }

    private function calculateProgress(Video $video, int $total, int $ready): int
    {
        // Early stages
        if ($total === 0) {
            return match ($video->status) {
                'pending' => 0,
                'downloading' => 10,
                'uploaded' => 20,
                'processing_chunks' => 25,
                default => 0,
            };
        }

        // Progress based on ready chunks (25% for download, 75% for processing)
        $chunkProgress = $total > 0 ? ($ready / $total) * 75 : 0;
        return (int) min(100, 25 + $chunkProgress);
    }
}
