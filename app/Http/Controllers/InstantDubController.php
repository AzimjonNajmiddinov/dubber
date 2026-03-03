<?php

namespace App\Http\Controllers;

use App\Jobs\PrepareInstantDubJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class InstantDubController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'srt' => 'nullable|string',
            'language' => 'required|string|max:10',
            'video_url' => 'nullable|string',
            'translate_from' => 'nullable|string|max:10',
        ]);

        $sessionId = Str::uuid()->toString();
        $language = $request->input('language', 'uz');
        $videoUrl = $request->input('video_url', '');
        $translateFrom = $request->input('translate_from', '');
        $srt = $request->input('srt', '');

        // Parse video URL components for HLS proxy
        $urlWithoutQuery = strtok($videoUrl, '?');
        $videoBaseUrl = $videoUrl ? preg_replace('#/[^/]+$#', '/', $urlWithoutQuery) : '';
        $videoQuery = $videoUrl ? (parse_url($videoUrl, PHP_URL_QUERY) ?? '') : '';

        // Create session immediately so polling works right away
        $session = [
            'id' => $sessionId,
            'language' => $language,
            'video_url' => $videoUrl,
            'video_base_url' => $videoBaseUrl,
            'video_query' => $videoQuery,
            'status' => 'preparing',
            'total_segments' => 0,
            'segments_ready' => 0,
            'created_at' => now()->toIso8601String(),
        ];

        Redis::setex("instant-dub:{$sessionId}", 50400, json_encode($session));

        // Dispatch prep job — fetches subs, translates, dispatches TTS
        PrepareInstantDubJob::dispatch(
            $sessionId, $videoUrl, $language, $translateFrom, $srt,
        )->onQueue('segment-generation');

        Log::info('Instant dub session created', ['session_id' => $sessionId]);

        return response()->json([
            'session_id' => $sessionId,
        ]);
    }

    public function poll(string $sessionId, Request $request): JsonResponse
    {
        $sessionJson = Redis::get("instant-dub:{$sessionId}");

        if (!$sessionJson) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $session = json_decode($sessionJson, true);
        $after = (int) $request->query('after', -1);

        $chunks = [];
        for ($i = $after + 1; $i < $after + 21; $i++) {
            $chunkJson = Redis::get("instant-dub:{$sessionId}:chunk:{$i}");
            if (!$chunkJson) break;
            $chunks[] = json_decode($chunkJson, true);
        }

        return response()->json([
            'status' => $session['status'] ?? 'preparing',
            'error' => $session['error'] ?? null,
            'segments_ready' => (int) ($session['segments_ready'] ?? 0),
            'total_segments' => (int) ($session['total_segments'] ?? 0),
            'chunks' => $chunks,
        ]);
    }

    public function stop(string $sessionId): JsonResponse
    {
        $sessionJson = Redis::get("instant-dub:{$sessionId}");

        if (!$sessionJson) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $session = json_decode($sessionJson, true);
        $session['status'] = 'stopped';
        Redis::setex("instant-dub:{$sessionId}", 300, json_encode($session));

        $total = $session['total_segments'] ?? 0;
        for ($i = 0; $i < $total; $i++) {
            Redis::del("instant-dub:{$sessionId}:chunk:{$i}");
        }

        return response()->json(['status' => 'stopped']);
    }

    // ── HLS endpoints for PlayerKit integration ──

    public function hlsMaster(string $sessionId)
    {
        $session = $this->getSession($sessionId);
        if (!$session) {
            return response('Session not found', 404);
        }

        $videoUrl = $session['video_url'] ?? '';
        if (!$videoUrl) {
            return response('No video URL in session', 400);
        }

        $response = Http::timeout(10)->get($videoUrl);
        if ($response->failed()) {
            return response('Failed to fetch master playlist', 502);
        }

        $master = $response->body();
        $proxyBase = "/api/instant-dub/{$sessionId}/proxy/";
        $lang = $session['language'] ?? 'uz';
        $langNames = ['uz' => "O'zbek dublyaj", 'ru' => 'Русский дубляж', 'en' => 'English dub'];
        $dubName = $langNames[$lang] ?? ucfirst($lang) . ' dub';

        $lines = explode("\n", $master);
        $output = [];
        $injected = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Inject dub audio EXT-X-MEDIA before the first STREAM-INF
            if (!$injected && str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                $output[] = "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"dub\",NAME=\"{$dubName}\",LANGUAGE=\"{$lang}\",URI=\"dub-audio.m3u8\",DEFAULT=NO,AUTOSELECT=YES";
                $injected = true;
            }

            // Add AUDIO="dub" to STREAM-INF lines
            if (str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                if (str_contains($trimmed, 'AUDIO=')) {
                    $line = preg_replace('/AUDIO="[^"]*"/', 'AUDIO="dub"', $line);
                } else {
                    $line = rtrim($line) . ',AUDIO="dub"';
                }
            }

            // Rewrite URI= in EXT-X-MEDIA tags to proxy
            if (str_starts_with($trimmed, '#EXT-X-MEDIA') && str_contains($trimmed, 'URI="')) {
                $line = preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($proxyBase) {
                    $uri = $m[1];
                    if (str_starts_with($uri, 'http')) {
                        return 'URI="' . $proxyBase . ltrim(parse_url($uri, PHP_URL_PATH) ?? $uri, '/') . '"';
                    }
                    return 'URI="' . $proxyBase . $uri . '"';
                }, $line);
            }

            // Rewrite standalone URI lines (non-comment, non-empty) to proxy
            if ($trimmed !== '' && !str_starts_with($trimmed, '#')) {
                if (str_starts_with($trimmed, 'http')) {
                    $line = $proxyBase . ltrim(parse_url($trimmed, PHP_URL_PATH) ?? $trimmed, '/');
                } else {
                    $line = $proxyBase . $trimmed;
                }
            }

            $output[] = $line;
        }

        return response(implode("\n", $output), 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function hlsAudioPlaylist(string $sessionId)
    {
        $session = $this->getSession($sessionId);
        if (!$session) {
            return response('Session not found', 404);
        }

        $total = (int) ($session['total_segments'] ?? 0);
        $status = $session['status'] ?? 'preparing';

        // Collect ready segments in order — stop at first missing chunk
        $segments = [];
        $prevEnd = 0.0;
        $maxDuration = 3;

        for ($i = 0; $i < $total; $i++) {
            $chunkJson = Redis::get("instant-dub:{$sessionId}:chunk:{$i}");
            if (!$chunkJson) break;

            $chunk = json_decode($chunkJson, true);
            $gap = max(0, ($chunk['start_time'] ?? 0) - $prevEnd);
            $audioDuration = (float) ($chunk['audio_duration'] ?? ($chunk['end_time'] - $chunk['start_time']));
            $segDuration = $gap + $audioDuration;

            $segments[] = [
                'index' => $i,
                'duration' => round($segDuration, 3),
            ];

            $maxDuration = max($maxDuration, (int) ceil($segDuration));
            $prevEnd = (float) ($chunk['end_time'] ?? 0);
        }

        $m3u8 = "#EXTM3U\n";
        $m3u8 .= "#EXT-X-VERSION:3\n";
        $m3u8 .= "#EXT-X-TARGETDURATION:{$maxDuration}\n";
        $m3u8 .= "#EXT-X-MEDIA-SEQUENCE:0\n";

        if ($status !== 'complete') {
            $m3u8 .= "#EXT-X-PLAYLIST-TYPE:EVENT\n";
        }

        foreach ($segments as $seg) {
            $m3u8 .= "#EXTINF:{$seg['duration']},\n";
            $m3u8 .= "dub-segment/{$seg['index']}.aac\n";
        }

        if ($status === 'complete' && count($segments) === $total) {
            $m3u8 .= "#EXT-X-ENDLIST\n";
        }

        $cacheControl = $status === 'complete' ? 'max-age=3600' : 'no-cache';

        return response($m3u8, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => $cacheControl,
        ]);
    }

    public function hlsAudioSegment(string $sessionId, int $index)
    {
        // Check AAC cache first
        $cacheKey = "instant-dub:{$sessionId}:aac:{$index}";
        $cached = Redis::get($cacheKey);

        if ($cached) {
            return response(base64_decode($cached), 200, [
                'Content-Type' => 'audio/aac',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'max-age=86400',
            ]);
        }

        $chunkJson = Redis::get("instant-dub:{$sessionId}:chunk:{$index}");
        if (!$chunkJson) {
            return response('Segment not ready', 404);
        }

        $chunk = json_decode($chunkJson, true);
        $aacData = $this->generateAacSegment($sessionId, $index, $chunk);

        if (!$aacData) {
            return response('Failed to generate AAC segment', 500);
        }

        // Cache converted AAC (14h TTL)
        Redis::setex($cacheKey, 50400, base64_encode($aacData));

        return response($aacData, 200, [
            'Content-Type' => 'audio/aac',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'max-age=86400',
        ]);
    }

    public function hlsProxy(string $sessionId, string $path)
    {
        $session = $this->getSession($sessionId);
        if (!$session) {
            return response('Session not found', 404);
        }

        $baseUrl = $session['video_base_url'] ?? '';
        $query = $session['video_query'] ?? '';

        $url = rtrim($baseUrl, '/') . '/' . $path;
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . $query;
        }

        $response = Http::timeout(30)->get($url);
        if ($response->failed()) {
            return response('Proxy fetch failed', 502);
        }

        $contentType = $response->header('Content-Type') ?? 'application/octet-stream';

        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    // ── Private helpers ──

    private function getSession(string $sessionId): ?array
    {
        $json = Redis::get("instant-dub:{$sessionId}");
        return $json ? json_decode($json, true) : null;
    }

    private function generateAacSegment(string $sessionId, int $index, array $chunk): ?string
    {
        $tmpDir = sys_get_temp_dir() . "/hls-dub-{$sessionId}";
        @mkdir($tmpDir, 0755, true);

        $mp3File = "{$tmpDir}/seg_{$index}.mp3";
        $aacFile = "{$tmpDir}/seg_{$index}.aac";

        try {
            // Calculate silence gap from previous segment's end
            $prevEnd = 0.0;
            if ($index > 0) {
                $prevJson = Redis::get("instant-dub:{$sessionId}:chunk:" . ($index - 1));
                if ($prevJson) {
                    $prev = json_decode($prevJson, true);
                    $prevEnd = (float) ($prev['end_time'] ?? 0);
                }
            }
            $gap = max(0, ($chunk['start_time'] ?? 0) - $prevEnd);

            $audioBase64 = $chunk['audio_base64'] ?? null;

            if (!$audioBase64) {
                // Error chunk — generate silence for the full slot
                $duration = max(0.1, ($chunk['end_time'] ?? 0) - $prevEnd);
                Process::timeout(15)->run([
                    'ffmpeg', '-y', '-f', 'lavfi', '-t', (string) round($duration, 3),
                    '-i', 'anullsrc=r=44100:cl=mono',
                    '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $aacFile,
                ]);
            } elseif ($gap > 0.01) {
                // Prepend silence gap, then convert MP3 → AAC
                file_put_contents($mp3File, base64_decode($audioBase64));
                Process::timeout(15)->run([
                    'ffmpeg', '-y',
                    '-f', 'lavfi', '-t', (string) round($gap, 3),
                    '-i', 'anullsrc=r=44100:cl=mono',
                    '-i', $mp3File,
                    '-filter_complex', '[0:a][1:a]concat=n=2:v=0:a=1',
                    '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
                ]);
            } else {
                // No gap — just convert MP3 → AAC
                file_put_contents($mp3File, base64_decode($audioBase64));
                Process::timeout(15)->run([
                    'ffmpeg', '-y', '-i', $mp3File,
                    '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
                ]);
            }

            if (!file_exists($aacFile) || filesize($aacFile) < 10) {
                return null;
            }

            return file_get_contents($aacFile);
        } finally {
            @unlink($mp3File);
            @unlink($aacFile);
            @rmdir($tmpDir);
        }
    }
}
