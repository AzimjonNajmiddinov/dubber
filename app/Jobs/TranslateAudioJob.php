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
        // Use 4 chars/sec to leave room for TTS variation
        $budgetRule = '';
        if ($slotDuration > 0) {
            $maxChars = max(3, (int) floor($slotDuration * 4));

            if ($slotDuration < 1.0) {
                $budgetRule = "8. CRITICAL: Only {$slotDuration}s! Use 1 word, max {$maxChars} characters. Example: 'Ha' or 'Yo'q'\n";
            } elseif ($slotDuration < 2.0) {
                $budgetRule = "8. VERY SHORT: {$slotDuration}s slot. Max 2 words, {$maxChars} characters total.\n";
            } elseif ($slotDuration < 3.0) {
                $budgetRule = "8. SHORT: {$slotDuration}s. Keep under {$maxChars} characters. Be extremely brief.\n";
            } else {
                $budgetRule = "8. LIMIT: {$slotDuration}s = max {$maxChars} characters. Brevity is essential.\n";
            }
        }

        // Few-shot examples for Uzbek demonstrating emotional range
        $examples = '';
        if (str_contains($targetLanguage, 'Uzbek') || str_contains($targetLanguage, 'uz')) {
            $examples =
                "\nEXAMPLES of good dubbing translation with emotions (English → Uzbek):\n" .
                "- \"Here's what it says.\" → \"Mana, ko'ring.\"\n" .
                "- \"I don't think that's going to work.\" → \"Bu ishlamaydi.\"\n" .
                "- \"We need to get out now!\" (urgent) → \"Tez ketamiz!\"\n" .
                "- \"What are you talking about?\" (confused) → \"Nima deyapsan?\"\n" .
                "- \"I told you this was bad.\" (frustrated) → \"Aytgan edim-ku!\"\n" .
                "- \"No way I'm letting you.\" (firm) → \"Yo'q, qo'ymayman.\"\n" .
                "- \"Can you believe it?\" (surprised) → \"Ko'rdingmi?!\"\n" .
                "- \"I'm sorry, I didn't mean to.\" (apologetic) → \"Kechirasiz.\"\n" .
                "- \"This is incredible!\" (excited) → \"Ajoyib!\"\n" .
                "- \"I can't do this anymore.\" (defeated) → \"Endi qo'limdan kelmaydi.\"\n" .
                "KEY: Translations are SHORT, emotional, and natural. Use punctuation to convey emotion (!?). Match the speaker's feeling.\n";
        }

        return
            "You are an expert FILM DUBBING TRANSLATOR for {$targetLanguage}.\n\n" .
            "YOUR MISSION: Create dubbed dialogue that sounds NATURAL and EMOTIONAL — like a native speaker would say it.\n\n" .
            "CRITICAL RULES:\n" .
            "1. EXTREMELY CONCISE: Use the FEWEST words possible. Dubbing MUST fit the time slot. Drop filler words. Simplify everything.\n" .
            "2. NATURAL SPEECH: Write how people ACTUALLY TALK. Use contractions. Use colloquial phrases. Avoid formal/written style.\n" .
            "3. EMOTIONAL AUTHENTICITY: Match the speaker's emotion EXACTLY. If they're angry, your translation must SOUND angry. If sad, it must feel heavy. Use punctuation (! ? ...) to convey emotion.\n" .
            "4. PRESERVE INTENT: Keep the core meaning but adapt the expression. Cultural equivalents are fine.\n" .
            "5. ONLY {$targetLanguage}: Output ONLY in {$targetLanguage}. No English except proper names.\n" .
            "6. DIALOGUE FLOW: This is part of a conversation. It must sound natural when spoken aloud.\n" .
            "7. PURE OUTPUT: Return ONLY the translated line. No quotes, no explanations.\n" .
            $budgetRule .
            $examples .
            $contextBlock .
            $extra;
    }

}
