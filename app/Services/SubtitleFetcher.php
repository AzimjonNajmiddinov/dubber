<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Fetches subtitles for a video from whichever source is available:
 * HLS subtitle tracks (multi-language, with dual-language timing/text merge)
 * or YouTube auto-captions via yt-dlp. Returns SRT text ready for SrtParser.
 *
 * Extracted from PrepareInstantDubJob; $sessionId is only used as log context.
 */
class SubtitleFetcher
{
    public function __construct(private string $sessionId = '') {}

    public function fetchSubsFromHls(string $url): ?array
    {
        try {
            $masterResp = Http::timeout(10)->get($url);
            if ($masterResp->failed()) return null;

            $master = $masterResp->body();
            $baseUrl = preg_replace('#/[^/]+$#', '/', $url);
            $query = parse_url($url, PHP_URL_QUERY);
            $resolve = function ($base, $rel) use ($query) {
                if (str_starts_with($rel, 'http')) return $rel;
                if (str_starts_with($rel, '//')) return 'https:' . $rel;
                if (str_starts_with($rel, '/')) {
                    $parts = parse_url($base);
                    $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
                    if (!empty($parts['port'])) {
                        $origin .= ':' . $parts['port'];
                    }
                    $r = $origin . $rel;
                } else {
                    $r = rtrim($base, '/') . '/' . $rel;
                }
                return $query ? $r . (str_contains($r, '?') ? '&' : '?') . $query : $r;
            };

            // Parse all subtitle tracks
            preg_match_all('/^#EXT-X-MEDIA:.*TYPE=SUBTITLES.*$/m', $master, $subLines);
            $tracks = [];
            foreach ($subLines[0] ?? [] as $line) {
                $lang = preg_match('/LANGUAGE="([^"]*)"/', $line, $lm) ? $lm[1] : 'unknown';
                $name = preg_match('/NAME="([^"]*)"/', $line, $nm) ? $nm[1] : '';
                $uri = preg_match('/URI="([^"]+)"/', $line, $um) ? $um[1] : null;
                if ($uri) $tracks[] = ['lang' => $lang, 'name' => $name, 'uri' => $uri];
            }

            if (empty($tracks)) {
                if (!preg_match('/TYPE=SUBTITLES.*?URI="([^"]+)"/', $master, $m)) return null;
                $tracks = [['lang' => 'unknown', 'name' => '', 'uri' => $m[1]]];
            }

            // Timeslots from English (original timing), text from Russian (better translation)
            $langPriority = ['ru', 'uz', 'en', 'tr'];
            $timingPriority = ['en', 'ru', 'uz', 'tr'];
            $langPatterns = [
                'ru' => ['ru', 'rus', 'russian'],
                'uz' => ['uz', 'uzb', 'uzbek'],
                'en' => ['en', 'eng', 'english'],
                'tr' => ['tr', 'tur', 'turkish'],
            ];

            // Classify each track by language code
            foreach ($tracks as &$track) {
                $track['langCode'] = 'unknown';
                $rawLang = strtolower(($track['lang'] ?? '') . ' ' . ($track['name'] ?? ''));
                foreach ($langPatterns as $code => $patterns) {
                    foreach ($patterns as $p) {
                        if (str_contains($rawLang, $p)) {
                            $track['langCode'] = $code;
                            break 2;
                        }
                    }
                }
            }
            unset($track);

            // Fetch all available subtitle languages and keep the largest usable
            // candidate for each. A short/broken high-priority language track must
            // not beat a complete lower-priority track, otherwise the rest of the
            // movie is treated as "no speech expected".
            $byLang = [];
            $availableLangs = array_values(array_unique(array_map(
                fn($track) => $track['langCode'] ?? 'unknown',
                $tracks,
            )));
            $orderedLangs = array_values(array_unique(array_merge(
                $langPriority,
                $timingPriority,
                $availableLangs,
            )));

            foreach ($orderedLangs as $lang) {
                $candidates = array_values(array_filter(
                    $tracks,
                    fn($track) => ($track['langCode'] ?? 'unknown') === $lang
                ));
                if (empty($candidates)) {
                    continue;
                }

                $result = $this->fetchBestSubtitleTrack($candidates, $baseUrl, $resolve, $lang);
                if ($result['cues'] > 0 && $result['srt'] !== '') {
                    $byLang[$lang] = $result;
                }
            }

            // Fallback: if preferred language tracks were broken, try unknown tracks too.
            if (empty($byLang)) {
                $unknownCandidates = array_values(array_filter(
                    $tracks,
                    fn($track) => ($track['langCode'] ?? 'unknown') === 'unknown'
                ));

                $result = $this->fetchBestSubtitleTrack($unknownCandidates, $baseUrl, $resolve, 'unknown');
                if ($result['cues'] > 0 && $result['srt'] !== '') {
                    $byLang['unknown'] = $result;
                }
            }

            if (empty($byLang)) return null;

            $cueSummary = [];
            foreach ($byLang as $lang => $r) {
                $cueSummary[] = "{$lang}:{$r['cues']}";
            }

            [$textLang, $textResult] = $this->selectSubtitleResult($byLang, $langPriority);
            [$timingLang, $timingResult] = $this->selectSubtitleResult($byLang, $timingPriority);

            // If we have both EN timing and RU text, merge: EN timestamps + RU text
            if ($timingResult && $textResult && $timingLang !== $textLang) {
                $timingSegments = \App\Services\SrtParser::parse($timingResult['srt']);
                $textSegments = \App\Services\SrtParser::parse($textResult['srt']);

                if (count($timingSegments) > 0 && count($textSegments) > 0) {
                    $ratio = min(count($timingSegments), count($textSegments)) / max(count($timingSegments), count($textSegments));
                    if ($ratio >= 0.75) {
                        $merged = $this->mergeSubtitleSegments($timingSegments, $textSegments);

                        Log::info("[DUB] Subtitle tracks merged: timing={$timingLang} text={$textLang} (" . count($timingSegments) . " cues, ratio=" . round($ratio, 2) . ") from [" . implode(', ', $cueSummary) . "]", [
                            'session' => $this->sessionId,
                        ]);

                        return ['srt' => $merged, 'language' => $textLang];
                    }

                    Log::warning("[DUB] Subtitle merge skipped due cue mismatch: timing={$timingLang}:" . count($timingSegments) . " text={$textLang}:" . count($textSegments), [
                        'session' => $this->sessionId,
                    ]);
                }
            }

            Log::info("[DUB] Subtitle track selected: {$textLang} ({$textResult['cues']} cues) from [" . implode(', ', $cueSummary) . "]", [
                'session' => $this->sessionId,
            ]);

            return ['srt' => $textResult['srt'], 'language' => $textLang];
        } catch (\Throwable $e) {
            Log::error("[DUB] HLS sub fetch failed", ['session' => $this->sessionId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function selectSubtitleResult(array $byLang, array $priority): array
    {
        $largestCueCount = 0;
        foreach ($byLang as $result) {
            $largestCueCount = max($largestCueCount, (int) ($result['cues'] ?? 0));
        }

        $minCompleteCueCount = $largestCueCount >= 40
            ? (int) floor($largestCueCount * 0.8)
            : 1;

        foreach ($priority as $lang) {
            if (isset($byLang[$lang]) && (int) ($byLang[$lang]['cues'] ?? 0) >= $minCompleteCueCount) {
                return [$lang, $byLang[$lang]];
            }
        }

        $bestLang = null;
        $best = null;
        foreach ($byLang as $lang => $result) {
            $isBetter = !$best
                || (int) ($result['cues'] ?? 0) > (int) ($best['cues'] ?? 0)
                || (
                    (int) ($result['cues'] ?? 0) === (int) ($best['cues'] ?? 0)
                    && (int) ($result['bytes'] ?? 0) > (int) ($best['bytes'] ?? 0)
                );

            if ($isBetter) {
                $bestLang = $lang;
                $best = $result;
            }
        }

        return [$bestLang, $best];
    }

    private function mergeSubtitleSegments(array $timingSegments, array $textSegments): string
    {
        $merged = '';
        $count = count($timingSegments);

        for ($i = 0; $i < $count; $i++) {
            $ts = $timingSegments[$i];
            $txt = trim((string) ($textSegments[$i]['text'] ?? $ts['text'] ?? ''));
            if ($txt === '') {
                $txt = trim((string) ($ts['text'] ?? ''));
            }

            $merged .= ($i + 1) . "\n";
            $merged .= $this->formatSrtSeconds((float) ($ts['start'] ?? 0.0))
                . ' --> '
                . $this->formatSrtSeconds((float) ($ts['end'] ?? 0.0))
                . "\n{$txt}\n\n";
        }

        return $merged;
    }

    private function fetchSubtitleTrack(array $track, string $baseUrl, callable $resolve): array
    {
        $subsUrl = $resolve($baseUrl, $track['uri']);
        $subsResp = Http::timeout(10)->get($subsUrl);
        if ($subsResp->failed()) return ['srt' => '', 'cues' => 0, 'bytes' => 0, 'uri' => $track['uri'] ?? ''];

        $body = $subsResp->body();
        $subsBase = preg_replace('#/[^/]+$#', '/', $subsUrl);
        $vttFiles = $this->subtitlePlaylistUris($body);
        if (empty($vttFiles)) {
            [$srt, $cues] = $this->vttToSrt($body);
            return ['srt' => $srt, 'cues' => $cues, 'bytes' => strlen($body), 'uri' => $track['uri'] ?? ''];
        }

        Log::debug("[DUB] VTT playlist: " . count($vttFiles) . " files, first URL: " . $resolve($subsBase, $vttFiles[0]), [
            'session' => $this->sessionId,
        ]);

        // Download VTT segments in batches of 30 to avoid overwhelming CDN
        $allVtt = '';
        $failed = 0;
        $batches = array_chunk($vttFiles, 30);

        foreach ($batches as $batch) {
            $pool = Http::pool(function ($pool) use ($batch, $subsBase, $resolve) {
                foreach ($batch as $i => $vttFile) {
                    $pool->as((string) $i)->timeout(15)->get($resolve($subsBase, $vttFile));
                }
            });

            foreach ($pool as $resp) {
                if ($resp instanceof \Illuminate\Http\Client\Response && $resp->successful()) {
                    $allVtt .= "\n" . $resp->body();
                } else {
                    $failed++;
                }
            }
        }

        if ($failed > 0) {
            Log::debug("[DUB] VTT download: {$failed}/" . count($vttFiles) . " segments failed", [
                'session' => $this->sessionId,
            ]);
        }

        Log::debug("[DUB] VTT content sample (" . strlen($allVtt) . " bytes): " . substr($allVtt, 0, 500), [
            'session' => $this->sessionId,
            'vtt_files' => count($vttFiles),
        ]);

        [$srt, $num] = $this->vttToSrt($allVtt);

        return ['srt' => $srt, 'cues' => $num, 'bytes' => strlen($allVtt), 'uri' => $track['uri'] ?? ''];
    }

    private function subtitlePlaylistUris(string $body): array
    {
        if (str_contains($body, '-->')) {
            return [];
        }

        $uris = [];
        $expectUri = false;
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#EXTINF')) {
                $expectUri = true;
                continue;
            }

            if (str_starts_with($line, '#')) {
                continue;
            }

            if ($expectUri || preg_match('/\.(?:vtt|webvtt)(?:\?|$)/i', $line)) {
                $uris[] = $line;
            }
            $expectUri = false;
        }

        return array_values(array_unique($uris));
    }

    private function vttToSrt(string $vtt): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $vtt);
        $seen = [];
        $srt = '';
        $num = 0;
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '' || str_starts_with($line, 'WEBVTT')) {
                continue;
            }

            if (preg_match('/^(NOTE|STYLE|REGION)\b/i', $line)) {
                while ($i + 1 < $count && trim((string) $lines[$i + 1]) !== '') {
                    $i++;
                }
                continue;
            }

            if (!str_contains($line, '-->') && $i + 1 < $count && str_contains((string) $lines[$i + 1], '-->')) {
                $i++;
                $line = trim((string) $lines[$i]);
            }

            if (!preg_match('/^((?:\d{2}:)?\d{2}:\d{2}[\.,]\d{1,3})\s*-->\s*((?:\d{2}:)?\d{2}:\d{2}[\.,]\d{1,3})/', $line, $m)) {
                continue;
            }

            $start = $this->normalizeVttTimestampForSrt($m[1]);
            $end = $this->normalizeVttTimestampForSrt($m[2]);
            $textLines = [];

            while ($i + 1 < $count) {
                $i++;
                $text = trim((string) $lines[$i]);
                if ($text === '') {
                    break;
                }
                if (str_starts_with($text, 'WEBVTT')) {
                    continue;
                }
                $textLines[] = $text;
            }

            $text = $this->cleanSubtitleCueText(implode(' ', $textLines));
            if ($text === '' || preg_match('/^\[.*]$/u', $text) || preg_match('/^♪/u', $text)) {
                continue;
            }

            $key = "{$start}|{$end}|{$text}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $num++;
            $srt .= "{$num}\n{$start} --> {$end}\n{$text}\n\n";
        }

        return [$srt, $num];
    }

    private function cleanSubtitleCueText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<[^>]+>/', '', $text);
        $text = preg_replace('/\{\\\\[^}]*\}/', '', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);

        return trim((string) $text);
    }

    private function normalizeVttTimestampForSrt(string $timestamp): string
    {
        $timestamp = str_replace(',', '.', trim($timestamp));
        if (substr_count($timestamp, ':') === 1) {
            $timestamp = '00:' . $timestamp;
        }

        [$h, $m, $sMs] = explode(':', $timestamp);
        [$s, $ms] = array_pad(explode('.', $sMs, 2), 2, '0');
        $ms = substr(str_pad($ms, 3, '0'), 0, 3);

        return sprintf('%02d:%02d:%02d,%03d', (int) $h, (int) $m, (int) $s, (int) $ms);
    }

    private function formatSrtSeconds(float $seconds): string
    {
        $milliseconds = (int) round(max(0.0, $seconds) * 1000);
        $h = intdiv($milliseconds, 3600000);
        $milliseconds %= 3600000;
        $m = intdiv($milliseconds, 60000);
        $milliseconds %= 60000;
        $s = intdiv($milliseconds, 1000);
        $ms = $milliseconds % 1000;

        return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $ms);
    }

    private function fetchBestSubtitleTrack(array $candidates, string $baseUrl, callable $resolve, string $lang): array
    {
        $best = ['srt' => '', 'cues' => 0, 'bytes' => 0, 'uri' => ''];
        foreach ($candidates as $track) {
            $result = $this->fetchSubtitleTrack($track, $baseUrl, $resolve);
            if ($result['cues'] <= 0 || $result['srt'] === '') {
                continue;
            }

            $isBetter = $result['cues'] > $best['cues']
                || ($result['cues'] === $best['cues'] && ($result['bytes'] ?? 0) > ($best['bytes'] ?? 0));

            if ($isBetter) {
                $best = $result;
            }
        }

        if ($best['cues'] > 0) {
            Log::info("[DUB] Best subtitle track selected for {$lang}: {$best['cues']} cues, {$best['bytes']} bytes", [
                'session' => $this->sessionId,
                'uri' => $best['uri'],
                'candidates' => count($candidates),
            ]);
        }

        return $best;
    }

    public function fetchYouTubeSrt(string $url): string
    {
        try {
            $tmpDir = sys_get_temp_dir() . '/yt_subs_' . $this->sessionId;
            @mkdir($tmpDir, 0755, true);

            // Try manual subs first (any language), then auto-generated (en/ru/uz only)
            $attempts = [
                ['--write-subs',      'all'],
                ['--write-auto-subs', 'en,ru,uz'],
            ];
            foreach ($attempts as [$subFlag, $subLangs]) {
                $result = Process::timeout(60)->run([
                    'yt-dlp',
                    $subFlag,
                    '--skip-download',
                    '--sub-langs', $subLangs,
                    '--sub-format', 'vtt',
                    '--convert-subs', 'srt',
                    '-o', $tmpDir . '/sub',
                    '--no-playlist',
                    '--quiet',
                    '--extractor-args', 'youtube:player_client=web_creator,mweb,ios',
                    $url,
                ]);

                // Find any .srt file written
                $files = glob($tmpDir . '/*.srt') ?: [];
                if (!empty($files)) {
                    usort($files, function ($a, $b) {
                        $cueDiff = $this->countSubtitleCues($b) <=> $this->countSubtitleCues($a);
                        return $cueDiff !== 0 ? $cueDiff : (filesize($b) ?: 0) <=> (filesize($a) ?: 0);
                    });
                    $selected = $files[0];
                    $srt = file_get_contents($selected);
                    $selectedCues = $this->countSubtitleCues($selected);
                    $selectedBytes = strlen($srt ?: '');
                    array_map('unlink', glob($tmpDir . '/*'));
                    @rmdir($tmpDir);
                    Log::info("[DUB] YouTube SRT fetched via yt-dlp ({$subFlag})", [
                        'session' => $this->sessionId,
                        'file' => basename($selected),
                        'cues' => $selectedCues,
                        'bytes' => $selectedBytes,
                        'candidates' => count($files),
                    ]);
                    return $srt ?: '';
                }
            }

            @rmdir($tmpDir);
        } catch (\Throwable $e) {
            Log::warning("[DUB] YouTube SRT fetch failed: " . $e->getMessage(), ['session' => $this->sessionId]);
        }

        return '';
    }

    private function countSubtitleCues(string $path): int
    {
        $content = is_file($path) ? (file_get_contents($path) ?: '') : '';
        return preg_match_all('/-->/u', $content);
    }

}
