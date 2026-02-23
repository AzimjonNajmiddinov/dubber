<?php

namespace App\Http\Controllers;

use App\Jobs\StartChunkProcessingJob;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        // URL-only submission (file upload uses chunked endpoint)
        $request->validate([
            'url' => 'required|url',
            'target_language' => 'required|string|in:uz,ru,en',
        ]);

        return $this->handleUrlSubmit($request->url, $request->target_language);
    }

    /**
     * Receive a single chunk of a file upload (2MB pieces).
     * Nginx blocks large POSTs, so JS splits the file into small chunks.
     */
    public function uploadChunk(Request $request): JsonResponse
    {
        $request->validate([
            'chunk' => 'required|file|max:3072', // 3MB max per chunk
            'upload_id' => 'required|string|alpha_num|size:16',
            'chunk_index' => 'required|integer|min:0',
            'total_chunks' => 'required|integer|min:1',
        ]);

        $uploadId = $request->upload_id;
        $chunkDir = "chunks/{$uploadId}";
        Storage::disk('local')->makeDirectory($chunkDir);

        $request->file('chunk')->storeAs($chunkDir, "chunk_{$request->chunk_index}", 'local');

        return response()->json(['ok' => true, 'chunk' => (int) $request->chunk_index]);
    }

    /**
     * All chunks uploaded - assemble file and start dubbing pipeline.
     */
    public function uploadComplete(Request $request): JsonResponse
    {
        $request->validate([
            'upload_id' => 'required|string|alpha_num|size:16',
            'total_chunks' => 'required|integer|min:1',
            'filename' => 'required|string|max:255',
            'target_language' => 'required|string|in:uz,ru,en',
        ]);

        $uploadId = $request->upload_id;
        $chunkDir = "chunks/{$uploadId}";
        $totalChunks = (int) $request->total_chunks;

        // Verify all chunks exist
        for ($i = 0; $i < $totalChunks; $i++) {
            if (!Storage::disk('local')->exists("{$chunkDir}/chunk_{$i}")) {
                return response()->json(['error' => "Missing chunk {$i}"], 422);
            }
        }

        // Assemble chunks into final file
        Storage::disk('local')->makeDirectory('videos/originals');
        $ext = pathinfo($request->filename, PATHINFO_EXTENSION) ?: 'mp4';
        $finalName = Str::random(16) . '.' . $ext;
        $finalPath = "videos/originals/{$finalName}";
        $finalAbsolute = Storage::disk('local')->path($finalPath);

        $out = fopen($finalAbsolute, 'wb');
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = Storage::disk('local')->path("{$chunkDir}/chunk_{$i}");
            $in = fopen($chunkPath, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        // Clean up chunks
        Storage::disk('local')->deleteDirectory($chunkDir);

        if (!file_exists($finalAbsolute) || filesize($finalAbsolute) < 1000) {
            return response()->json(['error' => 'File assembly failed'], 500);
        }

        $video = Video::create([
            'original_path' => $finalPath,
            'target_language' => $request->target_language,
            'status' => 'uploaded',
        ]);

        Log::info('Online dubber: chunked file uploaded', [
            'video_id' => $video->id,
            'filename' => $request->filename,
            'size' => filesize($finalAbsolute),
            'chunks' => $totalChunks,
            'target_language' => $request->target_language,
        ]);

        StartChunkProcessingJob::dispatch($video->id);

        return response()->json([
            'ok' => true,
            'redirect' => route('stream.player', $video),
        ]);
    }

    private function handleUrlSubmit(string $url, string $targetLanguage)
    {
        // Check for existing dub of same URL (deduplication)
        $existing = Video::where('source_url', $url)
            ->whereNotIn('status', ['failed', 'download_failed'])
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            return redirect()->route('stream.player', $existing);
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

        StartChunkProcessingJob::dispatch($video->id);

        return redirect()->route('stream.player', $video);
    }

    public function progress(Video $video)
    {
        // Chunk-based videos should use the streaming player
        if (in_array($video->status, ['processing_chunks', 'combining_chunks'])) {
            return redirect()->route('stream.player', $video);
        }

        $ready = in_array($video->status, ['dubbed_complete', 'lipsync_done', 'done']);
        $failed = in_array($video->status, ['failed', 'download_failed']);

        $progressMap = [
            'pending' => [0, 'Pending'],
            'downloading' => [5, 'Downloading video'],
            'download_failed' => [0, 'Download failed'],
            'uploaded' => [10, 'Downloaded'],
            'audio_extracted' => [20, 'Extracting audio'],
            'stems_separated' => [30, 'Separating audio tracks'],
            'processing_chunks' => [40, 'Processing chunks'],
            'combining_chunks' => [85, 'Combining chunks'],
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
