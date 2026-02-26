<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessProgressiveChunkJob;
use App\Jobs\ProgressiveDownloadAndChunkJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ProgressiveDubController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|url',
            'target_language' => 'required|string|max:10',
            'tts_driver' => 'nullable|string|max:20',
        ]);

        $url = $request->input('url');
        $targetLanguage = $request->input('target_language', 'uz');
        $ttsDriver = $request->input('tts_driver', 'edge');
        $sessionId = Str::uuid()->toString();

        // Check if yt-dlp can handle this URL
        $mode = $this->detectMode($url);

        $session = [
            'id' => $sessionId,
            'url' => $url,
            'target_language' => $targetLanguage,
            'tts_driver' => $ttsDriver,
            'mode' => $mode,
            'status' => 'starting',
            'chunks_ready' => 0,
            'total_chunks' => null,
            'created_at' => now()->toIso8601String(),
        ];

        Redis::setex("progressive:{$sessionId}", 14400, json_encode($session));

        if ($mode === 'server') {
            ProgressiveDownloadAndChunkJob::dispatch($sessionId, $url, $targetLanguage, $ttsDriver)
                ->onQueue('progressive');
        }

        Log::info('Progressive dubbing session started', [
            'session_id' => $sessionId,
            'url' => $url,
            'mode' => $mode,
        ]);

        return response()->json([
            'session_id' => $sessionId,
            'mode' => $mode,
        ]);
    }

    public function poll(string $sessionId, Request $request): JsonResponse
    {
        $sessionJson = Redis::get("progressive:{$sessionId}");

        if (!$sessionJson) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $session = json_decode($sessionJson, true);
        $afterChunk = (int) $request->query('after_chunk', -1);

        $chunks = [];
        $chunksReady = (int) ($session['chunks_ready'] ?? 0);

        for ($i = $afterChunk + 1; $i < $afterChunk + 1 + 20; $i++) {
            $chunkJson = Redis::get("progressive:{$sessionId}:chunk:{$i}");
            if (!$chunkJson) break;
            $chunks[] = json_decode($chunkJson, true);
        }

        return response()->json([
            'status' => $session['status'] ?? 'processing',
            'mode' => $session['mode'] ?? 'server',
            'chunks_ready' => $chunksReady,
            'total_chunks' => $session['total_chunks'] ?? null,
            'chunks' => $chunks,
        ]);
    }

    public function captureChunk(string $sessionId, Request $request): JsonResponse
    {
        $sessionJson = Redis::get("progressive:{$sessionId}");

        if (!$sessionJson) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $session = json_decode($sessionJson, true);

        $request->validate([
            'audio' => 'required|string',
            'timestamp' => 'required|numeric',
            'duration' => 'required|numeric',
            'index' => 'required|integer|min:0',
        ]);

        $audioBase64 = $request->input('audio');
        $timestamp = (float) $request->input('timestamp');
        $duration = (float) $request->input('duration');
        $index = (int) $request->input('index');

        // Decode WebM and convert to WAV
        $audioData = base64_decode($audioBase64);
        $tempDir = storage_path("app/temp/progressive/{$sessionId}");
        @mkdir($tempDir, 0755, true);

        $webmPath = "{$tempDir}/capture_{$index}.webm";
        $wavPath = "{$tempDir}/capture_{$index}.wav";
        file_put_contents($webmPath, $audioData);

        $result = Process::timeout(30)->run([
            'ffmpeg', '-y', '-i', $webmPath,
            '-ar', '16000', '-ac', '1', '-c:a', 'pcm_s16le',
            $wavPath,
        ]);

        @unlink($webmPath);

        if (!$result->successful() || !file_exists($wavPath)) {
            return response()->json(['error' => 'Audio conversion failed'], 500);
        }

        ProcessProgressiveChunkJob::dispatch(
            $sessionId,
            $index,
            $wavPath,
            $timestamp,
            $duration,
            $session['target_language'] ?? 'uz',
            $session['tts_driver'] ?? 'edge'
        )->onQueue('progressive');

        return response()->json(['status' => 'queued', 'index' => $index]);
    }

    public function stop(string $sessionId): JsonResponse
    {
        $sessionJson = Redis::get("progressive:{$sessionId}");

        if (!$sessionJson) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $session = json_decode($sessionJson, true);
        $session['status'] = 'stopped';
        Redis::setex("progressive:{$sessionId}", 300, json_encode($session));

        // Clean up chunk keys
        $i = 0;
        while (Redis::exists("progressive:{$sessionId}:chunk:{$i}")) {
            Redis::del("progressive:{$sessionId}:chunk:{$i}");
            $i++;
        }

        // Clean up temp files
        $tempDir = storage_path("app/temp/progressive/{$sessionId}");
        if (is_dir($tempDir)) {
            array_map('unlink', glob("{$tempDir}/*") ?: []);
            @rmdir($tempDir);
        }

        Log::info('Progressive dubbing session stopped', ['session_id' => $sessionId]);

        return response()->json(['status' => 'stopped']);
    }

    private function detectMode(string $url): string
    {
        try {
            $result = Process::timeout(8)->run([
                'yt-dlp', '--simulate', '--quiet', '--no-warnings', $url,
            ]);

            return $result->successful() ? 'server' : 'capture';
        } catch (\Throwable $e) {
            Log::warning('yt-dlp simulate failed', ['url' => $url, 'error' => $e->getMessage()]);
            return 'capture';
        }
    }
}
