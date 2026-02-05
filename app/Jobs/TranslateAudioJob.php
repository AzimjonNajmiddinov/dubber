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

            // Fetch all segments ordered by time for surrounding context
            $allSegments = VideoSegment::query()
                ->where('video_id', $video->id)
                ->orderBy('start_time')
                ->get(['id', 'text', 'translated_text', 'start_time', 'end_time']);

            $segments = $allSegments->filter(fn ($s) => $s->translated_text === null);

            if ($segments->isEmpty()) {
                $video->update(['status' => 'translated']);
                GenerateTtsSegmentsJobV2::dispatch($video->id);
                return;
            }

            // Build index for surrounding context lookup
            $segmentsList = $allSegments->values();

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

                // Find surrounding context
                $segIndex = $segmentsList->search(fn ($s) => $s->id === $seg->id);
                $prevText = $segIndex > 0 ? trim((string) $segmentsList[$segIndex - 1]->text) : '';
                $nextText = $segIndex < $segmentsList->count() - 1 ? trim((string) $segmentsList[$segIndex + 1]->text) : '';

                $charCount = mb_strlen($src);

                // Calculate slot duration for time-aware character budget
                $slotDuration = ((float) $seg->end_time) - ((float) $seg->start_time);

                // Attempt up to 2 times if English leaks
                $translated = null;
                $lastBody = null;

                for ($attempt = 1; $attempt <= 2; $attempt++) {
                    $system = $this->buildSystemPrompt($targetLanguage, $attempt, $charCount, $prevText, $nextText, $slotDuration);

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
                            ['role' => 'system', 'content' => $this->buildSystemPrompt($targetLanguage, 3, $charCount, $prevText, $nextText, $slotDuration)],
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

    private function buildSystemPrompt(string $targetLanguage, int $attempt, int $charCount = 0, string $prevText = '', string $nextText = '', float $slotDuration = 0): string
    {
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

        // Surrounding context block
        $contextBlock = '';
        if ($prevText !== '' || $nextText !== '') {
            $contextBlock = "\nSURROUNDING DIALOGUE (for natural flow, do NOT translate these):\n";
            if ($prevText !== '') {
                $contextBlock .= "- Previous line: \"{$prevText}\"\n";
            }
            if ($nextText !== '') {
                $contextBlock .= "- Next line: \"{$nextText}\"\n";
            }
        }

        // Time-aware character budget
        $budgetRule = '';
        if ($charCount > 0) {
            $maxCharsFromSource = (int) round($charCount * 1.2);

            if ($slotDuration > 0) {
                // ~12 chars/sec is a conservative speaking rate for Uzbek TTS
                $maxCharsFromSlot = (int) round($slotDuration * 12);
                $maxChars = min($maxCharsFromSlot, $maxCharsFromSource);

                if ($slotDuration < 1.5) {
                    $budgetRule = "8. TIME CONSTRAINT: This line must fit in {$slotDuration}s. Use only 1-3 words. Maximum {$maxChars} characters.\n";
                } else {
                    $budgetRule = "8. TIME CONSTRAINT: This line must fit in {$slotDuration}s (~{$maxChars} characters max at speaking speed). Shorter is better — dubbing requires fitting speech into this time slot.\n";
                }
            } else {
                $budgetRule = "8. CHARACTER BUDGET: The original text is {$charCount} characters. Your translation MUST be {$maxCharsFromSource} characters or fewer. Shorter is better — dubbing requires fitting speech into the same time slot.\n";
            }
        }

        // Few-shot examples for Uzbek to demonstrate natural spoken style
        $examples = '';
        if (str_contains($targetLanguage, 'Uzbek') || str_contains($targetLanguage, 'uz')) {
            $examples =
                "\nEXAMPLES of good dubbing translation (English → Uzbek):\n" .
                "- \"Here's what it says if you're ready.\" → \"Tayyor bo'lsangiz, mana.\"\n" .
                "- \"I don't think that's going to work out.\" → \"Bu ishlamaydi, menimcha.\"\n" .
                "- \"We need to get out of here right now!\" → \"Tezroq ketishimiz kerak!\"\n" .
                "- \"What are you talking about?\" → \"Nima deyapsan?\"\n" .
                "- \"I told you this was a bad idea.\" → \"Yomon fikr degan edim-ku.\"\n" .
                "- \"There's no way I'm letting you do that.\" → \"Yo'q, qo'ymayman.\"\n" .
                "- \"Can you believe what just happened?\" → \"Ko'rdingmi nima bo'ldi?\"\n" .
                "- \"I'm sorry, I didn't mean to hurt you.\" → \"Kechirasiz, xafa qilmoqchi emasdim.\"\n" .
                "Notice: translations are SHORT, natural, and drop unnecessary words. Do the same.\n";
        }

        return
            "You are an expert DUBBING TRANSLATOR specializing in {$targetLanguage}.\n" .
            "Target language: {$targetLanguage}.\n\n" .
            "YOUR GOAL: Produce concise, natural dubbed dialogue that fits within the original time slot.\n\n" .
            "CRITICAL RULES:\n" .
            "1. BE CONCISE: Translation must be SHORTER or equal length to the original. Dubbing requires fitting speech into the same time slot. Cut filler words, simplify long constructions, use compact phrasing.\n" .
            "2. SPOKEN LANGUAGE: Use short, punchy phrases. Avoid long formal constructions. Write how people TALK, not how they write. Use colloquial, everyday speech patterns of {$targetLanguage}.\n" .
            "3. PRESERVE MEANING: Convey the same meaning, emotions, and intent. Do NOT add or lose information.\n" .
            "4. EMOTION & TONE: Preserve the exact emotional tone — angry stays angry, sad stays sad, humor stays funny.\n" .
            "5. NO ENGLISH: Output ONLY in {$targetLanguage}. Translate everything except proper names.\n" .
            "6. NATURAL FLOW: This line is part of a conversation. Make it flow naturally with surrounding dialogue.\n" .
            "7. OUTPUT ONLY: Return ONLY the translated text. No explanations, no quotes, no commentary.\n" .
            $budgetRule .
            $examples .
            $contextBlock .
            $extra;
    }

}
