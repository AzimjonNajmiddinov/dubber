<?php

namespace App\Jobs;

use App\Models\Speaker;
use App\Models\Video;
use App\Models\VideoSegment;
use App\Services\SpeakerTuning;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RefineSpeakerAssignmentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;
    public int $uniqueFor = 600;

    private const WINDOW_SIZE = 30;
    private const WINDOW_OVERLAP = 10;

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RefineSpeakerAssignmentJob failed permanently', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);

        // Graceful degradation: dispatch translation anyway so pipeline continues
        try {
            TranslateAudioJob::dispatch($this->videoId);
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch TranslateAudioJob after RefineSpeakerAssignmentJob failure', [
                'video_id' => $this->videoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(): void
    {
        $lock = Cache::lock("video:{$this->videoId}:refine-speakers", 300);
        if (! $lock->get()) {
            return;
        }

        try {
            /** @var Video $video */
            $video = Video::query()->findOrFail($this->videoId);

            $segments = VideoSegment::query()
                ->with('speaker')
                ->where('video_id', $video->id)
                ->orderBy('start_time')
                ->get();

            if ($segments->count() < 2) {
                Log::info('RefineSpeakerAssignment: skipping, fewer than 2 segments', [
                    'video_id' => $video->id,
                ]);
                TranslateAudioJob::dispatch($video->id);
                return;
            }

            $speakers = Speaker::where('video_id', $video->id)->get()->keyBy('external_key');
            $speakersById = $speakers->keyBy('id');

            $apiKey = (string) config('services.openai.key');
            if (trim($apiKey) === '') {
                Log::warning('RefineSpeakerAssignment: no OpenAI key, skipping refinement', [
                    'video_id' => $video->id,
                ]);
                TranslateAudioJob::dispatch($video->id);
                return;
            }

            $makeClient = fn () => Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(90);

            $segmentsList = $segments->values();
            $totalCorrections = 0;

            // Slide window across segments
            for ($windowStart = 0; $windowStart < $segmentsList->count(); $windowStart += self::WINDOW_SIZE - self::WINDOW_OVERLAP) {
                $window = $segmentsList->slice($windowStart, self::WINDOW_SIZE)->values();

                if ($window->count() < 2) {
                    break;
                }

                // Skip monologue windows: single speaker AND all durations >3s
                if ($this->isMonologue($window)) {
                    continue;
                }

                $corrections = $this->processWindow($window, $speakersById, $makeClient, $video->id);

                if (empty($corrections)) {
                    continue;
                }

                // Apply corrections
                foreach ($corrections as $correction) {
                    $segIndex = $correction['line'] - 1; // GPT uses 1-based line numbers
                    if ($segIndex < 0 || $segIndex >= $window->count()) {
                        continue;
                    }

                    $segment = $window[$segIndex];
                    $newSpeakerKey = $correction['speaker'];

                    // Find or create the target speaker
                    $targetSpeaker = $speakers->get($newSpeakerKey);

                    if (!$targetSpeaker) {
                        // GPT identified a new speaker — create it
                        $targetSpeaker = $this->createNewSpeaker($video, $newSpeakerKey);
                        $speakers[$newSpeakerKey] = $targetSpeaker;
                        $speakersById[$targetSpeaker->id] = $targetSpeaker;
                    }

                    // Only update if actually changing
                    if ($segment->speaker_id !== $targetSpeaker->id) {
                        $oldSpeaker = $speakersById->get($segment->speaker_id);
                        $oldKey = $oldSpeaker ? $oldSpeaker->external_key : 'unknown';

                        $segment->update(['speaker_id' => $targetSpeaker->id]);
                        $totalCorrections++;

                        Log::info('RefineSpeakerAssignment: corrected speaker', [
                            'video_id' => $video->id,
                            'segment_id' => $segment->id,
                            'time' => round($segment->start_time, 2) . '-' . round($segment->end_time, 2),
                            'text' => mb_substr($segment->text, 0, 60),
                            'from' => $oldKey,
                            'to' => $newSpeakerKey,
                            'reason' => $correction['reason'] ?? '',
                        ]);
                    }
                }
            }

            Log::info('RefineSpeakerAssignment: completed', [
                'video_id' => $video->id,
                'total_segments' => $segmentsList->count(),
                'total_corrections' => $totalCorrections,
            ]);

            TranslateAudioJob::dispatch($video->id);

        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Check if a window is a monologue (single speaker, all segments >3s).
     */
    private function isMonologue($window): bool
    {
        $speakerIds = $window->pluck('speaker_id')->unique();
        if ($speakerIds->count() > 1) {
            return false;
        }

        foreach ($window as $seg) {
            $duration = (float) $seg->end_time - (float) $seg->start_time;
            if ($duration <= 3.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Send a window of segments to GPT-4o for speaker correction.
     *
     * @return array Corrections: [['line' => int, 'speaker' => string, 'reason' => string], ...]
     */
    private function processWindow($window, $speakersById, callable $makeClient, int $videoId): array
    {
        // Build the numbered segment list for GPT
        $lines = [];
        $speakerLabels = [];

        foreach ($window as $i => $seg) {
            $lineNum = $i + 1;
            $speaker = $speakersById->get($seg->speaker_id);
            $speakerKey = $speaker ? $speaker->external_key : 'UNKNOWN';
            $gender = $speaker ? ($speaker->gender ?? 'unknown') : 'unknown';
            $pitch = $speaker && $speaker->pitch_median_hz
                ? round($speaker->pitch_median_hz) . 'Hz'
                : '';

            $speakerInfo = $speakerKey . ' (' . $gender;
            if ($pitch) {
                $speakerInfo .= ', ' . $pitch;
            }
            $speakerInfo .= ')';

            $startTime = round((float) $seg->start_time, 2);
            $endTime = round((float) $seg->end_time, 2);

            $lines[] = "[{$lineNum}] {$speakerInfo} {$startTime}-{$endTime}: \"{$seg->text}\"";

            if (!isset($speakerLabels[$speakerKey])) {
                $speakerLabels[$speakerKey] = $gender;
            }
        }

        $segmentText = implode("\n", $lines);

        // Build speaker summary
        $speakerSummary = [];
        foreach ($speakerLabels as $key => $gender) {
            $speakerSummary[] = "{$key} ({$gender})";
        }

        $systemPrompt = <<<PROMPT
You are an expert dialogue analyst correcting automatic speaker diarization errors.

SPEAKERS IN THIS VIDEO:
{SPEAKER_LIST}

TASK:
Review the numbered transcript lines below. The automatic speaker diarization sometimes assigns wrong speakers, especially during:
- Rapid back-and-forth dialogue (1-2 second turns)
- Arguments or heated exchanges
- Question-answer pairs

Look for these patterns to detect errors:
1. CONVERSATIONAL TURN-TAKING: Questions followed by answers should usually be different speakers
2. DISAGREEMENTS: "No" / "You can't" / rebuttals are usually from a different speaker than the preceding statement
3. GENDER MISMATCH: If pitch/gender info contradicts the assignment
4. REPEATED SPEAKER in rapid short turns: If the same speaker has 3+ consecutive sub-2-second lines that read like a dialogue, some likely belong to another speaker

RULES:
- Only change assignments you're CONFIDENT about. If unsure, keep the original.
- Use ONLY speaker labels from the list above. Do NOT invent new speakers unless the dialogue clearly contains a voice not matching any existing speaker.
- Return ONLY the lines that need correction, as a JSON array.
- If no corrections needed, return an empty array: []

RESPONSE FORMAT (JSON array only, no other text):
[
  {"line": 15, "speaker": "SPEAKER_0", "reason": "responding to SPEAKER_5's question"},
  {"line": 17, "speaker": "SPEAKER_0", "reason": "continuation of SPEAKER_0's argument"}
]
PROMPT;

        $systemPrompt = str_replace('{SPEAKER_LIST}', implode(', ', $speakerSummary), $systemPrompt);

        // Call GPT with retry on 429
        $res = null;
        for ($retry = 1; $retry <= 4; $retry++) {
            $res = $makeClient()->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o',
                'temperature' => 0.1,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $segmentText],
                ],
            ]);

            if ($res->successful()) {
                break;
            }

            if ($res->status() === 429) {
                $retryAfter = $res->header('Retry-After');
                $wait = ($retryAfter && is_numeric($retryAfter))
                    ? (int) $retryAfter + 1
                    : min(5 * pow(2, $retry - 1), 60);

                Log::warning('RefineSpeakerAssignment: OpenAI 429, waiting', [
                    'video_id' => $videoId,
                    'retry' => $retry,
                    'wait' => $wait,
                ]);
                sleep((int) $wait);
                continue;
            }

            // Non-429 error — log and return no corrections (graceful)
            Log::warning('RefineSpeakerAssignment: GPT call failed', [
                'video_id' => $videoId,
                'status' => $res->status(),
                'body' => mb_substr($res->body(), 0, 1000),
            ]);
            return [];
        }

        if (!$res || $res->failed()) {
            Log::warning('RefineSpeakerAssignment: GPT call failed after retries', [
                'video_id' => $videoId,
            ]);
            return [];
        }

        $content = $res->json('choices.0.message.content');
        if (!is_string($content)) {
            return [];
        }

        return $this->parseCorrections($content, $window->count(), $videoId);
    }

    /**
     * Parse GPT response into validated corrections.
     */
    private function parseCorrections(string $content, int $windowSize, int $videoId): array
    {
        $content = trim($content);

        // Strip markdown code fences if present
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
            $content = trim($content);
        }

        $parsed = json_decode($content, true);

        if (!is_array($parsed)) {
            Log::warning('RefineSpeakerAssignment: GPT returned invalid JSON', [
                'video_id' => $videoId,
                'content' => mb_substr($content, 0, 500),
            ]);
            return [];
        }

        // Empty array means no corrections needed
        if (empty($parsed)) {
            return [];
        }

        $corrections = [];
        foreach ($parsed as $item) {
            if (!is_array($item)) continue;
            if (!isset($item['line']) || !isset($item['speaker'])) continue;

            $line = (int) $item['line'];
            $speaker = (string) $item['speaker'];
            $reason = (string) ($item['reason'] ?? '');

            // Validate line number is within window
            if ($line < 1 || $line > $windowSize) {
                continue;
            }

            // Validate speaker label format
            if (!preg_match('/^SPEAKER_\d+$/', $speaker) && $speaker !== 'SPEAKER_UNKNOWN') {
                continue;
            }

            $corrections[] = [
                'line' => $line,
                'speaker' => $speaker,
                'reason' => $reason,
            ];
        }

        return $corrections;
    }

    /**
     * Create a new Speaker record for a speaker GPT identified but diarization missed.
     */
    private function createNewSpeaker(Video $video, string $externalKey): Speaker
    {
        $tuner = app(SpeakerTuning::class);

        $speaker = new Speaker([
            'video_id' => $video->id,
            'external_key' => $externalKey,
            'label' => $externalKey,
            'gender' => 'unknown',
            'age_group' => 'unknown',
            'emotion' => 'neutral',
            'gender_confidence' => null,
            'emotion_confidence' => null,
            'pitch_median_hz' => null,
            'tts_voice' => 'pending',
            'tts_gain_db' => 0,
            'tts_rate' => '+0%',
            'tts_pitch' => '+0Hz',
        ]);

        $speaker->save();
        $tuner->applyDefaults($video, $speaker);
        $speaker->save();

        Log::info('RefineSpeakerAssignment: created new speaker', [
            'video_id' => $video->id,
            'speaker_id' => $speaker->id,
            'external_key' => $externalKey,
        ]);

        return $speaker;
    }
}
