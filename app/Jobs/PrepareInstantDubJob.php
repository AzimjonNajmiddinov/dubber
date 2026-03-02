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

        // 3. Translate and dispatch TTS per batch — first TTS starts while rest translates
        $needsTranslation = $this->translateFrom && $this->translateFrom !== $this->language;
        $dispatched = 0;
        $allSpeakers = [];

        $batches = array_chunk($segments, 10, true);
        foreach ($batches as $batch) {
            if ($needsTranslation) {
                $batch = $this->translateBatch($batch);
            }

            // Collect speakers and update voice map incrementally
            foreach ($batch as $seg) {
                $tag = $seg['speaker'] ?? 'M1';
                $allSpeakers[$tag] = true;
            }
            $this->buildVoiceMap($allSpeakers);

            // Dispatch TTS immediately for this batch
            foreach ($batch as $i => $seg) {
                $text = trim($seg['text']);
                if ($text === '') continue;

                ProcessInstantDubSegmentJob::dispatch(
                    $this->sessionId, $i, $text,
                    $seg['start'], $seg['end'], $this->language,
                    $seg['speaker'] ?? 'M1',
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
            $duration = round($seg['end'] - $seg['start'], 1);
            $maxChars = (int) round($duration * 12); // ~12 chars/sec for Uzbek TTS
            $lines[] = ($i + 1) . '. [' . $duration . 's, max ' . $maxChars . ' chars] ' . $seg['text'];
        }

        $systemPrompt = "You are a professional film/series subtitle translator for voice dubbing. Translate every line to natural, fluent {$toLang}.\n\nCRITICAL: Each line has a time slot shown as [Ns, max M chars]. The translation MUST fit within that character limit because it will be spoken aloud by TTS. Use concise, natural phrasing. Shorten wordy constructions. Prefer shorter synonyms. Drop filler words. The meaning must be preserved but brevity is essential.\n\nAlso identify distinct speakers from dialogue context. Prefix each line with a speaker tag: [M1] for first male, [M2] second male, [F1] first female, etc.\n\nFormat: \"1. [M1] translated text\"\nDo not include the timing info in your output. Do not skip or merge lines. Keep the exact numbering.";

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'temperature' => 0.3,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => implode("\n", $lines)],
                    ],
                ]);

            if ($response->successful()) {
                $translated = trim($response->json('choices.0.message.content') ?? '');
                foreach (preg_split('/\n+/', $translated) as $line) {
                    // Try format with speaker tag: "1. [M1] translated text"
                    if (preg_match('/^(\d+)\.\s*\[([MF]\d+)\]\s*(.+)/', $line, $lm)) {
                        $idx = (int) $lm[1] - 1;
                        if (isset($batch[$idx])) {
                            $batch[$idx]['speaker'] = $lm[2];
                            $batch[$idx]['text'] = trim($lm[3]);
                        }
                    // Fallback: no speaker tag
                    } elseif (preg_match('/^(\d+)\.\s*(.+)/', $line, $lm)) {
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

    private function buildVoiceMap(array $speakers): void
    {
        // Edge-tts voices with pitch variants for speaker differentiation.
        // SardorNeural = male base, MadinaNeural = female base.
        // Pitch: edge-tts --pitch=+/-NHz shifts vocal tone.
        $maleVariants = [
            ['voice' => 'uz-UZ-SardorNeural', 'pitch' => '+0Hz',  'rate' => '+0%'],
            ['voice' => 'uz-UZ-SardorNeural', 'pitch' => '-8Hz',  'rate' => '-5%'],  // deeper, slower
            ['voice' => 'uz-UZ-SardorNeural', 'pitch' => '+6Hz',  'rate' => '+5%'],  // higher, faster
            ['voice' => 'uz-UZ-SardorNeural', 'pitch' => '-15Hz', 'rate' => '-8%'],  // much deeper
        ];
        $femaleVariants = [
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '+0Hz',  'rate' => '+0%'],
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '-6Hz',  'rate' => '-5%'],  // deeper
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '+8Hz',  'rate' => '+5%'],  // higher
            ['voice' => 'uz-UZ-MadinaNeural', 'pitch' => '-12Hz', 'rate' => '-8%'],  // much deeper
        ];

        $voiceMap = [];
        $maleIdx = 0;
        $femaleIdx = 0;

        foreach (array_keys($speakers) as $tag) {
            if (str_starts_with($tag, 'M')) {
                $voiceMap[$tag] = $maleVariants[$maleIdx % count($maleVariants)];
                $maleIdx++;
            } else {
                $voiceMap[$tag] = $femaleVariants[$femaleIdx % count($femaleVariants)];
                $femaleIdx++;
            }
        }

        $voiceKey = "instant-dub:{$this->sessionId}:voices";
        Redis::setex($voiceKey, 50400, json_encode($voiceMap));

        Log::info('Voice map built', [
            'session' => $this->sessionId,
            'speakers' => array_keys($speakers),
            'map' => $voiceMap,
        ]);
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
