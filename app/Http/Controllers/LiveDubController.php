<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadVideoForStreamingJob;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
     * Start live dubbing from URL.
     * This triggers the streaming pipeline that processes segments one by one.
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url',
            'target_language' => 'required|string|in:uz,ru,en',
        ]);

        $url = $validated['url'];
        $targetLanguage = $validated['target_language'];

        // Check if we already have this URL being processed with streaming
        $existing = Video::where('source_url', $url)
            ->where('target_language', $targetLanguage)
            ->whereNotIn('status', ['failed', 'download_failed'])
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            // Check if it has segments ready for streaming
            $readySegments = $existing->segments()
                ->whereNotNull('tts_audio_path')
                ->count();

            return response()->json([
                'video_id' => $existing->id,
                'status' => $existing->status,
                'message' => $readySegments > 0 ? 'Video has segments ready' : 'Video is being processed',
                'ready_segments' => $readySegments,
                'player_url' => route('player.segments', $existing),
            ]);
        }

        // Create new video record with streaming mode
        $video = Video::create([
            'source_url' => $url,
            'target_language' => $targetLanguage,
            'status' => 'pending',
        ]);

        Log::info('Starting live streaming dubbing', [
            'video_id' => $video->id,
            'url' => $url,
            'target_language' => $targetLanguage,
        ]);

        // Dispatch streaming download job (uses streaming pipeline)
        DownloadVideoForStreamingJob::dispatch($video->id);

        return response()->json([
            'video_id' => $video->id,
            'status' => 'pending',
            'message' => 'Live dubbing started',
            'player_url' => route('player.segments', $video),
        ]);
    }

    /**
     * Get streaming status including segment readiness.
     */
    public function status(Video $video): JsonResponse
    {
        $totalSegments = $video->segments()->count();
        $readySegments = $video->segments()
            ->whereNotNull('tts_audio_path')
            ->count();
        $translatedSegments = $video->segments()
            ->whereNotNull('translated_text')
            ->count();

        // Calculate progress based on pipeline stages
        $progress = $this->calculateProgress($video, $totalSegments, $translatedSegments, $readySegments);

        return response()->json([
            'video_id' => $video->id,
            'status' => $video->status,
            'progress' => $progress,
            'total_segments' => $totalSegments,
            'translated_segments' => $translatedSegments,
            'ready_segments' => $readySegments,
            'can_play' => $readySegments > 0,
            'player_url' => route('player.segments', $video),
        ]);
    }

    private function calculateProgress(Video $video, int $total, int $translated, int $ready): int
    {
        // Early stages (before segments exist)
        if ($total === 0) {
            return match ($video->status) {
                'pending' => 0,
                'downloading' => 5,
                'uploaded' => 10,
                'audio_extracted' => 15,
                'stems_separated' => 20,
                'transcribing' => 25,
                default => 0,
            };
        }

        // After transcription: progress based on ready segments
        // 30% = transcribed, 100% = all segments ready
        $segmentProgress = $total > 0 ? ($ready / $total) * 70 : 0;
        return (int) min(100, 30 + $segmentProgress);
    }
}
