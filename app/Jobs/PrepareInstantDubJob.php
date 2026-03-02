<?php

namespace App\Jobs;

use App\Services\SrtParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PrepareInstantDubJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(
        public string $sessionId,
        public string $videoUrl,
        public string $language,
        public string $translateFrom,
        public string $srt,
    ) {}

    public function handle(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";

        // 1. Get subtitles — from SRT or fetch from HLS
        $srt = $this->srt;

        if (trim($srt) === '' && str_contains($this->videoUrl, '.m3u8')) {
            $this->updateStatus('Fetching subtitles...');
            $srt = $this->fetchSubsFromHls($this->videoUrl);
            if (!$srt) {
                $this->updateStatus('error', 'No subtitles found in HLS');
                return;
            }
        }

        if (trim($srt) === '') {
            $this->updateStatus('error', 'No subtitles available');
            return;
        }

        // 2. Parse SRT
        $segments = SrtParser::parse($srt);

        // Filter sound effects
        $segments = array_values(array_filter($segments, function ($seg) {
            $clean = preg_replace('/\[[^\]]*\]/', '', $seg['text']);
            $clean = preg_replace('/[-♪\s]+/', '', $clean);
            return $clean !== '';
        }));

        if (empty($segments)) {
            $this->updateStatus('error', 'No speakable segments');
            return;
        }

        // Clean bracket annotations
        foreach ($segments as &$seg) {
            $clean = preg_replace('/\[[^\]]*\]\s*/', '', $seg['text']);
            $clean = preg_replace('/^-\s*/', '', $clean);
            $clean = preg_replace('/\s+-\s+/', ' ', $clean);
            $seg['text'] = trim($clean);
        }
        unset($seg);
        $segments = array_values(array_filter($segments, fn($s) => trim($s['text']) !== ''));

        // Update total count so UI can show progress
        $this->updateSession(['total_segments' => count($segments), 'status' => 'processing']);

        // 3. Translate + dispatch TTS in batches of 10 (so first TTS starts while rest translates)
        $needsTranslation = $this->translateFrom && $this->translateFrom !== $this->language;
        $dispatched = 0;

        $batches = array_chunk($segments, 10, true);
        foreach ($batches as $batch) {
            if ($needsTranslation) {
                $batch = $this->translateBatch($batch);
            }

            foreach ($batch as $i => $seg) {
                $text = trim($seg['text']);
                if ($text === '') continue;

                ProcessInstantDubSegmentJob::dispatch(
                    $this->sessionId, $i, $text,
                    $seg['start'], $seg['end'], $this->language,
                )->onQueue('segment-generation');
                $dispatched++;
            }
        }

        Log::info('Instant dub prepared', [
            'session' => $this->sessionId,
            'segments' => $dispatched,
            'translated' => $needsTranslation,
        ]);
    }

    private function translateBatch(array $batch): array
    {
        $apiKey = config('services.openai.key');
        if (!$apiKey) return $batch;

        $langNames = [
            'uz' => 'Uzbek', 'ru' => 'Russian', 'en' => 'English', 'tr' => 'Turkish',
            'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'ar' => 'Arabic',
            'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean',
        ];
        $toLang = $langNames[$this->language] ?? $this->language;

        $lines = [];
        foreach ($batch as $i => $seg) {
            $lines[] = ($i + 1) . '. ' . $seg['text'];
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
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
                        if (isset($batch[$idx])) {
                            $batch[$idx]['text'] = trim($lm[2]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Batch translation failed', ['error' => $e->getMessage()]);
        }

        return $batch;
    }

    private function fetchSubsFromHls(string $url): ?string
    {
        try {
            $masterResp = Http::timeout(10)->get($url);
            if ($masterResp->failed()) return null;

            $master = $masterResp->body();
            $baseUrl = preg_replace('#/[^/]+$#', '/', $url);

            if (!preg_match('/TYPE=SUBTITLES.*?URI="([^"]+)"/', $master, $m)) return null;

            $query = parse_url($url, PHP_URL_QUERY);
            $resolve = function ($base, $rel) use ($url, $query) {
                if (str_starts_with($rel, 'http')) return $rel;
                $r = rtrim($base, '/') . '/' . $rel;
                return $query ? "{$r}?{$query}" : $r;
            };

            $subsUrl = $resolve($baseUrl, $m[1]);
            $subsResp = Http::timeout(10)->get($subsUrl);
            if ($subsResp->failed()) return null;

            $subsBase = preg_replace('#/[^/]+$#', '/', $subsUrl);
            preg_match_all('/^(seg-\S+\.vtt)$/m', $subsResp->body(), $vttFiles);
            if (empty($vttFiles[1])) return null;

            // Fetch VTT segments concurrently via pool
            $allVtt = '';
            $pool = Http::pool(function ($pool) use ($vttFiles, $subsBase, $resolve) {
                foreach ($vttFiles[1] as $i => $vttFile) {
                    $pool->as((string) $i)->timeout(8)->get($resolve($subsBase, $vttFile));
                }
            });

            foreach ($pool as $resp) {
                if ($resp instanceof \Illuminate\Http\Client\Response && $resp->successful()) {
                    $allVtt .= "\n" . $resp->body();
                }
            }

            // Parse VTT → SRT (inline for speed)
            preg_match_all(
                '/(\d+)\n(\d{2}:\d{2}:\d{2}\.\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}\.\d{3})\n((?:(?!\n\n|\nWEBVTT).)+)/s',
                $allVtt, $matches, PREG_SET_ORDER
            );

            $seen = [];
            $srt = '';
            $num = 0;
            foreach ($matches as $m) {
                $key = "{$m[1]}|{$m[2]}|{$m[3]}";
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $text = trim($m[4]);
                if ($text === '' || preg_match('/^\[.*\]$/', $text) || preg_match('/^♪/', $text)) continue;
                $num++;
                $srt .= "{$num}\n" . str_replace('.', ',', $m[2]) . ' --> ' . str_replace('.', ',', $m[3]) . "\n{$text}\n\n";
            }

            return $srt ?: null;
        } catch (\Throwable $e) {
            Log::error('HLS sub fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function updateStatus(string $status, string $error = ''): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $json = Redis::get($sessionKey);
        if (!$json) return;
        $session = json_decode($json, true);
        $session['status'] = $status;
        if ($error) $session['error'] = $error;
        Redis::setex($sessionKey, 50400, json_encode($session));
    }

    private function updateSession(array $data): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";
        $json = Redis::get($sessionKey);
        if (!$json) return;
        $session = json_decode($json, true);
        Redis::setex($sessionKey, 50400, json_encode(array_merge($session, $data)));
    }
}
