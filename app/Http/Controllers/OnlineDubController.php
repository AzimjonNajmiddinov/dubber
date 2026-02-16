<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadVideoFromUrlJob;
use App\Jobs\ExtractAudioJob;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OnlineDubController extends Controller
{
    public function index()
    {
        $recentDubs = Video::orderByDesc('id')
            ->limit(10)
            ->get();

        return view('online-dubber.index', compact('recentDubs'));
    }

    public function submit(Request $request)
    {
        $request->validate([
            'url' => 'nullable|required_without:video|url',
            'video' => 'nullable|required_without:url|file|mimes:mp4,mkv,avi,webm,mov|max:512000',
            'target_language' => 'required|string|in:uz,ru,en',
        ]);

        $targetLanguage = $request->target_language;

        // File upload path
        if ($request->hasFile('video')) {
            return $this->handleFileUpload($request, $targetLanguage);
        }

        // URL path
        return $this->handleUrlSubmit($request->url, $targetLanguage);
    }

    private function handleFileUpload(Request $request, string $targetLanguage)
    {
        $file = $request->file('video');
        $path = $file->store('videos/originals', 'local');

        if (!Storage::disk('local')->exists($path)) {
            return back()->withErrors(['video' => 'File upload failed. Please try again.']);
        }

        $video = Video::create([
            'original_path' => $path,
            'target_language' => $targetLanguage,
            'status' => 'uploaded',
        ]);

        Log::info('Online dubber: file uploaded', [
            'video_id' => $video->id,
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'target_language' => $targetLanguage,
        ]);

        ExtractAudioJob::dispatch($video->id);

        return redirect()->route('dub.progress', $video);
    }

    private function handleUrlSubmit(string $url, string $targetLanguage)
    {
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
