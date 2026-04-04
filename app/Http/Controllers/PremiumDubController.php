<?php

namespace App\Http\Controllers;

use App\Jobs\PremiumDub\StartPremiumDubJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PremiumDubController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'video_url'      => 'required|string',
            'language'       => 'required|string|max:10',
            'translate_from' => 'nullable|string|max:10',
            'speakers'       => 'nullable|integer|min:1|max:20',
        ]);

        $dubId = Str::uuid()->toString();
        $language = $request->input('language', 'uz');
        $translateFrom = $request->input('translate_from', 'auto');
        $speakers = $request->input('speakers');
        $videoUrl = $request->input('video_url');

        $session = [
            'id'             => $dubId,
            'language'       => $language,
            'translate_from' => $translateFrom,
            'video_url'      => $videoUrl,
            'speakers'       => $speakers,
            'status'         => 'pending',
            'progress'       => 'Starting...',
            'created_at'     => now()->toIso8601String(),
        ];

        Redis::setex("premium-dub:{$dubId}", 86400, json_encode($session));

        StartPremiumDubJob::dispatch($dubId, $videoUrl, $language, $translateFrom)
            ->onQueue('default');

        Log::info("[PREMIUM] Session created via URL", ['dub_id' => $dubId, 'language' => $language]);

        return response()->json(['dub_id' => $dubId, 'status' => 'pending']);
    }

    public function startUpload(Request $request): JsonResponse
    {
        $request->validate([
            'video'          => 'required|file|mimes:mp4,mov,avi,mkv,webm|max:512000',
            'language'       => 'required|string|max:10',
            'translate_from' => 'nullable|string|max:10',
            'speakers'       => 'nullable|integer|min:1|max:20',
        ]);

        $dubId = Str::uuid()->toString();
        $language = $request->input('language', 'uz');
        $translateFrom = $request->input('translate_from', 'auto');
        $speakers = $request->input('speakers');

        $workDir = storage_path("app/premium-dub/{$dubId}");
        mkdir($workDir, 0755, true);

        $videoPath = "{$workDir}/video.mp4";
        $request->file('video')->move($workDir, 'video.mp4');

        $audioPath = "{$workDir}/audio.wav";
        $audioResult = Process::timeout(120)->run([
            'ffmpeg', '-y', '-i', $videoPath,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le',
            $audioPath,
        ]);

        if (!$audioResult->successful() || !file_exists($audioPath)) {
            Log::error("[PREMIUM] Audio extraction failed: " . $audioResult->errorOutput());
            return response()->json(['error' => 'Audio extraction failed'], 422);
        }

        $probe = Process::timeout(10)->run([
            'ffprobe', '-hide_banner', '-loglevel', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1', $videoPath,
        ]);
        $videoDuration = $probe->successful() ? (float) trim($probe->output()) : 0;

        $session = [
            'id'             => $dubId,
            'language'       => $language,
            'translate_from' => $translateFrom,
            'speakers'       => $speakers,
            'video_path'     => $videoPath,
            'audio_path'     => $audioPath,
            'video_duration' => $videoDuration,
            'status'         => 'separating_stems',
            'progress'       => 'Processing audio...',
            'created_at'     => now()->toIso8601String(),
        ];

        Redis::setex("premium-dub:{$dubId}", 86400, json_encode($session));

        StartPremiumDubJob::dispatch($dubId, null, $language, $translateFrom)
            ->onQueue('default');

        Log::info("[PREMIUM] Session created via upload", ['dub_id' => $dubId, 'language' => $language, 'duration' => $videoDuration]);

        return response()->json(['dub_id' => $dubId, 'status' => 'pending']);
    }

    public function status(string $dubId): JsonResponse
    {
        $json = Redis::get("premium-dub:{$dubId}");
        if (!$json) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $session = json_decode($json, true);

        return response()->json([
            'dub_id'              => $dubId,
            'status'              => $session['status'] ?? 'unknown',
            'progress'            => $session['progress'] ?? '',
            'total_segments'      => $session['total_segments'] ?? 0,
            'segments_synthesized'=> $session['segments_synthesized'] ?? 0,
            'detected_language'   => $session['detected_language'] ?? null,
            'speakers_detected'   => count($session['speakers_info'] ?? []),
            'video_duration'      => $session['video_duration'] ?? 0,
            'final_video_size'    => $session['final_video_size'] ?? null,
            'ready'               => ($session['status'] ?? '') === 'complete',
        ]);
    }

    public function download(string $dubId)
    {
        $json = Redis::get("premium-dub:{$dubId}");
        if (!$json) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $session = json_decode($json, true);
        $videoPath = $session['final_video_path'] ?? null;

        if (!$videoPath || !file_exists($videoPath)) {
            return response()->json(['error' => 'Video not ready'], 404);
        }

        return response()->download($videoPath, "dubbed_{$dubId}.mp4", [
            'Content-Type' => 'video/mp4',
        ]);
    }
}
