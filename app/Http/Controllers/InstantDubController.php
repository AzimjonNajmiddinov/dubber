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
            'srt' => 'nullable|string',
            'language' => 'required|string|max:10',
            'video_url' => 'nullable|string',
            'translate_from' => 'nullable|string|max:10',
        ]);

        $srt = $request->input('srt', '');
        $videoUrl = $request->input('video_url', '');

        // If no SRT provided, try to fetch from HLS URL
        if (trim($srt) === '' && $videoUrl && str_contains($videoUrl, '.m3u8')) {
            $fetchResult = $this->fetchSubsFromHls($videoUrl);
            if ($fetchResult) {
                $srt = $fetchResult['srt'];
                // Auto-detect subtitle source language
                if (!$request->input('translate_from') && !empty($fetchResult['language'])) {
                    $request->merge(['translate_from' => $fetchResult['language']]);
                }
            }
        }

        if (trim($srt) === '') {
            return response()->json(['error' => 'No subtitles provided and could not fetch from URL'], 422);
        }

        $segments = SrtParser::parse($srt);

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

    public function fetchSubs(Request $request): JsonResponse
    {
        $request->validate(['url' => 'required|string']);

        $result = $this->fetchSubsFromHls($request->input('url'));

        if (!$result) {
            return response()->json(['error' => 'No subtitles found in HLS playlist'], 404);
        }

        return response()->json([
            'srt' => $result['srt'],
            'subtitle_language' => $result['language'],
            'segments_count' => substr_count($result['srt'], ' --> '),
        ]);
    }

    private function fetchSubsFromHls(string $url): ?array
    {
        try {
            $masterResp = Http::timeout(10)->get($url);
            if ($masterResp->failed()) return null;

            $master = $masterResp->body();
            $baseUrl = preg_replace('#/[^/]+$#', '/', $url);

            if (!preg_match('/TYPE=SUBTITLES.*?URI="([^"]+)"/', $master, $m)) return null;

            $subsPlaylistUrl = $this->resolveUrl($baseUrl, $m[1], $url);
            $subsResp = Http::timeout(10)->get($subsPlaylistUrl);
            if ($subsResp->failed()) return null;

            $subsPlaylist = $subsResp->body();
            $subsBaseUrl = preg_replace('#/[^/]+$#', '/', $subsPlaylistUrl);

            preg_match_all('/^(seg-\S+\.vtt)$/m', $subsPlaylist, $vttFiles);
            $vttFiles = $vttFiles[1] ?? [];
            if (empty($vttFiles)) return null;

            $allVtt = '';
            foreach ($vttFiles as $vttFile) {
                $vttUrl = $this->resolveUrl($subsBaseUrl, $vttFile, $subsPlaylistUrl);
                $vttResp = Http::timeout(10)->get($vttUrl);
                if ($vttResp->successful()) {
                    $allVtt .= "\n" . $vttResp->body();
                }
            }

            $srt = $this->vttToSrt($allVtt);
            $subLang = 'en';
            if (preg_match('/TYPE=SUBTITLES.*?LANGUAGE="([^"]+)"/', $master, $langMatch)) {
                $subLang = $langMatch[1];
            }

            return ['srt' => $srt, 'language' => $subLang];
        } catch (\Throwable $e) {
            Log::error('HLS subtitle fetch failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
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
        $apiKey = config('services.openai.key');
        if (!$apiKey) {
            Log::warning('No OpenAI key, skipping translation');
            return $segments;
        }

        $langNames = [
            'uz' => 'Uzbek', 'ru' => 'Russian', 'en' => 'English', 'tr' => 'Turkish',
            'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'ar' => 'Arabic',
            'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean',
        ];
        $toLang = $langNames[$to] ?? $to;

        // Clean all segments first
        foreach ($segments as $i => &$seg) {
            $clean = preg_replace('/\[[^\]]*\]\s*/', '', $seg['text']);
            $clean = preg_replace('/^-\s*/', '', $clean);
            $clean = preg_replace('/\s+-\s+/', ' ', $clean);
            $seg['text'] = trim($clean);
        }
        unset($seg);
        $segments = array_values(array_filter($segments, fn($s) => trim($s['text']) !== ''));

        // Batch translate via GPT (groups of 25)
        foreach (array_chunk($segments, 25, true) as $batch) {
            $lines = [];
            foreach ($batch as $i => $seg) {
                $lines[] = ($i + 1) . '. ' . $seg['text'];
            }

            try {
                $response = Http::withToken($apiKey)
                    ->timeout(45)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'temperature' => 0.3,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => "You are a professional film/series subtitle translator. Translate every line to natural, fluent {$toLang}. This is dialogue from a movie — preserve the tone, emotion and full meaning of each phrase. Do not skip or merge lines. Do not add anything extra. Keep the exact same numbering. One translated line per number.",
                            ],
                            ['role' => 'user', 'content' => implode("\n", $lines)],
                        ],
                    ]);

                if ($response->successful()) {
                    $translated = trim($response->json('choices.0.message.content') ?? '');
                    foreach (preg_split('/\n+/', $translated) as $line) {
                        if (preg_match('/^(\d+)\.\s*(.+)/', $line, $lm)) {
                            $idx = (int) $lm[1] - 1;
                            if (isset($segments[$idx])) {
                                $segments[$idx]['text'] = trim($lm[2]);
                            }
                        }
                    }
                } else {
                    Log::error('GPT translation failed', ['status' => $response->status()]);
                }
            } catch (\Throwable $e) {
                Log::warning('Batch translation failed', ['error' => $e->getMessage()]);
            }
        }

        Log::info('Translation complete', ['total' => count($segments)]);
        return $segments;
    }
}
