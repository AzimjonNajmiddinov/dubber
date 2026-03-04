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

        // Parse video URL components for HLS
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

        // Clean up original audio file
        if (!empty($session['original_audio_path'])) {
            @unlink($session['original_audio_path']);
            $dir = dirname($session['original_audio_path']);
            @rmdir($dir);
        }

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
        $videoBaseUrl = $session['video_base_url'] ?? '';
        $videoQuery = $session['video_query'] ?? '';
        $lang = $session['language'] ?? 'uz';
        $langNames = ['uz' => "O'zbek dublyaj", 'ru' => 'Русский дубляж', 'en' => 'English dub'];
        $dubName = $langNames[$lang] ?? ucfirst($lang) . ' dub';
        $subNames = ['uz' => "O'zbek", 'ru' => 'Русский', 'en' => 'English'];
        $subName = $subNames[$lang] ?? ucfirst($lang);

        $lines = explode("\n", $master);

        // First pass: find existing audio and subtitle group IDs
        $existingAudioGroup = null;
        $existingSubsGroup = null;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '#EXT-X-MEDIA')) {
                if (str_contains($trimmed, 'TYPE=AUDIO') && !$existingAudioGroup) {
                    if (preg_match('/GROUP-ID="([^"]+)"/', $trimmed, $m)) {
                        $existingAudioGroup = $m[1];
                    }
                }
                if (str_contains($trimmed, 'TYPE=SUBTITLES') && !$existingSubsGroup) {
                    if (preg_match('/GROUP-ID="([^"]+)"/', $trimmed, $m)) {
                        $existingSubsGroup = $m[1];
                    }
                }
            }
        }

        $groupId = $existingAudioGroup ?? 'audio';
        $subsGroupId = $existingSubsGroup ?? 'subs';
        $output = [];
        $injected = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Inject dub audio + subtitle tracks before the first STREAM-INF
            if (!$injected && str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                if (!$existingAudioGroup) {
                    $output[] = "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"Original\",DEFAULT=YES,AUTOSELECT=YES";
                }
                $output[] = "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"{$dubName}\",LANGUAGE=\"{$lang}\",URI=\"dub-audio.m3u8\",DEFAULT=NO,AUTOSELECT=NO";
                $output[] = "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"{$subsGroupId}\",NAME=\"{$subName}\",LANGUAGE=\"{$lang}\",URI=\"dub-subtitles.m3u8\",DEFAULT=NO,AUTOSELECT=YES,FORCED=NO";
                $injected = true;
            }

            // Ensure STREAM-INF lines reference audio + subtitle groups
            if (str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                if (str_contains($trimmed, 'AUDIO=')) {
                    if (!$existingAudioGroup) {
                        $line = preg_replace('/AUDIO="[^"]*"/', 'AUDIO="' . $groupId . '"', $line);
                    }
                } else {
                    $line = rtrim($line) . ',AUDIO="' . $groupId . '"';
                }
                if (!str_contains($line, 'SUBTITLES=')) {
                    $line = rtrim($line) . ',SUBTITLES="' . $subsGroupId . '"';
                }
            }

            // EXT-X-MEDIA URIs: convert relative to absolute CDN URLs (skip our own)
            if (str_starts_with($trimmed, '#EXT-X-MEDIA') && str_contains($trimmed, 'URI="')) {
                $line = preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($videoBaseUrl, $videoQuery) {
                    $uri = $m[1];
                    if (str_contains($uri, 'dub-')) return $m[0];
                    if (!str_starts_with($uri, 'http')) {
                        $abs = $videoBaseUrl . $uri;
                        if ($videoQuery) $abs .= (str_contains($abs, '?') ? '&' : '?') . $videoQuery;
                        return 'URI="' . $abs . '"';
                    }
                    return $m[0];
                }, $line);
            }

            // Standalone URIs: convert relative to absolute CDN URLs
            if ($trimmed !== '' && !str_starts_with($trimmed, '#')) {
                if (!str_starts_with($trimmed, 'http')) {
                    $line = $videoBaseUrl . $trimmed;
                    if ($videoQuery) $line .= (str_contains($line, '?') ? '&' : '?') . $videoQuery;
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

        // Build segment list with gap-splitting: long gaps become
        // separate max-10s silence segments so AVPlayer doesn't stall
        $entries = [];
        $prevEnd = 0.0;
        $maxDuration = 10;

        for ($i = 0; $i < $total; $i++) {
            $chunkJson = Redis::get("instant-dub:{$sessionId}:chunk:{$i}");
            if (!$chunkJson) break;

            $chunk = json_decode($chunkJson, true);
            $startTime = (float) ($chunk['start_time'] ?? 0);
            $endTime = (float) ($chunk['end_time'] ?? 0);
            $gap = max(0, $startTime - $prevEnd);

            // Break large gaps into max-10s segments (with background audio if available)
            $gapRemaining = $gap;
            $gapStart = $prevEnd;
            while ($gapRemaining > 0.05) {
                $silenceDur = min($gapRemaining, 10.0);
                $startMs = (int) round($gapStart * 1000);
                $durMs = (int) round($silenceDur * 1000);
                $entries[] = [
                    'uri' => "gap/{$startMs}/{$durMs}.aac",
                    'duration' => round($silenceDur, 3),
                ];
                $gapStart += $silenceDur;
                $gapRemaining -= $silenceDur;
            }

            // TTS segment (speech only, no gap — padded to slot duration)
            $slotDur = max(0.1, $endTime - $startTime);
            $entries[] = [
                'uri' => "dub-segment/{$i}.aac",
                'duration' => round($slotDur, 3),
            ];

            $maxDuration = max($maxDuration, (int) ceil($slotDur));
            $prevEnd = $endTime;
        }

        $m3u8 = "#EXTM3U\n";
        $m3u8 .= "#EXT-X-VERSION:3\n";
        $m3u8 .= "#EXT-X-TARGETDURATION:{$maxDuration}\n";
        $m3u8 .= "#EXT-X-MEDIA-SEQUENCE:0\n";

        if ($status !== 'complete') {
            $m3u8 .= "#EXT-X-PLAYLIST-TYPE:EVENT\n";
        }

        foreach ($entries as $entry) {
            $m3u8 .= "#EXTINF:{$entry['duration']},\n";
            $m3u8 .= "{$entry['uri']}\n";
        }

        if ($status === 'complete' && count($entries) > 0) {
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

        $session = $this->getSession($sessionId);
        $originalAudioPath = $session['original_audio_path'] ?? null;

        $chunk = json_decode($chunkJson, true);
        $aacData = $this->generateAacSegment($chunk, $originalAudioPath);

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

    public function hlsGapSegment(string $sessionId, int $startMs, int $durationMs)
    {
        $durationMs = max(10, min($durationMs, 30000));
        $duration = $durationMs / 1000;
        $startSeconds = $startMs / 1000;

        $session = $this->getSession($sessionId);
        $originalAudioPath = $session['original_audio_path'] ?? null;

        // If we have original audio, mix at 30% for this time range
        if ($originalAudioPath && file_exists($originalAudioPath)) {
            $cacheKey = "instant-dub:{$sessionId}:gap-aac:{$startMs}-{$durationMs}";
            $cached = Redis::get($cacheKey);

            if ($cached) {
                return response(base64_decode($cached), 200, [
                    'Content-Type' => 'audio/aac',
                    'Access-Control-Allow-Origin' => '*',
                    'Cache-Control' => 'max-age=86400',
                ]);
            }

            $tmpFile = sys_get_temp_dir() . "/gap_{$sessionId}_{$startMs}_{$durationMs}.aac";
            Process::timeout(20)->run([
                'ffmpeg', '-y',
                '-ss', (string) round($startSeconds, 3),
                '-t', (string) round($duration, 3),
                '-i', $originalAudioPath,
                '-af', 'volume=0.3',
                '-ac', '1', '-ar', '44100', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $tmpFile,
            ]);

            if (file_exists($tmpFile) && filesize($tmpFile) > 10) {
                $data = file_get_contents($tmpFile);
                @unlink($tmpFile);
                Redis::setex($cacheKey, 50400, base64_encode($data));
                return response($data, 200, [
                    'Content-Type' => 'audio/aac',
                    'Access-Control-Allow-Origin' => '*',
                    'Cache-Control' => 'max-age=86400',
                ]);
            }
            @unlink($tmpFile);
        }

        // Fallback: silence (shared cache)
        $cacheKey = "instant-dub:silence-aac:{$durationMs}";
        $cached = Redis::get($cacheKey);

        if ($cached) {
            return response(base64_decode($cached), 200, [
                'Content-Type' => 'audio/aac',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'max-age=86400',
            ]);
        }

        $tmpFile = sys_get_temp_dir() . "/silence_{$durationMs}.aac";
        Process::timeout(10)->run([
            'ffmpeg', '-y', '-f', 'lavfi', '-t', (string) round($duration, 3),
            '-i', 'anullsrc=r=44100:cl=mono',
            '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $tmpFile,
        ]);

        if (!file_exists($tmpFile) || filesize($tmpFile) < 10) {
            @unlink($tmpFile);
            return response('Failed to generate silence', 500);
        }

        $data = file_get_contents($tmpFile);
        @unlink($tmpFile);

        Redis::setex($cacheKey, 86400, base64_encode($data));

        return response($data, 200, [
            'Content-Type' => 'audio/aac',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'max-age=86400',
        ]);
    }

    // ── HLS Subtitle endpoints ──

    public function hlsSubtitlePlaylist(string $sessionId)
    {
        $session = $this->getSession($sessionId);
        if (!$session) {
            return response('Session not found', 404);
        }

        $status = $session['status'] ?? 'preparing';

        $m3u8 = "#EXTM3U\n";
        $m3u8 .= "#EXT-X-VERSION:3\n";
        $m3u8 .= "#EXT-X-TARGETDURATION:86400\n";
        $m3u8 .= "#EXT-X-MEDIA-SEQUENCE:0\n";

        if ($status !== 'complete') {
            $m3u8 .= "#EXT-X-PLAYLIST-TYPE:EVENT\n";
        }

        $m3u8 .= "#EXTINF:86400,\n";
        $m3u8 .= "dub-subtitles.vtt\n";

        if ($status === 'complete') {
            $m3u8 .= "#EXT-X-ENDLIST\n";
        }

        $cacheControl = $status === 'complete' ? 'max-age=3600' : 'no-cache';

        return response($m3u8, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => $cacheControl,
        ]);
    }

    public function hlsSubtitleVtt(string $sessionId)
    {
        $session = $this->getSession($sessionId);
        if (!$session) {
            return response('Session not found', 404);
        }

        $total = (int) ($session['total_segments'] ?? 0);
        $vtt = "WEBVTT\n\n";

        for ($i = 0; $i < $total; $i++) {
            $chunkJson = Redis::get("instant-dub:{$sessionId}:chunk:{$i}");
            if (!$chunkJson) continue;

            $chunk = json_decode($chunkJson, true);
            $start = $this->formatVttTime((float) ($chunk['start_time'] ?? 0));
            $end = $this->formatVttTime((float) ($chunk['end_time'] ?? 0));
            $text = $chunk['text'] ?? '';

            if ($text) {
                $vtt .= "{$start} --> {$end}\n{$text}\n\n";
            }
        }

        $status = $session['status'] ?? 'preparing';
        $cacheControl = $status === 'complete' ? 'max-age=3600' : 'no-cache';

        return response($vtt, 200, [
            'Content-Type' => 'text/vtt',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => $cacheControl,
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

    /**
     * Generate AAC for a TTS segment.
     * Mixes TTS with original audio at 30% if available.
     * Pads/trims to exact slot duration (start_time → end_time).
     */
    private function generateAacSegment(array $chunk, ?string $originalAudioPath = null): ?string
    {
        $tmpDir = sys_get_temp_dir() . '/hls-dub-' . Str::random(8);
        @mkdir($tmpDir, 0755, true);

        $mp3File = "{$tmpDir}/seg.mp3";
        $aacFile = "{$tmpDir}/seg.aac";
        $hasBg = $originalAudioPath && file_exists($originalAudioPath);

        try {
            $startTime = (float) ($chunk['start_time'] ?? 0);
            $endTime = (float) ($chunk['end_time'] ?? 0);
            $slotDuration = round(max(0.1, $endTime - $startTime), 3);

            $audioBase64 = $chunk['audio_base64'] ?? null;

            if (!$audioBase64) {
                if ($hasBg) {
                    // Background audio only at 30%
                    Process::timeout(20)->run([
                        'ffmpeg', '-y',
                        '-ss', (string) round($startTime, 3),
                        '-t', (string) $slotDuration,
                        '-i', $originalAudioPath,
                        '-af', 'volume=0.3',
                        '-ac', '1', '-ar', '44100', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $aacFile,
                    ]);
                } else {
                    // Silence for the slot
                    Process::timeout(15)->run([
                        'ffmpeg', '-y', '-f', 'lavfi', '-t', (string) $slotDuration,
                        '-i', 'anullsrc=r=44100:cl=mono',
                        '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $aacFile,
                    ]);
                }
            } else {
                file_put_contents($mp3File, base64_decode($audioBase64));

                if ($hasBg) {
                    // Mix TTS (100%) + original audio (30%)
                    Process::timeout(20)->run([
                        'ffmpeg', '-y',
                        '-i', $mp3File,
                        '-ss', (string) round($startTime, 3),
                        '-t', (string) $slotDuration,
                        '-i', $originalAudioPath,
                        '-filter_complex',
                        "[0:a]aresample=44100,apad=whole_dur={$slotDuration}[tts];[1:a]volume=0.3[bg];[tts][bg]amix=inputs=2:duration=first:normalize=0",
                        '-t', (string) $slotDuration,
                        '-ac', '1', '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
                    ]);
                } else {
                    // TTS only, padded to slot duration
                    Process::timeout(15)->run([
                        'ffmpeg', '-y', '-i', $mp3File,
                        '-af', 'aresample=44100,apad=whole_dur=' . $slotDuration,
                        '-t', (string) $slotDuration,
                        '-ac', '1', '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
                    ]);
                }
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

    private function formatVttTime(float $seconds): string
    {
        $hours = (int) ($seconds / 3600);
        $minutes = (int) (fmod($seconds, 3600) / 60);
        $secs = fmod($seconds, 60);
        return sprintf('%02d:%02d:%06.3f', $hours, $minutes, $secs);
    }
}
