<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadVideoFromUrlJob;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OnlineDubController extends Controller
{
    public function index()
    {
        $recentDubs = Video::whereNotNull('source_url')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('online-dubber.index', compact('recentDubs'));
    }

    public function submit(Request $request)
    {
        $validated = $request->validate([
            'url' => 'required|url',
            'target_language' => 'required|string|in:uz,ru,en',
        ]);

        $url = $validated['url'];
        $targetLanguage = $validated['target_language'];

        // Check for existing dub of same URL (deduplication)
        $existing = Video::where('source_url', $url)
            ->whereNotIn('status', ['failed', 'download_failed'])
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            return redirect()->route('dub.progress', $existing);
        }

        $video = Video::create([
            'source_url' => $url,
            'target_language' => $targetLanguage,
            'status' => 'pending',
        ]);

        Log::info('Online dubber: starting URL dub', [
            'video_id' => $video->id,
            'url' => $url,
            'target_language' => $targetLanguage,
        ]);

        DownloadVideoFromUrlJob::dispatch($video->id);

        return redirect()->route('dub.progress', $video);
    }

    public function progress(Video $video)
    {
        $ready = in_array($video->status, ['dubbed_complete', 'lipsync_done', 'done']);
        $failed = in_array($video->status, ['failed', 'download_failed']);

        $progressMap = [
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
            'lipsync_done' => [100, 'Complete'],
            'done' => [100, 'Complete'],
            'failed' => [0, 'Failed'],
        ];

        [$progress, $label] = $progressMap[$video->status] ?? [0, $video->status ?? 'Unknown'];

        $streamUrl = $ready ? route('api.stream.watch', $video) : null;

        return view('online-dubber.progress', compact('video', 'ready', 'failed', 'progress', 'label', 'streamUrl'));
    }
}
