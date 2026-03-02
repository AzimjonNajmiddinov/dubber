<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInstantDubSegmentJob;
use App\Services\SrtParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class InstantDubController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'srt' => 'required|string|min:10',
            'language' => 'required|string|max:10',
            'video_url' => 'nullable|string',
            'translate_from' => 'nullable|string|max:10',
        ]);

        $segments = SrtParser::parse($request->input('srt'));

        if (empty($segments)) {
            return response()->json(['error' => 'No valid SRT segments found'], 422);
        }

        // Filter out sound-effect-only segments (e.g. [glass shatters], ♪ ♪)
        $segments = array_values(array_filter($segments, function ($seg) {
            $text = trim($seg['text']);
            // Remove all bracket annotations
            $clean = preg_replace('/\[[^\]]*\]/', '', $text);
            // Remove dashes, music symbols, whitespace
            $clean = preg_replace('/[-♪\s]+/', '', $clean);
            return $clean !== '';
        }));

        if (empty($segments)) {
            return response()->json(['error' => 'No speakable segments found (all are sound effects)'], 422);
        }

        $sessionId = Str::uuid()->toString();
        $language = $request->input('language', 'uz');
        $translateFrom = $request->input('translate_from');

        if ($translateFrom && $translateFrom !== $language) {
            $segments = $this->translateSegments($segments, $translateFrom, $language);
        }

        // Store session in Redis (14h TTL)
        $session = [
            'id' => $sessionId,
            'language' => $language,
            'video_url' => $request->input('video_url'),
            'status' => 'processing',
            'total_segments' => count($segments),
            'segments_ready' => 0,
            'created_at' => now()->toIso8601String(),
        ];

        Redis::setex("instant-dub:{$sessionId}", 50400, json_encode($session));

        // Dispatch all segments in parallel (strip any remaining bracket annotations)
        foreach ($segments as $i => $seg) {
            $text = preg_replace('/\[[^\]]*\]\s*/', '', $seg['text']);
            $text = preg_replace('/^-\s+/', '', trim($text));
            $text = trim($text);
            if ($text === '') continue;

            ProcessInstantDubSegmentJob::dispatch(
                $sessionId,
                $i,
                $text,
                $seg['start'],
                $seg['end'],
                $language,
            )->onQueue('segment-generation');
        }

        Log::info('Instant dub session started', [
            'session_id' => $sessionId,
            'segments' => count($segments),
            'language' => $language,
        ]);

        return response()->json([
            'session_id' => $sessionId,
            'total_segments' => count($segments),
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
            if (!$chunkJson) {
                break;
            }
            $chunks[] = json_decode($chunkJson, true);
        }

        return response()->json([
            'status' => $session['status'] ?? 'processing',
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

        Log::info('Instant dub session stopped', ['session_id' => $sessionId]);

        return response()->json(['status' => 'stopped']);
    }

    /**
     * Fetch subtitles from an HLS master playlist URL.
     */
    public function fetchSubs(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|string',
        ]);

        $url = $request->input('url');

        try {
            // 1. Fetch master playlist
            $masterResp = Http::timeout(10)->get($url);
            if ($masterResp->failed()) {
                return response()->json(['error' => 'Failed to fetch master playlist'], 400);
            }

            $master = $masterResp->body();
            $baseUrl = preg_replace('#/[^/]+$#', '/', $url);

            // 2. Find subtitle playlist URI
            if (!preg_match('/TYPE=SUBTITLES.*?URI="([^"]+)"/', $master, $m)) {
                return response()->json(['error' => 'No subtitle track found in HLS playlist'], 404);
            }

            $subsPlaylistUrl = $this->resolveUrl($baseUrl, $m[1], $url);

            // 3. Fetch subtitle playlist
            $subsResp = Http::timeout(10)->get($subsPlaylistUrl);
            if ($subsResp->failed()) {
                return response()->json(['error' => 'Failed to fetch subtitle playlist'], 400);
            }

            $subsPlaylist = $subsResp->body();
            $subsBaseUrl = preg_replace('#/[^/]+$#', '/', $subsPlaylistUrl);

            // 4. Extract VTT segment filenames
            preg_match_all('/^(seg-\S+\.vtt)$/m', $subsPlaylist, $vttFiles);
            $vttFiles = $vttFiles[1] ?? [];

            if (empty($vttFiles)) {
                return response()->json(['error' => 'No VTT segments found'], 404);
            }

            // 5. Fetch all VTT segments and merge
            $allVtt = '';
            foreach ($vttFiles as $vttFile) {
                $vttUrl = $this->resolveUrl($subsBaseUrl, $vttFile, $subsPlaylistUrl);
                $vttResp = Http::timeout(10)->get($vttUrl);
                if ($vttResp->successful()) {
                    $allVtt .= "\n" . $vttResp->body();
                }
            }

            // 6. Parse VTT → deduplicated SRT
            $srt = $this->vttToSrt($allVtt);

            // Detect subtitle language from master playlist
            $subLang = 'en';
            if (preg_match('/TYPE=SUBTITLES.*?LANGUAGE="([^"]+)"/', $master, $langMatch)) {
                $subLang = $langMatch[1];
            }

            return response()->json([
                'srt' => $srt,
                'subtitle_language' => $subLang,
                'segments_count' => substr_count($srt, ' --> '),
            ]);

        } catch (\Throwable $e) {
            Log::error('HLS subtitle fetch failed', ['url' => $url, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to extract subtitles: ' . $e->getMessage()], 500);
        }
    }

    private function resolveUrl(string $baseUrl, string $relative, string $originalUrl): string
    {
        if (str_starts_with($relative, 'http')) {
            return $relative;
        }

        // Carry query params (token, ip) from original URL
        $query = parse_url($originalUrl, PHP_URL_QUERY);
        $resolved = rtrim($baseUrl, '/') . '/' . $relative;

        return $query ? "{$resolved}?{$query}" : $resolved;
    }

    private function vttToSrt(string $allVtt): string
    {
        // Parse all cues with regex
        preg_match_all(
            '/(\d+)\n(\d{2}:\d{2}:\d{2}\.\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}\.\d{3})\n((?:(?!\n\n|\nWEBVTT).)+)/s',
            $allVtt,
            $matches,
            PREG_SET_ORDER
        );

        // Deduplicate by (index, start, end)
        $seen = [];
        $unique = [];

        foreach ($matches as $m) {
            $key = "{$m[1]}|{$m[2]}|{$m[3]}";
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $text = trim($m[4]);
            if ($text === '') continue;

            // Skip pure sound effects / music
            if (preg_match('/^\[.*\]$/', $text) || preg_match('/^♪/', $text)) continue;

            $unique[] = [
                'start' => str_replace('.', ',', $m[2]),
                'end' => str_replace('.', ',', $m[3]),
                'text' => $text,
            ];
        }

        // Build SRT
        $srt = '';
        foreach ($unique as $i => $cue) {
            $num = $i + 1;
            $srt .= "{$num}\n{$cue['start']} --> {$cue['end']}\n{$cue['text']}\n\n";
        }

        return trim($srt);
    }

    private function translateSegments(array $segments, string $from, string $to): array
    {
        $translated = 0;
        $failed = 0;

        foreach ($segments as $i => &$seg) {
            $text = $seg['text'];

            // Skip pure sound effects
            if (preg_match('/^\[.*\]$/', trim($text))) {
                continue;
            }

            // Strip bracket annotations like [laughs], [woman], etc.
            $cleanText = preg_replace('/\[[^\]]*\]\s*/', '', $text);
            $cleanText = preg_replace('/^-\s*$/', '', $cleanText);
            $cleanText = trim($cleanText);
            if ($cleanText === '') {
                continue;
            }

            try {
                $result = $this->googleTranslate($cleanText, $from, $to);
                if ($result && $result !== $cleanText) {
                    $seg['text'] = $result;
                    $translated++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Segment translation failed', ['index' => $i, 'error' => $e->getMessage()]);
            }
        }
        unset($seg);

        Log::info('Translation complete', ['translated' => $translated, 'failed' => $failed, 'total' => count($segments)]);

        return $segments;
    }

    private function googleTranslate(string $text, string $from, string $to): ?string
    {
        $url = 'https://translate.googleapis.com/translate_a/single?' . http_build_query([
            'client' => 'gtx',
            'sl' => $from,
            'tl' => $to,
            'dt' => 't',
            'q' => $text,
        ]);

        $response = Http::timeout(10)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get($url);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();

        if (!is_array($data) || !isset($data[0])) {
            return null;
        }

        // Response format: [[["translated","original",null,null,N]], ...]
        $result = '';
        foreach ($data[0] as $part) {
            if (is_array($part) && isset($part[0])) {
                $result .= $part[0];
            }
        }

        return trim($result) ?: null;
    }
}
