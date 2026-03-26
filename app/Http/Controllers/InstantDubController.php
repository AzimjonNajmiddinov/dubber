<?php

namespace App\Http\Controllers;

use App\Jobs\PrepareInstantDubJob;
use App\Services\ElevenLabs\ElevenLabsClient;
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
            'title' => 'nullable|string|max:255',
        ]);

        $sessionId = Str::uuid()->toString();
        $language = $request->input('language', 'uz');
        $videoUrl = $request->input('video_url', '');
        $translateFrom = $request->input('translate_from', '');
        $srt = $request->input('srt', '');
        $title = $request->input('title', 'Untitled');

        // Parse video URL components for HLS
        $urlWithoutQuery = strtok($videoUrl, '?');
        $videoBaseUrl = $videoUrl ? preg_replace('#/[^/]+$#', '/', $urlWithoutQuery) : '';
        $videoQuery = $videoUrl ? (parse_url($videoUrl, PHP_URL_QUERY) ?? '') : '';

        // Create session immediately so polling works right away
        $session = [
            'id' => $sessionId,
            'title' => $title,
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

        Log::info("[DUB] Session created", ['session' => $sessionId, 'title' => $title, 'language' => $language, 'translate_from' => $translateFrom]);

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
        $status = $session['status'] ?? 'preparing';
        $ready = (int) ($session['segments_ready'] ?? 0);
        $total = (int) ($session['total_segments'] ?? 0);

        // Auto-complete stale sessions: if status is not complete/stopped but no progress for 2 min
        if ($total > 0 && $ready > 0 && !in_array($status, ['complete', 'stopped', 'error'])) {
            $lastProgress = $session['last_progress_at'] ?? null;
            $now = now()->timestamp;

            if ($lastProgress && ($now - $lastProgress) > 120) {
                // No progress for 2 minutes — force complete
                $session['status'] = 'complete';
                $session['playable'] = true;
                Redis::setex("instant-dub:{$sessionId}", 50400, json_encode($session));
                Log::warning("[DUB] Session auto-completed (stale): {$ready}/{$total} segments", [
                    'session' => $sessionId,
                    'stale_seconds' => $now - $lastProgress,
                ]);
            }
        }

        $after = (int) $request->query('after', -1);

        // Batch fetch up to 20 chunks in one Redis call
        $chunkKeys = [];
        for ($i = $after + 1; $i < $after + 21; $i++) {
            $chunkKeys[] = "instant-dub:{$sessionId}:chunk:{$i}";
        }
        $chunkValues = Redis::mget($chunkKeys);

        $chunks = [];
        foreach ($chunkValues as $chunkJson) {
            if (!$chunkJson) break;
            $chunks[] = json_decode($chunkJson, true);
        }

        return response()->json([
            'status' => $session['status'] ?? 'preparing',
            'error' => $session['error'] ?? null,
            'segments_ready' => (int) ($session['segments_ready'] ?? 0),
            'total_segments' => (int) ($session['total_segments'] ?? 0),
            'playable' => !empty($session['playable']),
            'title' => $session['title'] ?? 'Untitled',
            'speakers' => $session['speakers'] ?? null,
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
        $title = $session['title'] ?? 'Untitled';
        $session['status'] = 'stopped';
        Redis::setex("instant-dub:{$sessionId}", 300, json_encode($session));

        Log::info("[DUB] [{$title}] Session stopped", ['session' => $sessionId]);

        // Clean up original audio file
        if (!empty($session['original_audio_path'])) {
            @unlink($session['original_audio_path']);
            $dir = dirname($session['original_audio_path']);
            @rmdir($dir);
        }

        // Delete cloned ElevenLabs voices
        $voiceIdsJson = Redis::get("instant-dub:{$sessionId}:elevenlabs-voices");
        if ($voiceIdsJson) {
            $client = new ElevenLabsClient();
            foreach (json_decode($voiceIdsJson, true) as $voiceId) {
                try { $client->deleteVoice($voiceId); } catch (\Throwable) {}
            }
            Redis::del("instant-dub:{$sessionId}:elevenlabs-voices");
        }

        // Batch delete all chunk keys + cached responses
        $total = $session['total_segments'] ?? 0;
        $keysToDelete = [
            "instant-dub:{$sessionId}:rewritten-master",
            "instant-dub:{$sessionId}:master-playlist",
            "instant-dub:{$sessionId}:vtt-cache",
            "instant-dub:{$sessionId}:voices",
            "instant-dub:{$sessionId}:full-dialogue",
            "instant-dub:{$sessionId}:all-segments",
            "instant-dub:{$sessionId}:character-context",
        ];
        // Clean up batch keys
        $totalBatches = (int) ceil($total / 15);
        for ($i = 0; $i < $totalBatches; $i++) {
            $keysToDelete[] = "instant-dub:{$sessionId}:batch:{$i}";
        }
        for ($i = 0; $i < $total; $i++) {
            $keysToDelete[] = "instant-dub:{$sessionId}:chunk:{$i}";
        }
        if (!empty($keysToDelete)) {
            Redis::del($keysToDelete);
        }

        // Clean up AAC files on disk
        $aacDir = storage_path("app/instant-dub/{$sessionId}/aac");
        if (is_dir($aacDir)) {
            array_map('unlink', glob("{$aacDir}/*.aac"));
            @rmdir($aacDir);
        }
        // Clean up session directory
        $sessionDir = storage_path("app/instant-dub/{$sessionId}");
        if (is_dir($sessionDir)) {
            @rmdir($sessionDir);
        }

        // Clean up tmp dir used by segment jobs
        $tmpDir = '/tmp/instant-dub-' . $sessionId;
        if (is_dir($tmpDir)) {
            array_map('unlink', glob("{$tmpDir}/*"));
            @rmdir($tmpDir);
        }

        return response()->json(['status' => 'stopped']);
    }

    // ── Server-Sent Events for real-time updates ──

    public function events(string $sessionId)
    {
        $session = $this->getSession($sessionId);
        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        return response()->stream(function () use ($sessionId) {
            set_time_limit(0);

            // Kill ALL output buffering layers (PHP + FastCGI)
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            // Pad first write to exceed FastCGI buffer threshold
            echo ':' . str_repeat(' ', 4096) . "\n\n";
            flush();

            $lastReady = -1;
            $lastStatus = '';
            $lastProgress = '';
            $lastWarning = '';
            $tick = 0;

            while (true) {
                if (connection_aborted()) break;

                $session = $this->getSession($sessionId);
                if (!$session) {
                    $this->sseEvent('error', ['error' => 'Session expired']);
                    break;
                }

                $status = $session['status'] ?? 'preparing';
                $ready = (int) ($session['segments_ready'] ?? 0);
                $total = (int) ($session['total_segments'] ?? 0);
                $error = $session['error'] ?? null;
                $progress = $session['progress'] ?? null;
                $warning = $session['last_warning'] ?? null;
                $playable = !empty($session['playable']);

                // Send warning event (transient errors like 429)
                if ($warning && $warning !== $lastWarning) {
                    $this->sseEvent('warning', ['message' => $warning]);
                    $lastWarning = $warning;
                }

                // Send update if state changed
                if ($status !== $lastStatus || $ready !== $lastReady || $progress !== $lastProgress) {
                    $this->sseEvent('update', [
                        'status' => $status,
                        'segments_ready' => $ready,
                        'total_segments' => $total,
                        'playable' => $playable,
                        'progress' => $progress,
                        'error' => $error,
                    ]);
                    $lastStatus = $status;
                    $lastReady = $ready;
                    $lastProgress = $progress;
                }

                // Terminal states — close connection
                if (in_array($status, ['complete', 'stopped', 'error'])) {
                    $this->sseEvent('done', ['status' => $status]);
                    break;
                }

                // Heartbeat every 15s to keep connection alive
                if ($tick > 0 && $tick % 15 === 0) {
                    echo ": heartbeat\n\n";
                    flush();
                }

                $tick++;
                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function sseEvent(string $event, array $data): void
    {
        echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
        flush();
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

        // Cache the fully rewritten master playlist — deterministic, no need to recompute
        $rewrittenKey = "instant-dub:{$sessionId}:rewritten-master";
        $cached = Redis::get($rewrittenKey);
        if ($cached) {
            return response($cached, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'max-age=300',
            ]);
        }

        // Fetch original master playlist (cached separately for CDN token lifetime)
        $masterCacheKey = "instant-dub:{$sessionId}:master-playlist";
        $master = Redis::get($masterCacheKey);
        if (!$master) {
            $response = Http::timeout(10)->get($videoUrl);
            if ($response->failed()) {
                return response('Failed to fetch master playlist', 502);
            }
            $master = $response->body();
            Redis::setex($masterCacheKey, 50400, $master);
        }
        $videoBaseUrl = $session['video_base_url'] ?? '';
        $videoQuery = $session['video_query'] ?? '';
        $lang = $session['language'] ?? 'uz';
        $langNames = ['uz' => "O'zbek dublyaj", 'ru' => 'Русский дубляж', 'en' => 'English dub'];
        $dubName = $langNames[$lang] ?? ucfirst($lang) . ' dub';
        $subNames = ['uz' => "O'zbek", 'ru' => 'Русский', 'en' => 'English'];
        $subName = $subNames[$lang] ?? ucfirst($lang);

        $lines = explode("\n", $master);

        // Inject EXT-X-INDEPENDENT-SEGMENTS if not present
        $hasIndependent = false;
        foreach ($lines as $line) {
            if (str_contains(trim($line), '#EXT-X-INDEPENDENT-SEGMENTS')) {
                $hasIndependent = true;
                break;
            }
        }
        if (!$hasIndependent) {
            array_splice($lines, 1, 0, ['#EXT-X-INDEPENDENT-SEGMENTS']);
        }

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
        $dubInjected = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Inject dub audio track BEFORE existing audio tracks so iOS picks it first
            if (!$dubInjected && str_starts_with($trimmed, '#EXT-X-MEDIA') && str_contains($trimmed, 'TYPE=AUDIO')) {
                $output[] = "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"{$dubName}\",LANGUAGE=\"{$lang}\",URI=\"dub-audio.m3u8\",DEFAULT=YES,AUTOSELECT=YES";
                $dubInjected = true;
            }

            // Inject before STREAM-INF if no existing audio tracks
            if (!$dubInjected && str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                if (!$existingAudioGroup) {
                    $output[] = "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"Original\",DEFAULT=NO,AUTOSELECT=NO";
                }
                $output[] = "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"{$dubName}\",LANGUAGE=\"{$lang}\",URI=\"dub-audio.m3u8\",DEFAULT=YES,AUTOSELECT=YES";
                $output[] = "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"{$subsGroupId}\",NAME=\"{$subName}\",LANGUAGE=\"{$lang}\",URI=\"dub-subtitles.m3u8\",DEFAULT=NO,AUTOSELECT=YES,FORCED=NO";
                $dubInjected = true;
            }

            // Inject subtitles before STREAM-INF
            if (str_starts_with($trimmed, '#EXT-X-STREAM-INF') && !str_contains(implode("\n", $output), 'dub-subtitles')) {
                $output[] = "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"{$subsGroupId}\",NAME=\"{$subName}\",LANGUAGE=\"{$lang}\",URI=\"dub-subtitles.m3u8\",DEFAULT=NO,AUTOSELECT=YES,FORCED=NO";
            }

            // Set existing audio tracks to DEFAULT=NO
            if (str_starts_with($trimmed, '#EXT-X-MEDIA') && str_contains($trimmed, 'TYPE=AUDIO') && !str_contains($trimmed, 'dub-audio')) {
                $line = preg_replace('/DEFAULT=YES/', 'DEFAULT=NO', $line);
                $line = preg_replace('/AUTOSELECT=YES/', 'AUTOSELECT=NO', $line);
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

        $result = implode("\n", $output);

        // Cache rewritten master — it won't change for this session
        Redis::setex($rewrittenKey, 50400, $result);

        return response($result, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'max-age=300',
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
        $allDone = in_array($status, ['complete', 'stopped']);

        // Batch fetch all chunks in one Redis call (eliminates N+1)
        $chunkKeys = [];
        for ($i = 0; $i < $total; $i++) {
            $chunkKeys[] = "instant-dub:{$sessionId}:chunk:{$i}";
        }
        $chunkValues = $total > 0 ? Redis::mget($chunkKeys) : [];

        // Sequential-only while in progress: include segments 0..horizon where ALL are present.
        // EVENT playlists must only append — never modify existing entries.
        // Once complete: include ALL segments, skipping gaps (use silent fallback for missing).
        $chunks = [];
        $horizon = -1;
        foreach ($chunkValues as $i => $chunkJson) {
            if (!$chunkJson) {
                if ($allDone) continue; // skip gaps when complete
                break; // stop at first gap while in progress
            }
            $chunks[$i] = json_decode($chunkJson, true);
            $horizon = $i;
        }

        // Each segment covers its full "slot" — from its start to the next segment's start.
        $entries = [];

        // When no segments are ready yet, serve a short silent segment so the EVENT playlist
        // is valid. iOS AVPlayer won't reload an empty EVENT playlist (no segments = no
        // reload interval reference). The 5s silent segment gives the player something to
        // play while it polls for new segments every 3s (Cache-Control: max-age=3).
        if ($horizon < 0 && !$allDone) {
            $entries[] = [
                'uri' => 'dub-segment/lead.aac',
                'duration' => 5.0,
            ];
        }

        // Add silent lead-in segment if first dialogue starts after time 0
        if ($horizon >= 0) {
            $firstStart = (float) ($chunks[0]['start_time'] ?? 0);
            if ($firstStart > 1.0) {
                // Use actual lead AAC duration if available
                $leadFile = storage_path("app/instant-dub/{$sessionId}/aac/lead.aac");
                $leadDur = round($firstStart, 3);
                if (file_exists($leadFile)) {
                    $probe = trim(shell_exec("ffprobe -v error -show_entries format=duration -of csv=p=0 " . escapeshellarg($leadFile) . " 2>/dev/null") ?? '');
                    if ($probe && (float) $probe > 0.1) {
                        $leadDur = round((float) $probe, 3);
                    }
                }
                $entries[] = [
                    'uri' => 'dub-segment/lead.aac',
                    'duration' => $leadDur,
                ];
            }
        }

        for ($i = 0; $i <= $horizon; $i++) {
            if (!isset($chunks[$i])) {
                $entries[] = [
                    'uri' => "dub-segment/{$i}.aac",
                    'duration' => 3.0,
                ];
                continue;
            }

            $chunk = $chunks[$i];
            $startTime = (float) ($chunk['start_time'] ?? 0);
            $endTime = (float) ($chunk['end_time'] ?? 0);

            // Find next chunk for slot duration
            $nextStart = null;
            for ($j = $i + 1; $j <= $horizon; $j++) {
                if (isset($chunks[$j])) {
                    $nextStart = (float) ($chunks[$j]['start_time'] ?? 0);
                    break;
                }
            }
            $slotEnd = $nextStart ?? $endTime;
            $slotDur = round(max(0.1, $slotEnd - $startTime), 3);

            // Use actual AAC file duration if available (prevents cumulative drift)
            $aacDur = (float) ($chunk['aac_duration'] ?? 0);
            $duration = ($aacDur > 0.1) ? $aacDur : $slotDur;

            // One segment per slot (speech + gap combined)
            $entries[] = [
                'uri' => "dub-segment/{$i}.aac",
                'duration' => $duration,
            ];
        }

        // Prepare tail segment info before TARGETDURATION calculation
        $tailDuration = 0.0;
        if ($allDone && count($entries) > 0) {
            $tailDuration = (float) ($session['tail_duration'] ?? 0);
            if ($tailDuration < 5) {
                $tailDuration = 0.0;
            }
        }

        // Calculate actual max duration from entries for TARGETDURATION
        $maxDur = 10;
        foreach ($entries as $entry) {
            $maxDur = max($maxDur, (int) ceil($entry['duration']));
        }
        if ($tailDuration > 0) {
            $maxDur = max($maxDur, (int) ceil($tailDuration));
        }

        $m3u8 = "#EXTM3U\n";
        $m3u8 .= "#EXT-X-VERSION:3\n";
        $m3u8 .= "#EXT-X-TARGETDURATION:{$maxDur}\n";
        $m3u8 .= "#EXT-X-MEDIA-SEQUENCE:0\n";
        $m3u8 .= "#EXT-X-INDEPENDENT-SEGMENTS\n";

        if (!$allDone) {
            $m3u8 .= "#EXT-X-PLAYLIST-TYPE:EVENT\n";
        }

        foreach ($entries as $entry) {
            $m3u8 .= "#EXTINF:{$entry['duration']},\n";
            $m3u8 .= "{$entry['uri']}\n";
        }

        // Add trailing silent segment after last dialogue to prevent player pause
        if ($tailDuration > 0) {
            $m3u8 .= "#EXTINF:{$tailDuration},\n";
            $m3u8 .= "dub-segment/tail.aac\n";
        }
        if ($allDone) {
            $m3u8 .= "#EXT-X-ENDLIST\n";
        }

        $cacheControl = $allDone ? 'max-age=3600' : 'max-age=3';

        return response($m3u8, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => $cacheControl,
        ]);
    }

    public function hlsInitSegment(string $sessionId)
    {
        // No longer needed — ADTS format doesn't require init segments
        return response('', 404);
    }

    public function hlsGapSegment(string $sessionId, int $index)
    {
        $aacFile = storage_path("app/instant-dub/{$sessionId}/aac/gap-{$index}.aac");

        if (file_exists($aacFile) && filesize($aacFile) > 10) {
            $session = $this->getSession($sessionId);
            $status = $session['status'] ?? 'processing';
            $cacheControl = in_array($status, ['complete', 'stopped']) ? 'max-age=86400' : 'max-age=10';

            return response()->file($aacFile, [
                'Content-Type' => 'audio/aac',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => $cacheControl,
            ]);
        }

        return $this->silentAacResponse();
    }

    public function hlsLeadSegment(string $sessionId)
    {
        $aacFile = storage_path("app/instant-dub/{$sessionId}/aac/lead.aac");

        if (file_exists($aacFile) && filesize($aacFile) > 100) {
            $session = $this->getSession($sessionId);
            $status = $session['status'] ?? 'processing';
            $cacheControl = in_array($status, ['complete', 'stopped']) ? 'max-age=86400' : 'max-age=10';

            return response()->file($aacFile, [
                'Content-Type' => 'audio/aac',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => $cacheControl,
            ]);
        }

        return $this->silentAacResponse();
    }

    public function hlsTailSegment(string $sessionId)
    {
        $aacFile = storage_path("app/instant-dub/{$sessionId}/aac/tail.aac");

        if (file_exists($aacFile) && filesize($aacFile) > 100) {
            return response()->file($aacFile, [
                'Content-Type' => 'audio/aac',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'max-age=86400',
            ]);
        }

        return $this->silentAacResponse();
    }

    public function hlsAudioSegment(string $sessionId, int $index)
    {
        $aacFile = storage_path("app/instant-dub/{$sessionId}/aac/{$index}.aac");

        if (file_exists($aacFile) && filesize($aacFile) > 10) {
            $session = $this->getSession($sessionId);
            $status = $session['status'] ?? 'processing';
            $cacheControl = in_array($status, ['complete', 'stopped']) ? 'max-age=86400' : 'max-age=10';

            return response()->file($aacFile, [
                'Content-Type' => 'audio/aac',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => $cacheControl,
            ]);
        }

        Log::warning("[DUB] Segment {$index} pre-gen AAC missing, using fallback", [
            'session' => $sessionId,
            'exists' => file_exists($aacFile),
            'size' => file_exists($aacFile) ? filesize($aacFile) : 'N/A',
        ]);

        // Fallback: generate on-demand if pre-gen missed (e.g. race condition, old session)
        $chunkJson = Redis::get("instant-dub:{$sessionId}:chunk:{$index}");
        if (!$chunkJson) {
            return $this->silentAacResponse();
        }

        $session = $this->getSession($sessionId);
        $originalAudioPath = $session['original_audio_path'] ?? null;

        $chunk = json_decode($chunkJson, true);
        [$slotStart, $slotEnd] = $this->computeSlotBounds($sessionId, $index, $chunk);
        $aacData = $this->generateAacSegment($chunk, $originalAudioPath, $slotStart, $slotEnd);

        if (!$aacData) {
            return response('Failed to generate AAC segment', 500);
        }

        // Cache to disk for subsequent requests
        $aacDir = dirname($aacFile);
        try {
            if (!is_dir($aacDir)) {
                mkdir($aacDir, 0755, true);
            }
            file_put_contents($aacFile, $aacData);
        } catch (\Throwable) {
            // Non-fatal
        }

        return response($aacData, 200, [
            'Content-Type' => 'audio/aac',
            'Content-Length' => strlen($aacData),
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
        $total = (int) ($session['total_segments'] ?? 0);

        // Get last chunk's end_time for duration
        $lastChunkJson = $total > 0
            ? Redis::get("instant-dub:{$sessionId}:chunk:" . ($total - 1))
            : null;
        $totalDuration = 3600; // fallback 1h
        if ($lastChunkJson) {
            $lastChunk = json_decode($lastChunkJson, true);
            $totalDuration = (int) ceil((float) ($lastChunk['end_time'] ?? 3600));
        }

        $m3u8 = "#EXTM3U\n";
        $m3u8 .= "#EXT-X-VERSION:3\n";
        $m3u8 .= "#EXT-X-TARGETDURATION:{$totalDuration}\n";
        $m3u8 .= "#EXT-X-MEDIA-SEQUENCE:0\n";

        if ($status !== 'complete') {
            $m3u8 .= "#EXT-X-PLAYLIST-TYPE:EVENT\n";
        }

        $m3u8 .= "#EXTINF:{$totalDuration},\n";
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

        $status = $session['status'] ?? 'preparing';

        // Serve cached VTT for completed sessions
        if ($status === 'complete') {
            $cached = Redis::get("instant-dub:{$sessionId}:vtt-cache");
            if ($cached) {
                return response($cached, 200, [
                    'Content-Type' => 'text/vtt',
                    'Access-Control-Allow-Origin' => '*',
                    'Cache-Control' => 'max-age=3600',
                ]);
            }
        }

        $total = (int) ($session['total_segments'] ?? 0);
        $vtt = "WEBVTT\n\n";

        // Batch fetch all chunks
        $chunkKeys = [];
        for ($i = 0; $i < $total; $i++) {
            $chunkKeys[] = "instant-dub:{$sessionId}:chunk:{$i}";
        }
        $chunkValues = $total > 0 ? Redis::mget($chunkKeys) : [];

        foreach ($chunkValues as $chunkJson) {
            if (!$chunkJson) continue;

            $chunk = json_decode($chunkJson, true);
            $start = $this->formatVttTime((float) ($chunk['start_time'] ?? 0));
            $end = $this->formatVttTime((float) ($chunk['end_time'] ?? 0));
            $text = $chunk['text'] ?? '';

            if ($text) {
                $vtt .= "{$start} --> {$end}\n{$text}\n\n";
            }
        }

        // Cache VTT once session is complete (won't change)
        if ($status === 'complete') {
            Redis::setex("instant-dub:{$sessionId}:vtt-cache", 50400, $vtt);
        }

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
            'Cache-Control' => 'max-age=60',
        ]);
    }

    // ── Private helpers ──

    /**
     * Return a minimal silent AAC ADTS segment for missing chunks.
     */
    private function silentAacResponse()
    {
        $silentFile = storage_path('app/silent.aac');
        if (!file_exists($silentFile)) {
            Process::timeout(5)->run([
                'ffmpeg', '-y', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono',
                '-t', '0.5', '-c:a', 'aac', '-b:a', '32k', '-f', 'adts', $silentFile,
            ]);
        }

        if (file_exists($silentFile)) {
            return response()->file($silentFile, [
                'Content-Type' => 'audio/aac',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'max-age=86400',
            ]);
        }

        return response('', 200, [
            'Content-Type' => 'audio/aac',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function getSession(string $sessionId): ?array
    {
        $json = Redis::get("instant-dub:{$sessionId}");
        return $json ? json_decode($json, true) : null;
    }

    /**
     * Compute the extended slot boundaries for a segment.
     * Segment 0 starts at time 0 (absorbs leading gap for timeline alignment).
     * Each segment extends to the next chunk's start_time.
     */
    private function computeSlotBounds(string $sessionId, int $index, array $chunk): array
    {
        $startTime = (float) ($chunk['start_time'] ?? 0);
        $endTime = (float) ($chunk['end_time'] ?? 0);

        $slotStart = $startTime;

        $nextJson = Redis::get("instant-dub:{$sessionId}:chunk:" . ($index + 1));
        if ($nextJson) {
            $next = json_decode($nextJson, true);
            $slotEnd = (float) ($next['start_time'] ?? $endTime);
        } else {
            $slotEnd = $endTime;
        }

        return [$slotStart, $slotEnd];
    }

    /**
     * Generate AAC for a TTS segment.
     * Mixes TTS with original audio at 20% if available.
     * Pads/trims to exact slot duration.
     */
    private function generateAacSegment(array $chunk, ?string $originalAudioPath = null, float $slotStart = -1, float $slotEnd = -1): ?string
    {
        $tmpDir = sys_get_temp_dir() . '/hls-dub-' . Str::random(8);
        @mkdir($tmpDir, 0755, true);

        $mp3File = "{$tmpDir}/seg.mp3";
        $aacFile = "{$tmpDir}/seg.aac";
        $hasBg = $originalAudioPath && file_exists($originalAudioPath);

        try {
            $startTime = (float) ($chunk['start_time'] ?? 0);
            $endTime = (float) ($chunk['end_time'] ?? 0);

            if ($slotStart < 0) $slotStart = $startTime;
            if ($slotEnd < 0) $slotEnd = $endTime;

            $slotDuration = round(max(0.1, $slotEnd - $slotStart), 3);
            $preGap = max(0, $startTime - $slotStart);
            $preGapMs = (int) round($preGap * 1000);

            $audioBase64 = $chunk['audio_base64'] ?? null;

            if (!$audioBase64) {
                if ($hasBg) {
                    Process::timeout(20)->run([
                        'ffmpeg', '-y',
                        '-i', $originalAudioPath,
                        '-ss', (string) round($slotStart, 3),
                        '-t', (string) $slotDuration,
                        '-af', 'volume=0.2',
                        '-ac', '1', '-ar', '44100', '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $aacFile,
                    ]);
                } else {
                    Process::timeout(15)->run([
                        'ffmpeg', '-y', '-f', 'lavfi', '-t', (string) $slotDuration,
                        '-i', 'anullsrc=r=44100:cl=mono',
                        '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $aacFile,
                    ]);
                }
            } else {
                file_put_contents($mp3File, base64_decode($audioBase64));

                if ($hasBg) {
                    $delayFilter = $preGapMs > 0 ? "adelay={$preGapMs}|{$preGapMs}," : '';
                    Process::timeout(20)->run([
                        'ffmpeg', '-y',
                        '-i', $mp3File,
                        '-ss', (string) round($slotStart, 3), '-i', $originalAudioPath,
                        '-filter_complex',
                        "[0:a]aresample=44100,{$delayFilter}apad=whole_dur={$slotDuration}[tts];[1:a]atrim=duration={$slotDuration},volume=0.2[bg];[tts][bg]amix=inputs=2:duration=first:normalize=0",
                        '-t', (string) $slotDuration,
                        '-ac', '1', '-c:a', 'aac', '-b:a', '128k', '-f', 'adts', $aacFile,
                    ]);
                } else {
                    $delayFilter = $preGapMs > 0 ? "adelay={$preGapMs}|{$preGapMs}," : '';
                    Process::timeout(15)->run([
                        'ffmpeg', '-y', '-i', $mp3File,
                        '-af', "aresample=44100,{$delayFilter}apad=whole_dur={$slotDuration}",
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
