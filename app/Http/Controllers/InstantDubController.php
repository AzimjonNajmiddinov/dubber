<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadOriginalAudioJob;
use App\Jobs\PrepareInstantDubJob;
use App\Models\InstantDub;
use App\Services\ElevenLabs\ElevenLabsClient;
use App\Support\AudioFrame;
use App\Support\DubSession;
use App\Support\InstantDubHlsReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class InstantDubController extends Controller
{
    private const BG_CHUNK_SECONDS = 30.0;

    public function voices(): JsonResponse
    {
        $driver = config('dubber.tts.default', 'edge');

        // OpenAI TTS voices for gpt-4o-mini-tts (13 voices)
        if ($driver === 'openai') {
            return response()->json([
                ['voice_id' => 'cedar',   'name' => 'Cedar (Erkak)',   'gender' => 'male',   'language' => 'uz'],
                ['voice_id' => 'onyx',    'name' => 'Onyx (Erkak)',    'gender' => 'male',   'language' => 'uz'],
                ['voice_id' => 'echo',    'name' => 'Echo (Erkak)',    'gender' => 'male',   'language' => 'uz'],
                ['voice_id' => 'verse',   'name' => 'Verse (Erkak)',   'gender' => 'male',   'language' => 'uz'],
                ['voice_id' => 'ash',     'name' => 'Ash (Neytral)',   'gender' => 'male',   'language' => 'uz'],
                ['voice_id' => 'alloy',   'name' => 'Alloy (Neytral)', 'gender' => 'male',   'language' => 'uz'],
                ['voice_id' => 'sage',    'name' => 'Sage (Neytral)',  'gender' => 'male',   'language' => 'uz'],
                ['voice_id' => 'nova',    'name' => 'Nova (Ayol)',     'gender' => 'female', 'language' => 'uz'],
                ['voice_id' => 'shimmer', 'name' => 'Shimmer (Ayol)',  'gender' => 'female', 'language' => 'uz'],
                ['voice_id' => 'coral',   'name' => 'Coral (Ayol)',    'gender' => 'female', 'language' => 'uz'],
                ['voice_id' => 'marin',   'name' => 'Marin (Ayol)',    'gender' => 'female', 'language' => 'uz'],
                ['voice_id' => 'fable',   'name' => 'Fable (Neytral)', 'gender' => 'female', 'language' => 'uz'],
                ['voice_id' => 'ballad',  'name' => 'Ballad (Neytral)','gender' => 'female', 'language' => 'uz'],
            ]);
        }

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
            'video_url'      => 'nullable|url|max:2048',
            'audio_url'      => 'nullable|url|max:2048',
            'translate_from' => 'nullable|string|max:10',
            'title'          => 'nullable|string|max:255',
            'quality'        => 'nullable|string|in:standard,premium',
            'voice_id'       => 'nullable|string|max:100',
        ]);

        $videoUrl = (string) ($request->input('video_url') ?? '');
        if ($videoUrl && !str_starts_with($videoUrl, 'https://')) {
            return response()->json(['message' => 'video_url must use HTTPS.'], 422);
        }
        $audioUrlRaw = (string) ($request->input('audio_url') ?? '');
        if ($audioUrlRaw && !str_starts_with($audioUrlRaw, 'https://')) {
            return response()->json(['message' => 'audio_url must use HTTPS.'], 422);
        }

        $sessionId = Str::uuid()->toString();
        $language = $request->input('language', 'uz');
        $translateFrom = $request->input('translate_from', '');
        $srt      = (string) ($request->input('srt') ?? '');
        $audioUrl = $audioUrlRaw ?: null;
        $title    = $request->input('title', 'Untitled');
        $quality    = 'standard';
        $forceVoice = null;
        $ttsDriver  = 'edge';

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
                DubSession::save($sessionId, $session);

                $cached->update(['status' => 'processing', 'session_id' => $sessionId]);
                PrepareInstantDubJob::dispatch(
                    $sessionId, $videoUrl, $language, $translateFrom, $srt, $cached->id,
                )->onQueue('segment-generation');
                Log::info("[DUB] Cache hit (re-TTS) for: {$urlWithoutQuery} [{$language}]", ['session' => $sessionId]);
                return response()->json(array_merge(['session_id' => $sessionId], $this->hlsResponseUrls($sessionId)));
            }
        }

        // No cache — full pipeline
        $session = [
            'id'             => $sessionId,
            'title'          => $title,
            'language'       => $language,
            'translate_from' => $translateFrom,
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

        DubSession::save($sessionId, $session);

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

        return response()->json(array_merge(['session_id' => $sessionId], $this->hlsResponseUrls($sessionId)));
    }

    public function poll(string $sessionId, Request $request): JsonResponse
    {
        $session = DubSession::get($sessionId);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $status = $session['status'] ?? 'preparing';
        $ready = (int) ($session['segments_ready'] ?? 0);
        $total = (int) ($session['total_segments'] ?? 0);

        // Only complete from poll when all segments are genuinely ready. Partial
        // sessions must keep processing; otherwise long movies look "done" early.
        $isHlsSession = str_contains((string) ($session['video_url'] ?? ''), '.m3u8');
        $totalBgForCompletion = (int) ($session['total_bg_chunks'] ?? 0);
        if (!$isHlsSession && $totalBgForCompletion <= 0 && $total > 0 && $ready >= $total && !in_array($status, ['complete', 'stopped', 'error'])) {
            $lastProgress = $session['last_progress_at'] ?? null;
            $now = now()->timestamp;

            if ($lastProgress && ($now - $lastProgress) > 120) {
                $status = 'complete';
                DubSession::patch($sessionId, ['status' => 'complete']);
                $session['status'] = 'complete';
                Log::warning("[DUB] Session marked complete from poll: {$ready}/{$total} segments", [
                    'session' => $sessionId,
                    'stale_seconds' => $now - $lastProgress,
                ]);
            }
        }

        $after = (int) $request->query('after', -1);

        // Batch fetch up to 20 chunks in one Redis call
        $chunkKeys = [];
        for ($i = $after + 1; $i < $after + 21; $i++) {
            $chunkKeys[] = DubSession::chunkKey($sessionId, $i);
        }
        $chunkValues = Redis::mget($chunkKeys);

        $chunks = [];
        foreach ($chunkValues as $chunkJson) {
            if (!$chunkJson) continue;
            $chunk = json_decode($chunkJson, true);
            // Hydrate audio from disk (file-based storage; audio_path not exposed to client)
            if (!empty($chunk['audio_path']) && file_exists($chunk['audio_path'])) {
                $chunk['audio_base64'] = base64_encode(file_get_contents($chunk['audio_path']));
            } else {
                $chunk['audio_base64'] = null;
            }
            unset($chunk['audio_path']);
            $chunks[] = $chunk;
        }

        $playableState = $this->resolveHlsPlayableState($sessionId, $session);
        $session = $playableState['session'];

        return response()->json([
            'status' => $session['status'] ?? 'preparing',
            'error' => $session['error'] ?? null,
            'segments_ready' => (int) ($session['segments_ready'] ?? 0),
            'total_segments' => (int) ($session['total_segments'] ?? 0),
            'playable' => $playableState['playable'],
            'title' => $session['title'] ?? 'Untitled',
            'speakers' => $session['speakers'] ?? null,
            'hls' => array_merge($this->hlsResponseUrls($sessionId, $playableState['playable']), [
                'playable' => $playableState['playable'],
                'dub_start_time' => (float) ($session['hls_dub_start_time'] ?? 0.0),
                'ready_seconds' => (float) ($session['hls_ready_seconds'] ?? 0.0),
                'required_seconds' => (float) ($session['hls_required_seconds'] ?? 0.0),
                'continuous_until' => (float) ($session['hls_continuous_until'] ?? 0.0),
                'last_ready_bg_idx' => $session['hls_last_ready_bg_idx'] ?? null,
                'complete' => (bool) ($session['hls_complete'] ?? (($session['status'] ?? null) === 'complete')),
            ]),
            'chunks' => $chunks,
        ]);
    }

    public function stop(string $sessionId): JsonResponse
    {
        $session = DubSession::get($sessionId);

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $title = $session['title'] ?? 'Untitled';
        $session['status'] = 'stopped';
        // Intentional short TTL (5 min) for stopped sessions so Redis cleans up quickly
        Redis::setex(DubSession::key($sessionId), 300, json_encode($session));

        Log::info("[DUB] [{$title}] Session stopped", ['session' => $sessionId]);

        // Clean up source audio file
        $sourceAudio = storage_path("app/instant-dub/{$sessionId}/source_audio.m4a");
        if (file_exists($sourceAudio)) {
            @unlink($sourceAudio);
        }

        // Delete cloned ElevenLabs voices
        $voiceIdsJson = Redis::get(DubSession::elevenLabsVoicesKey($sessionId));
        if ($voiceIdsJson) {
            $client = new ElevenLabsClient();
            foreach (json_decode($voiceIdsJson, true) as $voiceId) {
                try { $client->deleteVoice($voiceId); } catch (\Throwable) {}
            }
            Redis::del(DubSession::elevenLabsVoicesKey($sessionId));
        }

        // Batch delete all chunk keys + cached responses
        $total = (int) ($session['total_segments'] ?? 0);
        $totalBatches = (int) ceil($total / 15);
        $keysToDelete = DubSession::allDeleteKeys($sessionId, $total, $totalBatches);
        if (!empty($keysToDelete)) {
            Redis::del($keysToDelete);
        }

        // Recursively delete all session files (audio, AAC, window downloads, etc.)
        $sessionDir = storage_path("app/instant-dub/{$sessionId}");
        $this->deleteDirectory($sessionDir);

        // Clean up tmp dir used by segment TTS jobs
        $this->deleteDirectory('/tmp/instant-dub-' . $sessionId);

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
            $lastPlayable = null;
            $lastHlsContinuousUntil = null;
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
                $playableState = $this->resolveHlsPlayableState($sessionId, $session);
                $session = $playableState['session'];
                $playable = $playableState['playable'];
                $hlsContinuousUntil = (float) ($session['hls_continuous_until'] ?? 0.0);

                // Send warning event (transient errors like 429)
                if ($warning && $warning !== $lastWarning) {
                    $this->sseEvent('warning', ['message' => $warning]);
                    $lastWarning = $warning;
                }

                // Send update if state changed
                if (
                    $status !== $lastStatus
                    || $ready !== $lastReady
                    || $progress !== $lastProgress
                    || $playable !== $lastPlayable
                    || $hlsContinuousUntil !== $lastHlsContinuousUntil
                ) {
                    $this->sseEvent('update', [
                        'status' => $status,
                        'segments_ready' => $ready,
                        'total_segments' => $total,
                        'playable' => $playable,
                        'hls' => [
                            'playable' => $playable,
                            'dub_start_time' => (float) ($session['hls_dub_start_time'] ?? 0.0),
                            'ready_seconds' => (float) ($session['hls_ready_seconds'] ?? 0.0),
                            'required_seconds' => (float) ($session['hls_required_seconds'] ?? 0.0),
                            'continuous_until' => $hlsContinuousUntil,
                            'last_ready_bg_idx' => $session['hls_last_ready_bg_idx'] ?? null,
                            'complete' => (bool) ($session['hls_complete'] ?? (($session['status'] ?? null) === 'complete')),
                            'master_url' => $this->hlsMasterUrl($sessionId, $playable),
                            'hls_url' => $this->hlsMasterUrl($sessionId, $playable),
                        ],
                        'progress' => $progress,
                        'error' => $error,
                    ]);
                    $lastStatus = $status;
                    $lastReady = $ready;
                    $lastProgress = $progress;
                    $lastPlayable = $playable;
                    $lastHlsContinuousUntil = $hlsContinuousUntil;
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

        $playableState = $this->resolveHlsPlayableState($sessionId, $session);
        $session = $playableState['session'];
        $dubPlayable = $playableState['playable'];
        $dubDefault = $dubPlayable ? 'YES' : 'NO';
        $dubAutoselect = $dubPlayable ? 'YES' : 'NO';

        // The rewritten master changes once the dubbed audio has enough buffered chunks.
        $rewrittenKey = DubSession::rewrittenMasterCacheKey($sessionId, $dubPlayable);
        $cached = Redis::get($rewrittenKey);
        if ($cached) {
            return response($cached, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => $dubPlayable ? 'max-age=300' : 'max-age=3',
            ]);
        }

        // Fetch original master playlist (cached separately for CDN token lifetime)
        $masterCacheKey = DubSession::masterPlaylistKey($sessionId);
        $master = Redis::get($masterCacheKey);
        if (!$master) {
            $response = Http::timeout(10)->get($videoUrl);
            if ($response->failed()) {
                return response('Failed to fetch master playlist', 502);
            }
            $master = $response->body();
            Redis::setex($masterCacheKey, DubSession::TTL, $master);
        }
        $videoBaseUrl = $session['video_base_url'] ?? '';
        $videoQuery = $session['video_query'] ?? '';
        $lang = $session['language'] ?? 'uz';
        $langNames = ['uz' => "O'zbek dublyaj", 'ru' => 'Русский дубляж', 'en' => 'English dub'];
        $dubName = $langNames[$lang] ?? ucfirst($lang) . ' dub';
        $subNames = ['uz' => "O'zbek", 'ru' => 'Русский', 'en' => 'English'];
        $subName = $subNames[$lang] ?? ucfirst($lang);

        if (str_contains($master, '#EXTINF') && !str_contains($master, '#EXT-X-STREAM-INF')) {
            $result = $this->syntheticMasterForMediaPlaylist(
                $videoUrl,
                $groupId = 'audio',
                $subsGroupId = 'subs',
                $dubName,
                $subName,
                $lang,
                $dubPlayable,
            );

            Redis::setex($rewrittenKey, DubSession::TTL, $result);

            return response($result, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => $dubPlayable ? 'max-age=300' : 'max-age=3',
            ]);
        }

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

        // First pass: collect all audio/subtitle groups. Some HLS masters use
        // different AUDIO groups per variant; the dub rendition must exist in
        // every referenced group or ABR level switching can fall back to original.
        $existingAudioGroups = [];
        $streamAudioGroups = [];
        $existingSubsGroups = [];
        $streamSubsGroups = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '#EXT-X-MEDIA')) {
                if (str_contains($trimmed, 'TYPE=AUDIO')) {
                    $group = $this->hlsTagAttribute($trimmed, 'GROUP-ID');
                    if ($group !== null && !in_array($group, $existingAudioGroups, true)) {
                        $existingAudioGroups[] = $group;
                    }
                }
                if (str_contains($trimmed, 'TYPE=SUBTITLES')) {
                    $group = $this->hlsTagAttribute($trimmed, 'GROUP-ID');
                    if ($group !== null && !in_array($group, $existingSubsGroups, true)) {
                        $existingSubsGroups[] = $group;
                    }
                }
            } elseif (str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                $audioGroup = $this->hlsTagAttribute($trimmed, 'AUDIO');
                if ($audioGroup !== null && !in_array($audioGroup, $streamAudioGroups, true)) {
                    $streamAudioGroups[] = $audioGroup;
                }
                $subsGroup = $this->hlsTagAttribute($trimmed, 'SUBTITLES');
                if ($subsGroup !== null && !in_array($subsGroup, $streamSubsGroups, true)) {
                    $streamSubsGroups[] = $subsGroup;
                }
            }
        }

        $audioGroupIds = array_values(array_unique(array_merge(
            $streamAudioGroups,
            $existingAudioGroups,
        )));
        if (empty($audioGroupIds)) {
            $audioGroupIds = ['audio'];
        }

        $subsGroupIds = array_values(array_unique(array_merge(
            $streamSubsGroups,
            $existingSubsGroups,
        )));
        if (empty($subsGroupIds)) {
            $subsGroupIds = ['subs'];
        }

        $groupId = $audioGroupIds[0];
        $subsGroupId = $subsGroupIds[0];
        $output = [];
        $dubInjected = false;
        $fallbackAudioInjected = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Inject dub audio track only after the server verifies a continuous
            // post-intro HLS runway. Before that, the track must not be selectable.
            if ($dubPlayable && !$dubInjected && str_starts_with($trimmed, '#EXT-X-MEDIA') && str_contains($trimmed, 'TYPE=AUDIO')) {
                foreach ($audioGroupIds as $audioGroupId) {
                    $output[] = $this->hlsDubAudioMediaLine($audioGroupId, $dubName, $lang, $dubDefault, $dubAutoselect);
                }
                $dubInjected = true;
            }

            // Inject before STREAM-INF if no existing audio tracks.
            if (!$fallbackAudioInjected && str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                if (empty($existingAudioGroups)) {
                    $originalDefault = $dubPlayable ? 'NO' : 'YES';
                    $originalAutoselect = $dubPlayable ? 'NO' : 'YES';
                    $output[] = "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"Original\",DEFAULT={$originalDefault},AUTOSELECT={$originalAutoselect}";
                }
                if ($dubPlayable && !$dubInjected) {
                    foreach ($audioGroupIds as $audioGroupId) {
                        $output[] = $this->hlsDubAudioMediaLine($audioGroupId, $dubName, $lang, $dubDefault, $dubAutoselect);
                    }
                    $dubInjected = true;
                }
                if (!str_contains(implode("\n", $output), 'dub-subtitles')) {
                    $output[] = "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"{$subsGroupId}\",NAME=\"{$subName}\",LANGUAGE=\"{$lang}\",URI=\"dub-subtitles.m3u8\",DEFAULT=YES,AUTOSELECT=YES,FORCED=NO";
                }
                $fallbackAudioInjected = true;
            }

            // Inject subtitles before STREAM-INF
            if (str_starts_with($trimmed, '#EXT-X-STREAM-INF') && !str_contains(implode("\n", $output), 'dub-subtitles')) {
                $output[] = "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"{$subsGroupId}\",NAME=\"{$subName}\",LANGUAGE=\"{$lang}\",URI=\"dub-subtitles.m3u8\",DEFAULT=YES,AUTOSELECT=YES,FORCED=NO";
            }

            // Once dub is playable, demote existing audio/subtitle tracks so ours takes priority.
            if ($dubPlayable && str_starts_with($trimmed, '#EXT-X-MEDIA') && !str_contains($trimmed, 'dub-audio') && !str_contains($trimmed, 'dub-subtitles')) {
                $line = preg_replace('/DEFAULT=YES/', 'DEFAULT=NO', $line);
                $line = preg_replace('/AUTOSELECT=YES/', 'AUTOSELECT=NO', $line);
            }

            // Ensure STREAM-INF lines reference audio + subtitle groups
            if (str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                if (str_contains($trimmed, 'AUDIO=')) {
                    if (empty($existingAudioGroups)) {
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
                        return 'URI="' . $this->resolveHlsUrl($videoBaseUrl, $uri, $videoQuery) . '"';
                    }
                    return $m[0];
                }, $line);
            }

            // Standalone URIs: convert relative to absolute CDN URLs
            if ($trimmed !== '' && !str_starts_with($trimmed, '#')) {
                if (!str_starts_with($trimmed, 'http')) {
                    $line = $this->resolveHlsUrl($videoBaseUrl, $trimmed, $videoQuery);
                }
            }

            $output[] = $line;
        }

        $result = implode("\n", $output);

        // Cache rewritten master — it won't change for this session
        Redis::setex($rewrittenKey, DubSession::TTL, $result);

        return response($result, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => $dubPlayable ? 'max-age=300' : 'max-age=3',
        ]);
    }

    public function hlsAudioPlaylist(string $sessionId)
    {
        $session = $this->getSession($sessionId);
        if (!$session) {
            return response('Session not found', 404);
        }

        $dubStartTime = max(0.0, (float) ($session['hls_dub_start_time'] ?? 0.0));

        // Build the online-style planned audio timeline. Source URLs cover the
        // intro only; bg-* URLs are reserved for verified dubbed audio.
        $totalBg    = (int) ($session['total_bg_chunks'] ?? 0);
        $aacDir     = $this->aacDir($sessionId, $session);
        $entries    = [];
        $startedDub = false;
        $lastPlannedBgIdx = -1;
        $lastReadyDubBgIdx = -1;
        $sourceReadyBeforeDubStart = true;

        $bgHashData = Redis::hgetall(DubSession::bgChunksKey($sessionId)) ?? [];
        ksort($bgHashData, SORT_NUMERIC);

        if (empty($bgHashData) && $dubStartTime > 0.25) {
            $leadDuration = round($this->frameAlignedDuration(0.0, $dubStartTime), 6);
            $entries[] = ['uri' => 'dub-segment/lead.ts', 'duration' => $leadDuration];
        }

        foreach ($bgHashData as $bgIdx => $bgJson) {
            $bgIdx = (int) $bgIdx;
            if ($startedDub && $bgIdx !== $lastPlannedBgIdx + 1) break; // stop at first planned gap
            if (!$startedDub && $bgIdx !== 0) break;
            $bgChunk = json_decode($bgJson, true);
            $cs  = (float) ($bgChunk['start'] ?? ($bgIdx * self::BG_CHUNK_SECONDS));
            $ce  = (float) ($bgChunk['end']   ?? (($bgIdx + 1) * self::BG_CHUNK_SECONDS));
            $rawFile = $bgChunk['path'] ?? null;
            if ($ce <= $dubStartTime) {
                if (!$rawFile || !file_exists($rawFile) || filesize($rawFile) <= 10) {
                    $sourceReadyBeforeDubStart = false;
                }
                $dur = round($this->frameAlignedDuration($cs, $ce), 6);
                $entries[] = ['uri' => $this->versionedHlsUri("dub-segment/source-bg-{$bgIdx}.ts", $this->hlsFileVersion($rawFile)), 'duration' => $dur];
                $startedDub = true;
                $lastPlannedBgIdx = $bgIdx;
                continue;
            }

            if (!$startedDub) {
                $startedDub = true;
                $lastPlannedBgIdx = $bgIdx - 1;
            }

            if ($cs < $dubStartTime) {
                if (!$rawFile || !file_exists($rawFile) || filesize($rawFile) <= 10) {
                    $sourceReadyBeforeDubStart = false;
                }
                $offsetMs = (int) round(($dubStartTime - $cs) * 1000);
                $sourceDur = round($this->frameAlignedDuration($cs, $dubStartTime), 6);
                $entries[] = ['uri' => $this->versionedHlsUri("dub-segment/source-bg-{$bgIdx}-to-{$offsetMs}.ts", $this->hlsFileVersion($rawFile)), 'duration' => $sourceDur];
                if (!InstantDubHlsReadiness::chunkHasVerifiedDub($sessionId, $session, $bgIdx, $bgChunk, $aacDir)) {
                    break;
                }
                $dur = round($this->frameAlignedDuration($dubStartTime, $ce), 6);
                $dubFile = InstantDubHlsReadiness::dubChunkPath($aacDir, $bgIdx);
                $sliceFile = "{$aacDir}/bg-{$bgIdx}-from-{$offsetMs}.ts";
                $version = $this->hlsFileVersion($sliceFile) ?: $this->hlsFileVersion($dubFile) ?: (string) ($bgChunk['dub_mixed_at'] ?? '');
                $entries[] = ['uri' => $this->versionedHlsUri("dub-segment/bg-{$bgIdx}-from-{$offsetMs}.ts", $version), 'duration' => $dur];
                $lastReadyDubBgIdx = $bgIdx;
            } else {
                if (!InstantDubHlsReadiness::chunkHasVerifiedDub($sessionId, $session, $bgIdx, $bgChunk, $aacDir)) {
                    break;
                }
                $dur = round($this->frameAlignedDuration($cs, $ce), 6);
                $dubFile = InstantDubHlsReadiness::dubChunkPath($aacDir, $bgIdx);
                $version = $this->hlsFileVersion($dubFile) ?: (string) ($bgChunk['dub_mixed_at'] ?? '');
                $entries[] = ['uri' => $this->versionedHlsUri("dub-segment/bg-{$bgIdx}.ts", $version), 'duration' => $dur];
                $lastReadyDubBgIdx = $bgIdx;
            }

            $lastPlannedBgIdx = $bgIdx;
        }

        $timelinePlanned = $sourceReadyBeforeDubStart && $totalBg > 0 && $lastReadyDubBgIdx >= $totalBg - 1;

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

        if ($timelinePlanned) {
            $m3u8 .= "#EXT-X-PLAYLIST-TYPE:VOD\n";
        } else {
            $m3u8 .= "#EXT-X-PLAYLIST-TYPE:EVENT\n";
        }

        $wroteEntry = false;
        foreach ($entries as $entry) {
            if (!empty($wroteEntry)) {
                $m3u8 .= "#EXT-X-DISCONTINUITY\n";
            }
            $m3u8 .= "#EXTINF:{$entry['duration']},\n";
            $m3u8 .= "{$entry['uri']}\n";
            $wroteEntry = true;
        }

        if ($timelinePlanned) {
            $m3u8 .= "#EXT-X-ENDLIST\n";
        }

        $cacheControl = $timelinePlanned ? 'max-age=30' : 'max-age=3';

        return response($m3u8, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => $cacheControl,
        ]);
    }

    public function hlsInitSegment(string $sessionId)
    {
        // MPEG-TS audio segments do not require an init segment.
        return response('', 404);
    }

    private function aacDir(string $sessionId, ?array $session = null): string
    {
        return DubSession::aacDir($sessionId, $session);
    }

    public function hlsGapSegment(string $sessionId, int $index)
    {
        $aacFile = $this->hlsMediaFile($this->aacDir($sessionId), "gap-{$index}");

        if (file_exists($aacFile) && filesize($aacFile) > 10) {
            $session = $this->getSession($sessionId);
            $status = $session['status'] ?? 'processing';
            $cacheControl = in_array($status, ['complete', 'stopped']) ? 'max-age=86400' : 'max-age=10';

            return response()->file($aacFile, [
                'Content-Type' => $this->hlsAudioContentType($aacFile),
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => $cacheControl,
            ]);
        }

        return $this->silentHlsAudioResponse();
    }

    public function hlsLeadSegment(string $sessionId)
    {
        $aacFile = $this->hlsMediaFile($this->aacDir($sessionId), 'lead');

        if (file_exists($aacFile) && filesize($aacFile) > 100) {
            $session = $this->getSession($sessionId);
            $status = $session['status'] ?? 'processing';
            $cacheControl = in_array($status, ['complete', 'stopped']) ? 'max-age=86400' : 'max-age=10';

            return response()->file($aacFile, [
                'Content-Type' => $this->hlsAudioContentType($aacFile),
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => $cacheControl,
            ]);
        }

        // File missing (cache hit with TTS-only storage) — pull background from HLS
        $session = $this->getSession($sessionId);
        $dubStartTime = max(0.0, (float) ($session['hls_dub_start_time'] ?? 0.0));
        if ($dubStartTime > 0.25) {
            $duration = round($this->frameAlignedDuration(0, $dubStartTime), 6);
            if ($this->generateLeadFromHls($sessionId, $duration)) {
                $aacFile = $this->hlsMediaFile($this->aacDir($sessionId), 'lead');
                return response()->file($aacFile, [
                    'Content-Type'  => $this->hlsAudioContentType($aacFile),
                    'Access-Control-Allow-Origin' => '*',
                    'Cache-Control' => 'max-age=86400',
                ]);
            }
            return $this->silentHlsAudioResponse($duration);
        }

        $total = (int) ($session['total_segments'] ?? 0);
        if ($total > 0) {
            $firstChunkJson = Redis::get(DubSession::chunkKey($sessionId, 0));
            if ($firstChunkJson) {
                $firstChunk = json_decode($firstChunkJson, true);
                $firstStart = (float) ($firstChunk['start_time'] ?? 0);
                if ($firstStart > 1.0) {
                    $duration = round($this->frameAlignedDuration(0, $firstStart), 6);
                    if ($this->generateLeadFromHls($sessionId, $duration)) {
                        $aacFile = $this->hlsMediaFile($this->aacDir($sessionId), 'lead');
                        return response()->file($aacFile, [
                            'Content-Type'  => $this->hlsAudioContentType($aacFile),
                            'Access-Control-Allow-Origin' => '*',
                            'Cache-Control' => 'max-age=86400',
                        ]);
                    }
                    return $this->silentHlsAudioResponse($duration);
                }
            }
        }

        return $this->silentHlsAudioResponse();
    }

    public function hlsBgSegment(string $sessionId, int $index)
    {
        $session = $this->getSession($sessionId) ?? [];
        $aacDir = $this->aacDir($sessionId, $session);
        $aacFile = InstantDubHlsReadiness::dubChunkPath($aacDir, $index);
        $bgJson = Redis::hget(DubSession::bgChunksKey($sessionId), (string) $index);
        $bgChunk = $bgJson ? json_decode($bgJson, true) : null;
        $cs = $bgChunk ? (float) ($bgChunk['start'] ?? $index * self::BG_CHUNK_SECONDS) : $index * self::BG_CHUNK_SECONDS;
        $ce = $bgChunk ? (float) ($bgChunk['end']   ?? ($index + 1) * self::BG_CHUNK_SECONDS) : ($index + 1) * self::BG_CHUNK_SECONDS;
        $dur = round($this->frameAlignedDuration($cs, $ce), 6);

        if (InstantDubHlsReadiness::chunkHasVerifiedDub($sessionId, $session, $index, $bgChunk, $aacDir)) {
            $status  = $session['status'] ?? 'processing';
            return response()->file($aacFile, [
                'Content-Type'              => $this->hlsAudioContentType($aacFile),
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control'             => 'no-store',
            ]);
        }

        // Never serve original audio under a dubbed URL after dub start; players cache it.
        return $this->silentHlsAudioResponse($dur);
    }

    public function hlsBgSourceSegment(string $sessionId, int $index)
    {
        $session = $this->getSession($sessionId) ?? [];
        $dubStartTime = max(0.0, (float) ($session['hls_dub_start_time'] ?? 0.0));
        $bgJson = Redis::hget(DubSession::bgChunksKey($sessionId), (string) $index);
        $bgChunk = $bgJson ? json_decode($bgJson, true) : null;
        $cs = $bgChunk ? (float) ($bgChunk['start'] ?? $index * self::BG_CHUNK_SECONDS) : $index * self::BG_CHUNK_SECONDS;
        $ce = $bgChunk ? (float) ($bgChunk['end']   ?? ($index + 1) * self::BG_CHUNK_SECONDS) : ($index + 1) * self::BG_CHUNK_SECONDS;
        $dur = round($this->frameAlignedDuration($cs, $ce), 6);

        if ($cs >= $dubStartTime - 0.05) {
            return $this->silentHlsAudioResponse($dur);
        }

        $rawFile = $bgChunk['path'] ?? null;
        if ($rawFile && file_exists($rawFile) && filesize($rawFile) > 10) {
            return response()->file($rawFile, [
                'Content-Type' => $this->hlsAudioContentType($rawFile),
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'no-store',
            ]);
        }

        return $this->silentHlsAudioResponse($dur);
    }

    public function hlsBgSourceSliceSegment(string $sessionId, int $index, int $offsetMs)
    {
        $session = $this->getSession($sessionId) ?? [];
        $dubStartTime = max(0.0, (float) ($session['hls_dub_start_time'] ?? 0.0));
        $bgJson = Redis::hget(DubSession::bgChunksKey($sessionId), (string) $index);
        $bgChunk = $bgJson ? json_decode($bgJson, true) : null;
        $cs = $bgChunk ? (float) ($bgChunk['start'] ?? $index * self::BG_CHUNK_SECONDS) : $index * self::BG_CHUNK_SECONDS;
        $offset = min(max(0.0, $offsetMs / 1000), max(0.0, $dubStartTime - $cs));
        $duration = round($this->frameAlignedDuration($cs, $cs + $offset), 6);

        if ($duration <= 0.1) {
            return $this->silentHlsAudioResponse(0.1);
        }

        $rawFile = $bgChunk['path'] ?? null;
        if ($rawFile && file_exists($rawFile) && filesize($rawFile) > 10) {
            return $this->uncachedHlsAudioSliceResponse($rawFile, 0.0, $duration);
        }

        return $this->silentHlsAudioResponse($duration);
    }

    public function hlsBgSliceSegment(string $sessionId, int $index, int $offsetMs)
    {
        $session = $this->getSession($sessionId) ?? [];
        $aacDir = $this->aacDir($sessionId, $session);
        $aacFile = InstantDubHlsReadiness::dubChunkPath($aacDir, $index);
        $bgJson = Redis::hget(DubSession::bgChunksKey($sessionId), (string) $index);
        $bgChunk = $bgJson ? json_decode($bgJson, true) : null;
        $cs = $bgChunk ? (float) ($bgChunk['start'] ?? $index * self::BG_CHUNK_SECONDS) : $index * self::BG_CHUNK_SECONDS;
        $ce = $bgChunk ? (float) ($bgChunk['end']   ?? ($index + 1) * self::BG_CHUNK_SECONDS) : ($index + 1) * self::BG_CHUNK_SECONDS;
        $offset = max(0.0, $offsetMs / 1000);
        $duration = round($this->frameAlignedDuration($cs + $offset, $ce), 6);
        $chunkDuration = round($this->frameAlignedDuration($cs, $ce), 6);

        if ($duration <= 0.1) {
            return $this->silentHlsAudioResponse(0.1);
        }

        $hasDub = InstantDubHlsReadiness::chunkHasVerifiedDub($sessionId, $session, $index, $bgChunk, $aacDir);
        if (!$hasDub) {
            return $this->silentHlsAudioResponse($duration);
        }

        $sliceFile = "{$aacDir}/bg-{$index}-from-{$offsetMs}.ts";
        $speechExpected = (int) ($bgChunk['dub_tts_inputs'] ?? $bgChunk['dub_expected_speech'] ?? 0) > 0;
        if ($this->hlsSliceNeedsRefresh($sliceFile, $aacFile, $chunkDuration, $duration, $speechExpected)) {
            $tmpFile = "{$sliceFile}.tmp." . getmypid();
            $result = Process::timeout(30)->run([
                'ffmpeg', '-y',
                '-ss', (string) round($offset, 3),
                '-t', (string) round($duration, 3),
                '-i', $aacFile,
                '-ac', '1', '-ar', '44100',
                '-c:a', 'aac', '-b:a', '96k',
                '-muxdelay', '0', '-muxpreload', '0',
                '-f', 'mpegts', $tmpFile,
            ]);

            if ($result->successful() && file_exists($tmpFile) && filesize($tmpFile) > 10) {
                rename($tmpFile, $sliceFile);
            } else {
                @unlink($tmpFile);
                return $this->silentHlsAudioResponse($duration);
            }
        }

        return response()->file($sliceFile, [
            'Content-Type' => $this->hlsAudioContentType($sliceFile),
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function hlsSliceNeedsRefresh(
        string $sliceFile,
        string $sourceFile,
        ?float $sourceDuration = null,
        ?float $sliceDuration = null,
        bool $speechExpected = false,
    ): bool
    {
        if (!file_exists($sliceFile) || filesize($sliceFile) <= 10) {
            return true;
        }

        if (!file_exists($sourceFile) || filesize($sourceFile) <= 10) {
            return true;
        }

        if ($speechExpected && $sourceDuration !== null && $sliceDuration !== null && $sourceDuration > 0.0) {
            $expectedBytes = filesize($sourceFile) * min(1.0, max(0.0, $sliceDuration / $sourceDuration)) * 0.65;
            if (filesize($sliceFile) < $expectedBytes) {
                return true;
            }
        }

        return filemtime($sliceFile) < filemtime($sourceFile);
    }

    private function hlsFileVersion(?string $file): string
    {
        return $file && file_exists($file) ? (string) filemtime($file) : '';
    }

    private function versionedHlsUri(string $uri, string $version): string
    {
        return $version !== '' ? "{$uri}?v={$version}" : $uri;
    }

    public function hlsTailSegment(string $sessionId)
    {
        $aacFile = $this->hlsMediaFile($this->aacDir($sessionId), 'tail');

        if (file_exists($aacFile) && filesize($aacFile) > 100) {
            return response()->file($aacFile, [
                'Content-Type' => $this->hlsAudioContentType($aacFile),
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'max-age=86400',
            ]);
        }

        return $this->silentHlsAudioResponse();
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
        $chunkJson = Redis::get(DubSession::chunkKey($sessionId, $index));
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
            ? Redis::get(DubSession::chunkKey($sessionId, $total - 1))
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
            $cached = Redis::get(DubSession::vttCacheKey($sessionId));
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
            $chunkKeys[] = DubSession::chunkKey($sessionId, $i);
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
            Redis::setex(DubSession::vttCacheKey($sessionId), DubSession::TTL, $vtt);
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

        // Reject path traversal attempts
        if (str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '//')) {
            return response('Invalid path', 400);
        }

        $baseUrl = $session['video_base_url'] ?? '';
        if (!$baseUrl) {
            return response('No base URL in session', 400);
        }
        $query = $session['video_query'] ?? '';

        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        // Ensure the resolved URL stays on the same host as the session's base URL
        $allowedHost = parse_url($baseUrl, PHP_URL_HOST);
        $targetHost  = parse_url($url, PHP_URL_HOST);
        if (!$allowedHost || $allowedHost !== $targetHost) {
            return response('Proxy target not allowed', 403);
        }

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

    private function hlsMediaFile(string $dir, string $baseName): string
    {
        $tsFile = "{$dir}/{$baseName}.ts";
        if (file_exists($tsFile)) {
            return $tsFile;
        }

        return "{$dir}/{$baseName}.aac";
    }

    private function hlsAudioContentType(string $file): string
    {
        return str_ends_with($file, '.ts') ? 'video/mp2t' : 'audio/aac';
    }

    private function hlsTagAttribute(string $tag, string $name): ?string
    {
        return preg_match('/(?:^|,)' . preg_quote($name, '/') . '="([^"]*)"/', $tag, $m)
            ? $m[1]
            : null;
    }

    private function hlsDubAudioMediaLine(
        string $groupId,
        string $dubName,
        string $lang,
        string $dubDefault,
        string $dubAutoselect,
    ): string {
        return "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"{$dubName}\",LANGUAGE=\"{$lang}\",URI=\"dub-audio.m3u8\",DEFAULT={$dubDefault},AUTOSELECT={$dubAutoselect},CHANNELS=\"1\"";
    }

    /**
     * Download the first $duration seconds of the HLS audio track and save as lead.ts.
     * Uses a file lock to prevent parallel downloads for the same session.
     */
    private function generateLeadFromHls(string $sessionId, float $duration): bool
    {
        $session = $this->getSession($sessionId);
        $videoUrl = $session['video_url'] ?? '';
        if (!$videoUrl || !str_contains($videoUrl, '.m3u8')) return false;

        $aacDir = storage_path("app/instant-dub/{$sessionId}/aac");
        @mkdir($aacDir, 0755, true);
        $leadFile = "{$aacDir}/lead.ts";

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
                '-c:a', 'aac', '-b:a', '64k',
                '-muxdelay', '0', '-muxpreload', '0',
                '-f', 'mpegts', $leadFile,
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
                    return $this->resolveHlsUrl($baseUrl, $m[1], $query);
                }
            }
        } catch (\Throwable) {}
        return null;
    }

    private function silentHlsAudioResponse(float $duration = 0.5): \Illuminate\Http\Response
    {
        $duration = max(0.1, round($duration, 3));
        $destFile = sys_get_temp_dir() . '/silent-hls-' . (int) ($duration * 1000) . 'ms.ts';

        if (!file_exists($destFile) || filesize($destFile) < 10) {
            $buildFile = $destFile . '.tmp.' . getmypid();
            Process::timeout(15)->run([
                'ffmpeg', '-y', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono',
                '-t', (string) $duration,
                '-c:a', 'aac', '-b:a', '32k',
                '-muxdelay', '0', '-muxpreload', '0',
                '-f', 'mpegts', $buildFile,
            ]);
            if (file_exists($buildFile) && filesize($buildFile) > 10) {
                rename($buildFile, $destFile);
            } else {
                @unlink($buildFile);
            }
        }

        if (file_exists($destFile) && filesize($destFile) > 10) {
            $data = file_get_contents($destFile);
            return response($data, 200, [
                'Content-Type' => 'video/mp2t',
                'Content-Length' => strlen($data),
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'no-store',
            ]);
        }

        return response('', 200, [
            'Content-Type' => 'video/mp2t',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Generate a silent AAC ADTS segment of the given duration (for lead/tail gaps).
     * Result is NOT cached to disk — generated fresh per request (durations vary).
     */
    private function silentAacOfDuration(float $duration): \Illuminate\Http\Response
    {
        $duration = max(0.1, round($duration, 3));
        $destFile = sys_get_temp_dir() . '/silent-' . (int) ($duration * 1000) . 'ms.aac';

        if (!file_exists($destFile) || filesize($destFile) < 10) {
            $buildFile = $destFile . '.tmp.' . getmypid();
            Process::timeout(15)->run([
                'ffmpeg', '-y', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono',
                '-t', (string) $duration,
                '-c:a', 'aac', '-b:a', '32k', '-f', 'adts', $buildFile,
            ]);
            if (file_exists($buildFile) && filesize($buildFile) > 10) {
                rename($buildFile, $destFile);
            } else {
                @unlink($buildFile);
            }
        }

        if (file_exists($destFile) && filesize($destFile) > 10) {
            $data = file_get_contents($destFile);
            return response($data, 200, [
                'Content-Type'   => 'audio/aac',
                'Content-Length' => strlen($data),
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control'  => 'max-age=3600',
            ]);
        }

        return $this->silentAacResponse();
    }

    private function uncachedHlsAudioSliceResponse(string $sourceFile, float $offset, float $duration): \Illuminate\Http\Response
    {
        $duration = max(0.1, round($duration, 3));
        $offset = max(0.0, round($offset, 3));
        $tmpFile = sys_get_temp_dir() . '/hls-source-slice-' . Str::random(8) . '.ts';

        $result = Process::timeout(30)->run([
            'ffmpeg', '-y',
            '-ss', (string) $offset,
            '-t', (string) $duration,
            '-i', $sourceFile,
            '-ac', '1', '-ar', '44100',
            '-c:a', 'aac', '-b:a', '96k',
            '-muxdelay', '0', '-muxpreload', '0',
            '-f', 'mpegts', $tmpFile,
        ]);

        if ($result->successful() && file_exists($tmpFile) && filesize($tmpFile) > 10) {
            $data = file_get_contents($tmpFile);
            @unlink($tmpFile);

            return response($data, 200, [
                'Content-Type' => 'video/mp2t',
                'Content-Length' => strlen($data),
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'no-store',
            ]);
        }

        @unlink($tmpFile);
        return $this->silentHlsAudioResponse($duration);
    }

    private function buildSessionFromCache(InstantDub $dub, string $sessionId, string $videoUrl, string $videoBaseUrl, string $videoQuery, string $title, ?string $forceVoice = null): array
    {
        $isComplete = $dub->status === 'complete';

        return [
            'id'             => $sessionId,
            'title'          => $dub->title ?: $title,
            'language'       => $dub->language,
            'translate_from' => $dub->translate_from,
            'video_url'      => $videoUrl,
            'video_base_url' => $videoBaseUrl,
            'video_query'    => $videoQuery,
            'status'         => 'processing',
            'playable'       => false,
            'tts_driver'     => 'edge',
            'force_voice'    => null,
            'disable_prosody' => false,
            'total_segments' => $dub->total_segments,
            'segments_ready' => $isComplete ? $dub->total_segments : 0,
            'cached_dub_id'  => $dub->id,
            'created_at'     => now()->toIso8601String(),
        ];
    }

    private function frameAlignedDuration(float $start, float $end): float
    {
        return AudioFrame::alignedDuration($start, $end);
    }

    private function getSession(string $sessionId): ?array
    {
        return DubSession::get($sessionId);
    }

    private function hlsResponseUrls(string $sessionId, ?bool $dubPlayable = null): array
    {
        $masterUrl = $this->hlsMasterUrl($sessionId, $dubPlayable);

        return [
            'hls_url' => $masterUrl,
            'master_url' => $masterUrl,
            'dub_audio_url' => route('api.instant-dub.dub-audio', $sessionId),
            'subtitles_url' => route('api.instant-dub.dub-subtitles', $sessionId),
        ];
    }

    private function hlsMasterUrl(string $sessionId, ?bool $dubPlayable = null): string
    {
        $url = route('api.instant-dub.master', $sessionId);
        if ($dubPlayable === null) {
            return $url;
        }

        return $url . '?dub=' . ($dubPlayable ? 'ready' : 'waiting');
    }

    private function syntheticMasterForMediaPlaylist(
        string $mediaPlaylistUrl,
        string $groupId,
        string $subsGroupId,
        string $dubName,
        string $subName,
        string $lang,
        bool $dubPlayable,
    ): string {
        $originalDefault = $dubPlayable ? 'NO' : 'YES';
        $originalAutoselect = $dubPlayable ? 'NO' : 'YES';
        $dubDefault = $dubPlayable ? 'YES' : 'NO';
        $dubAutoselect = $dubPlayable ? 'YES' : 'NO';

        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-INDEPENDENT-SEGMENTS',
            "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"Original\",DEFAULT={$originalDefault},AUTOSELECT={$originalAutoselect}",
            "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"{$subsGroupId}\",NAME=\"{$subName}\",LANGUAGE=\"{$lang}\",URI=\"dub-subtitles.m3u8\",DEFAULT=YES,AUTOSELECT=YES,FORCED=NO",
            "#EXT-X-STREAM-INF:BANDWIDTH=3000000,AUDIO=\"{$groupId}\",SUBTITLES=\"{$subsGroupId}\"",
            $mediaPlaylistUrl,
            '',
        ];

        if ($dubPlayable) {
            array_splice($lines, 4, 0, [
                "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"{$dubName}\",LANGUAGE=\"{$lang}\",URI=\"dub-audio.m3u8\",DEFAULT={$dubDefault},AUTOSELECT={$dubAutoselect},CHANNELS=\"1\"",
            ]);
        }

        return implode("\n", $lines);
    }

    private function resolveHlsUrl(string $baseUrl, string $uri, string $parentQuery = ''): string
    {
        if (str_starts_with($uri, 'http')) {
            return $uri;
        }

        if (str_starts_with($uri, '//')) {
            return 'https:' . $uri;
        }

        if (str_starts_with($uri, '/')) {
            $parts = parse_url($baseUrl);
            $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            if (!empty($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
            $url = $origin . $uri;
        } else {
            $url = rtrim($baseUrl, '/') . '/' . $uri;
        }

        if ($parentQuery && !str_contains($url, '?')) {
            $url .= '?' . $parentQuery;
        }

        return $url;
    }

    private function resolveHlsPlayableState(string $sessionId, array $session): array
    {
        $totalBg = (int) ($session['total_bg_chunks'] ?? 0);
        if ($totalBg <= 0) {
            if (str_contains((string) ($session['video_url'] ?? ''), '.m3u8')) {
                $patch = [
                    'playable' => false,
                    'hls_switch_verified' => false,
                    'hls_ready_seconds' => 0.0,
                    'hls_required_seconds' => 0.0,
                    'hls_continuous_until' => 0.0,
                    'hls_last_ready_bg_idx' => null,
                    'hls_complete' => false,
                ];

                if (!empty($session['playable']) || !empty($session['hls_switch_verified'])) {
                    DubSession::patch($sessionId, $patch);
                    $session = array_merge($session, $patch);
                }

                return [
                    'playable' => false,
                    'session' => $session,
                    'window' => null,
                ];
            }

            return [
                'playable' => !empty($session['playable']),
                'session' => $session,
                'window' => null,
            ];
        }

        $window = InstantDubHlsReadiness::readyWindow($sessionId, $session, $this->aacDir($sessionId, $session));
        $formatVerified = !empty($session['hls_switch_verified'])
            && (($session['hls_verified_format'] ?? null) === 'ts');
        $playable = !empty($window['ready']);
        $patch = [
            'playable' => $playable,
            'hls_switch_verified' => $formatVerified || $playable,
            'hls_verified_format' => ($formatVerified || $playable) ? 'ts' : ($session['hls_verified_format'] ?? null),
            'hls_ready_seconds' => round((float) $window['ready_seconds'], 3),
            'hls_required_seconds' => round((float) $window['required_seconds'], 3),
            'hls_continuous_until' => round((float) $window['continuous_until'], 3),
            'hls_last_ready_bg_idx' => $window['last_ready_bg_idx'],
            'hls_complete' => !empty($window['complete']),
        ];

        $changed = (bool) ($session['playable'] ?? false) !== $playable
            || (bool) ($session['hls_switch_verified'] ?? false) !== $patch['hls_switch_verified']
            || (string) ($session['hls_verified_format'] ?? '') !== (string) ($patch['hls_verified_format'] ?? '')
            || (float) ($session['hls_ready_seconds'] ?? -1) !== $patch['hls_ready_seconds']
            || (float) ($session['hls_continuous_until'] ?? -1) !== $patch['hls_continuous_until']
            || (int) ($session['hls_last_ready_bg_idx'] ?? -999) !== (int) ($patch['hls_last_ready_bg_idx'] ?? -999)
            || (bool) ($session['hls_complete'] ?? false) !== $patch['hls_complete'];

        if ($changed) {
            DubSession::patch($sessionId, $patch);
            $session = array_merge($session, $patch);
        }

        return [
            'playable' => $playable,
            'session' => $session,
            'window' => $window,
        ];
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (glob("{$dir}/*") ?: [] as $entry) {
            is_dir($entry) ? $this->deleteDirectory($entry) : @unlink($entry);
        }
        @rmdir($dir);
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

        $nextJson = Redis::get(DubSession::chunkKey($sessionId, $index + 1));
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

            // Resolve audio: prefer in-memory base64, fall back to reading from disk
            $audioBase64 = $chunk['audio_base64'] ?? null;
            if (!$audioBase64 && !empty($chunk['audio_path']) && file_exists($chunk['audio_path'])) {
                $audioBase64 = base64_encode(file_get_contents($chunk['audio_path']));
            }

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
