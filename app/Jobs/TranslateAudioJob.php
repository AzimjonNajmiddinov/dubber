<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoSegment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Traits\DetectsEnglish;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslateAudioJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use DetectsEnglish;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1200;

    /**
     * Exponential backoff between retries (seconds).
     */
    public array $backoff = [30, 60, 120];

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    /**
     * Handle job failure - mark video as failed.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TranslateAudioJob failed permanently', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $video = Video::find($this->videoId);
            if ($video) {
                $video->update(['status' => 'translation_failed']);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to update video status after TranslateAudioJob failure', [
                'video_id' => $this->videoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(): void
    {
        $lock = Cache::lock("video:{$this->videoId}:translate", 1200);
        if (! $lock->get()) {
            return;
        }

        try {
            /** @var Video $video */
            $video = Video::query()->findOrFail($this->videoId);

            // Normalize target language (critical for determinism)
            $targetLanguage = $this->normalizeTargetLanguage((string) ($video->target_language ?: 'uz'));

            $segments = VideoSegment::query()
                ->where('video_id', $video->id)
                ->whereNull('translated_text')
                ->orderBy('start_time')
                ->get(['id', 'text']);

            if ($segments->isEmpty()) {
                $video->update(['status' => 'translated']);
                GenerateTtsSegmentsJobV2::dispatch($video->id);
                return;
            }

            $apiKey = (string) config('services.openai.key');
            if (trim($apiKey) === '') {
                throw new \RuntimeException('OpenAI API key missing: services.openai.key');
            }

            $client = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(90)
                ->retry(2, 1000);

            foreach ($segments as $seg) {
                $src = trim((string) $seg->text);

                if ($src === '') {
                    $seg->update(['translated_text' => '']);
                    continue;
                }

                // Attempt up to 2 times if English leaks
                $translated = null;
                $lastBody = null;

                for ($attempt = 1; $attempt <= 2; $attempt++) {
                    $system = $this->buildSystemPrompt($targetLanguage, $attempt);

                    $res = $client->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'temperature' => 0.2,
                        'messages' => [
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user', 'content' => $src],
                        ],
                    ]);

                    if ($res->failed()) {
                        $lastBody = mb_substr($res->body(), 0, 4000);
                        Log::error('OpenAI translate failed', [
                            'video_id' => $video->id,
                            'segment_id' => $seg->id,
                            'attempt' => $attempt,
                            'status' => $res->status(),
                            'body' => $lastBody,
                        ]);
                        throw new \RuntimeException('OpenAI translate request failed');
                    }

                    $out = $res->json('choices.0.message.content');
                    if (!is_string($out)) {
                        Log::error('OpenAI translate invalid response', [
                            'video_id' => $video->id,
                            'segment_id' => $seg->id,
                            'attempt' => $attempt,
                            'json' => $res->json(),
                        ]);
                        throw new \RuntimeException('OpenAI translate invalid response');
                    }

                    $out = trim($out);

                    // Guard: reject English-ish outputs
                    if ($this->looksLikeEnglish($out)) {
                        Log::warning('Translation looks English; retrying with stricter prompt', [
                            'video_id' => $video->id,
                            'segment_id' => $seg->id,
                            'attempt' => $attempt,
                            'target_language' => $targetLanguage,
                            'sample' => mb_substr($out, 0, 160),
                        ]);
                        $translated = null;
                        continue;
                    }

                    $translated = $out;
                    break;
                }

                if ($translated === null) {
                    // Last resort: store *something* but mark it so you can find it
                    Log::error('Translation kept coming back English; storing last output anyway', [
                        'video_id' => $video->id,
                        'segment_id' => $seg->id,
                        'target_language' => $targetLanguage,
                        'src_sample' => mb_substr($src, 0, 160),
                    ]);

                    // Try one more very strict request (single shot)
                    $res = $client->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'temperature' => 0.0,
                        'messages' => [
                            ['role' => 'system', 'content' => $this->buildSystemPrompt($targetLanguage, 3)],
                            ['role' => 'user', 'content' => $src],
                        ],
                    ]);

                    $out = is_string($res->json('choices.0.message.content'))
                        ? trim((string) $res->json('choices.0.message.content'))
                        : '';

                    $translated = $out;
                }

                $seg->update(['translated_text' => $translated]);
            }

            $video->update(['status' => 'translated']);
            GenerateTtsSegmentsJobV2::dispatch($video->id);

        } finally {
            optional($lock)->release();
        }
    }

    private function normalizeTargetLanguage(string $raw): string
    {
        $v = trim(mb_strtolower($raw));

        // Uzbek normalization
        if ($v === '' || $v === 'uz' || $v === 'uzbek' || $v === 'oʻzbek' || $v === 'o‘zbek' || $v === 'uzb' || str_contains($v, 'uzbek')) {
            // Make it explicit: Uzbek Latin
            return "Uzbek (Latin, O‘zbekcha) [uz]";
        }

        // Russian normalization example (optional)
        if ($v === 'ru' || str_contains($v, 'russian')) {
            return "Russian [ru]";
        }

        // Fallback: keep user provided but include "no English" rules anyway
        return $raw;
    }

    private function buildSystemPrompt(string $targetLanguage, int $attempt): string
    {
        // attempt 1: normal
        // attempt 2: extra strict
        // attempt 3: ultra strict (temp 0.0)
        $extra = '';
        if ($attempt >= 2) {
            $extra =
                "\nSTRICT MODE:\n" .
                "- If you are unsure, still produce output in the target language.\n" .
                "- Do NOT output English words.\n" .
                "- Use natural spoken phrases used by native speakers.\n";
        }
        if ($attempt >= 3) {
            $extra .=
                "- Output must be ONLY Uzbek Latin text.\n" .
                "- If a name is English, keep name but everything else Uzbek.\n";
        }

        return
            "You are translating movie dialogue for PROFESSIONAL DUBBING.\n" .
            "Target language: {$targetLanguage}.\n" .
            "Goal: produce natural, actor-ready spoken lines that match timing and emotion.\n" .
            "Rules:\n" .
            "- Output ONLY in the target language.\n" .
            "- Write as real spoken dialogue, not subtitles.\n" .
            "- Match the original line's length and rhythm as closely as possible.\n" .
            "- Prioritize natural flow and lip-sync over literal translation.\n" .
            "- Preserve emotion, intent, and character voice.\n" .
            "- Avoid long or complex sentence structures.\n" .
            "- No explanations, no quotes, no meta text.\n" .
            $extra;

    }

}
