<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadOriginalAudioJob;
use App\Jobs\PrepareInstantDubJob;
use App\Models\InstantDub;
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
    public function voices(): JsonResponse
    {
        $voices = [];
        foreach (['male', 'female', 'child'] as $gender) {
            $dir   = storage_path("app/voice-pool/{$gender}");
            $files = is_dir($dir) ? glob("{$dir}/*.{wav,mp3,m4a}", GLOB_BRACE) : [];
            foreach ($files as $file) {
                $name     = pathinfo($file, PATHINFO_FILENAME);
                $voices[] = ['voice_id' => $name, 'name' => ucfirst($name), 'gender' => $gender, 'language' => 'uz'];
            }
        }

        return response()->json($voices);
    }

    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'srt'            => 'nullable|string',
            'language'       => 'required|string|max:10',
            'video_url'      => 'nullable|string',
            'audio_url'      => 'nullable|string',
            'translate_from' => 'nullable|string|max:10',
            'title'          => 'nullable|string|max:255',
            'quality'        => 'nullable|string|in:standard,premium',
            'voice_id'       => 'nullable|string|max:100',
        ]);

        $sessionId = Str::uuid()->toString();
        $language = $request->input('language', 'uz');
        $videoUrl = $request->input('video_url', '');
        $translateFrom = $request->input('translate_from', '');
        $srt      = (string) ($request->input('srt') ?? '');
        $audioUrl = (string) ($request->input('audio_url') ?? '') ?: null;
        $title    = $request->input('title', 'Untitled');
        $quality    = $request->input('quality', 'standard');
        $forceVoice = $request->input('voice_id') ?: null;
        $ttsDriver  = $quality === 'premium' ? 'elevenlabs' : 'mms';

        // Parse video URL components for HLS
        $urlWithoutQuery = strtok($videoUrl, '?');
        $videoBaseUrl = $videoUrl ? preg_replace('#/[^/]+$#', '/', $urlWithoutQuery) : '';
        $videoQuery = $videoUrl ? (parse_url($videoUrl, PHP_URL_QUERY) ?? '') : '';

        // Check cache — skip re-dubbing if we already have a result
        if ($videoUrl && !$srt) {
            $contentKey = InstantDub::extractContentKey($videoUrl);
            $cached = InstantDub::where('video_content_key', $contentKey)
                ->where('language', $language)
                ->whereIn('status', ['complete', 'needs_retts'])
                ->first();
            // Fallback: match by full URL for older records without content_key
            if (!$cached) {
                $cached = InstantDub::where('video_url', $urlWithoutQuery)
                    ->where('language', $language)
                    ->whereIn('status', ['complete', 'needs_retts'])
                    ->whereNull('video_content_key')
                    ->first();
            }

            if ($cached) {
                // Always re-TTS with selected voice — translations are cached in DB,
                // but audio is always regenerated fresh (never serve stale TTS files)
                if ($cached->status === 'complete') {
                    $cached->update(['status' => 'needs_retts', 'session_id' => $sessionId]);
                    $cached = $cached->fresh();
                }

                $session = $this->buildSessionFromCache($cached, $sessionId, $videoUrl, $videoBaseUrl, $videoQuery, $title, $forceVoice);
                Redis::setex("instant-dub:{$sessionId}", 50400, json_encode($session));

                $cached->update(['status' => 'processing', 'session_id' => $sessionId]);
                PrepareInstantDubJob::dispatch(
                    $sessionId, $videoUrl, $language, $translateFrom, $srt, $cached->id,
                )->onQueue('segment-generation');
                Log::info("[DUB] Cache hit (re-TTS) for: {$urlWithoutQuery} [{$language}]", ['session' => $sessionId]);
                return response()->json(['session_id' => $sessionId]);
            }
        }

        // No cache — full pipeline
        $session = [
            'id'             => $sessionId,
            'title'          => $title,
            'language'       => $language,
            'video_url'      => $videoUrl,
            'video_base_url' => $videoBaseUrl,
            'video_query'    => $videoQuery,
            'status'         => 'preparing',
            'quality'        => $quality,
            'tts_driver'     => $ttsDriver,
            'force_voice'    => $forceVoice,
            'disable_prosody' => (bool) $forceVoice,
            'total_segments' => 0,
            'segments_ready' => 0,
            'created_at'     => now()->toIso8601String(),
        ];

        Redis::setex("instant-dub:{$sessionId}", 50400, json_encode($session));

        PrepareInstantDubJob::dispatch(
            $sessionId, $videoUrl, $language, $translateFrom, $srt, null, $audioUrl,
        )->onQueue('segment-generation');

        Log::info("[DUB] Session created", [
            'session'   => $sessionId,
            'title'     => $title,
            'language'  => $language,
            'srt_len'   => strlen($srt),
            'audio_url' => $audioUrl ? substr($audioUrl, 0, 60) : null,
        ]);

        return response()->json(['session_id' => $sessionId]);
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
            if (!$chunkJson) continue;
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
                $output[] = "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"{$subsGroupId}\",NAME=\"{$subName}\",LANGUAGE=\"{$lang}\",URI=\"dub-subtitles.m3u8\",DEFAULT=YES,AUTOSELECT=YES,FORCED=NO";
                $dubInjected = true;
            }

            // Inject subtitles before STREAM-INF
            if (str_starts_with($trimmed, '#EXT-X-STREAM-INF') && !str_contains(implode("\n", $output), 'dub-subtitles')) {
                $output[] = "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"{$subsGroupId}\",NAME=\"{$subName}\",LANGUAGE=\"{$lang}\",URI=\"dub-subtitles.m3u8\",DEFAULT=YES,AUTOSELECT=YES,FORCED=NO";
            }

            // Set existing audio and subtitle tracks to DEFAULT=NO/AUTOSELECT=NO (ours takes priority)
            if (str_starts_with($trimmed, '#EXT-X-MEDIA') && !str_contains($trimmed, 'dub-audio') && !str_contains($trimmed, 'dub-subtitles')) {
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

        // Build playlist from bg chunks (each chunk = one segment, contains bg + TTS)
        $bgChunks     = $session['bg_chunks'] ?? [];
        $totalBg      = (int) ($session['total_bg_chunks'] ?? 0);
        $availableBg  = count($bgChunks);
        $entries      = [];

        $aacDir = $this->aacDir($sessionId);
        $readyCount = 0;

        if (!empty($bgChunks)) {
            // Only list segments in strict sequential order (0,1,2,...).
            // Stop at the first gap or missing file — HLS EVENT playlists are
            // append-only, so a gap would cause the player to jump in time and
            // desync (or fall back to the original audio track).
            ksort($bgChunks);
            foreach ($bgChunks as $bgIdx => $bgChunk) {
                // Require strict sequence: 0, 1, 2, ...
                if ($bgIdx !== $readyCount) break;
                $aacFile = "{$aacDir}/bg-{$bgIdx}.aac";
                if (!file_exists($aacFile) || filesize($aacFile) <= 10) break;
                $cs  = (float) ($bgChunk['start'] ?? 0);
                $ce  = (float) ($bgChunk['end'] ?? 0);
                $dur = round($this->frameAlignedDuration($cs, $ce), 6);
                $entries[] = ['uri' => "dub-segment/bg-{$bgIdx}.aac", 'duration' => $dur];
                $readyCount++;
            }
        }

        // If nothing is ready yet, serve a short silence placeholder so the
        // EVENT playlist stays syntactically valid while we wait.
        if (empty($entries) && !$allDone) {
            $entries[] = ['uri' => 'dub-segment/bg-0.aac', 'duration' => 5.0];
        }

        $allBgDone = $totalBg > 0 && $readyCount >= $totalBg;

        // Calculate TARGETDURATION
        $maxDur = 10;
        foreach ($entries as $entry) {
            $maxDur = max($maxDur, (int) ceil($entry['duration']));
        }

        $m3u8 = "#EXTM3U\n";
        $m3u8 .= "#EXT-X-VERSION:3\n";
        $m3u8 .= "#EXT-X-TARGETDURATION:{$maxDur}\n";
        $m3u8 .= "#EXT-X-MEDIA-SEQUENCE:0\n";
        $m3u8 .= "#EXT-X-INDEPENDENT-SEGMENTS\n";

        if (!($allDone && $allBgDone)) {
            $m3u8 .= "#EXT-X-PLAYLIST-TYPE:EVENT\n";
        }

        foreach ($entries as $entry) {
            $m3u8 .= "#EXTINF:{$entry['duration']},\n";
            $m3u8 .= "{$entry['uri']}\n";
        }

        if ($allDone && $allBgDone) {
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

    private function aacDir(string $sessionId): string
    {
        $session = $this->getSession($sessionId);
        return $session['aac_base_dir'] ?? storage_path("app/instant-dub/{$sessionId}/aac");
    }

    public function hlsGapSegment(string $sessionId, int $index)
    {
        $aacFile = $this->aacDir($sessionId) . "/gap-{$index}.aac";

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
        $aacFile = $this->aacDir($sessionId) . "/lead.aac";

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

        // File missing (cache hit with TTS-only storage) — pull background from HLS
        $session = $this->getSession($sessionId);
        $total = (int) ($session['total_segments'] ?? 0);
        if ($total > 0) {
            $firstChunkJson = Redis::get("instant-dub:{$sessionId}:chunk:0");
            if ($firstChunkJson) {
                $firstChunk = json_decode($firstChunkJson, true);
                $firstStart = (float) ($firstChunk['start_time'] ?? 0);
                if ($firstStart > 1.0) {
                    $duration = round($this->frameAlignedDuration(0, $firstStart), 6);
                    if ($this->generateLeadFromHls($sessionId, $duration)) {
                        return response()->file($aacFile, [
                            'Content-Type'  => 'audio/aac',
                            'Access-Control-Allow-Origin' => '*',
                            'Cache-Control' => 'max-age=86400',
                        ]);
                    }
                    return $this->silentAacOfDuration($duration);
                }
            }
        }

        return $this->silentAacResponse();
    }

    public function hlsBgSegment(string $sessionId, int $index)
    {
        $aacFile = $this->aacDir($sessionId) . "/bg-{$index}.aac";

        if (file_exists($aacFile) && filesize($aacFile) > 10) {
            $session = $this->getSession($sessionId);
            $status  = $session['status'] ?? 'processing';
            $cache   = in_array($status, ['complete', 'stopped']) ? 'max-age=86400' : 'max-age=5';
            return response()->file($aacFile, [
                'Content-Type'              => 'audio/aac',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control'             => $cache,
            ]);
        }

        // Not ready yet — return silence of expected duration
        $session  = $this->getSession($sessionId);
        $bgChunks = $session['bg_chunks'] ?? [];
        $cs = isset($bgChunks[$index]) ? (float)($bgChunks[$index]['start'] ?? 0) : $index * 10.0;
        $ce = isset($bgChunks[$index]) ? (float)($bgChunks[$index]['end'] ?? 0)   : ($index + 1) * 10.0;
        $dur = round($this->frameAlignedDuration($cs, $ce), 6);

        return $this->silentAacOfDuration($dur);
    }

    public function hlsTailSegment(string $sessionId)
    {
        $aacFile = $this->aacDir($sessionId) . "/tail.aac";

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
        $aacFile = $this->aacDir($sessionId) . "/{$index}.aac";

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

    /**
     * Download the first $duration seconds of the HLS audio track and save as lead.aac.
     * Uses a file lock to prevent parallel downloads for the same session.
     */
    private function generateLeadFromHls(string $sessionId, float $duration): bool
    {
        $session = $this->getSession($sessionId);
        $videoUrl = $session['video_url'] ?? '';
        if (!$videoUrl || !str_contains($videoUrl, '.m3u8')) return false;

        $aacDir = storage_path("app/instant-dub/{$sessionId}/aac");
        @mkdir($aacDir, 0755, true);
        $leadFile = "{$aacDir}/lead.aac";

        // File lock — only one request generates; others fall through to silence
        $lockFile = "{$aacDir}/lead.lock";
        $lock = @fopen($lockFile, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            return false;
        }

        try {
            // Re-check after acquiring lock — may have been generated while waiting
            if (file_exists($leadFile) && filesize($leadFile) > 100) {
                return true;
            }

            $audioPlaylistUrl = $this->findHlsAudioPlaylist($videoUrl);
            if (!$audioPlaylistUrl) return false;

            $result = Process::timeout(60)->run([
                'ffmpeg', '-y',
                '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
                '-i', $audioPlaylistUrl,
                '-t', (string) round($duration, 3),
                '-af', 'volume=0.2',
                '-ac', '1', '-ar', '44100',
                '-c:a', 'aac', '-b:a', '64k', '-f', 'adts', $leadFile,
            ]);

            return file_exists($leadFile) && filesize($leadFile) > 100;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockFile);
        }
    }

    /**
     * Parse HLS master playlist and return the URL of the first audio rendition playlist.
     */
    private function findHlsAudioPlaylist(string $masterUrl): ?string
    {
        try {
            $urlWithoutQuery = strtok($masterUrl, '?');
            $baseUrl = preg_replace('#/[^/]+$#', '/', $urlWithoutQuery);
            $query = parse_url($masterUrl, PHP_URL_QUERY) ?? '';

            $master = Http::timeout(10)->get($masterUrl)->body();

            preg_match_all('/^#EXT-X-MEDIA:.*TYPE=AUDIO.*$/m', $master, $audioLines);
            foreach ($audioLines[0] ?? [] as $line) {
                if (preg_match('/URI="([^"]+)"/', $line, $m)) {
                    $url = str_starts_with($m[1], 'http') ? $m[1] : $baseUrl . $m[1];
                    if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . $query;
                    return $url;
                }
            }
        } catch (\Throwable) {}
        return null;
    }

    /**
     * Generate a silent AAC ADTS segment of the given duration (for lead/tail gaps).
     * Result is NOT cached to disk — generated fresh per request (durations vary).
     */
    private function silentAacOfDuration(float $duration): \Illuminate\Http\Response
    {
        $duration = max(0.1, round($duration, 3));
        $tmpFile = sys_get_temp_dir() . '/silent-' . (int)($duration * 1000) . 'ms.aac';

        if (!file_exists($tmpFile) || filesize($tmpFile) < 10) {
            Process::timeout(15)->run([
                'ffmpeg', '-y', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono',
                '-t', (string) $duration,
                '-c:a', 'aac', '-b:a', '32k', '-f', 'adts', $tmpFile,
            ]);
        }

        if (file_exists($tmpFile) && filesize($tmpFile) > 10) {
            $data = file_get_contents($tmpFile);
            return response($data, 200, [
                'Content-Type'  => 'audio/aac',
                'Content-Length' => strlen($data),
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'max-age=3600',
            ]);
        }

        return $this->silentAacResponse();
    }

    private function buildSessionFromCache(InstantDub $dub, string $sessionId, string $videoUrl, string $videoBaseUrl, string $videoQuery, string $title, ?string $forceVoice = null): array
    {
        $isComplete = $dub->status === 'complete';

        return [
            'id'             => $sessionId,
            'title'          => $dub->title ?: $title,
            'language'       => $dub->language,
            'video_url'      => $videoUrl,
            'video_base_url' => $videoBaseUrl,
            'video_query'    => $videoQuery,
            'status'         => 'processing',
            'playable'       => false,
            'tts_driver'     => 'mms',
            'force_voice'    => $forceVoice,
            'disable_prosody' => (bool) $forceVoice,
            'total_segments' => $dub->total_segments,
            'segments_ready' => $isComplete ? $dub->total_segments : 0,
            'cached_dub_id'  => $dub->id,
            'created_at'     => now()->toIso8601String(),
        ];
    }

    private function frameAlignedDuration(float $start, float $end): float
    {
        $startFrames = (int) round($start * 44100 / 1024);
        $endFrames = (int) round($end * 44100 / 1024);
        return max(1, $endFrames - $startFrames) * 1024 / 44100;
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
