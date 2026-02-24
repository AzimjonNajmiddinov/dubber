<?php

namespace App\Services\Tts\Drivers;

use App\Contracts\TtsDriverInterface;
use App\Models\Speaker;
use App\Models\VideoSegment;
use App\Services\TextNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * UzbekVoice.ai TTS Driver — calls the uzbekvoice.ai API for speech synthesis.
 *
 * Uses native Uzbek voice models with built-in emotion variants.
 * Voice selection cycles through gender-appropriate models for speaker variety.
 * Emotion is mapped to model variants (e.g. davron-happy, dilfuza-angry).
 */
class UzbekVoiceDriver implements TtsDriverInterface
{
    protected string $apiUrl;
    protected string $apiKey;

    /**
     * Voice pools by gender — cycled for multi-speaker variety.
     * Using only confirmed-working models (lola, shoira).
     * Pitch shifting provides speaker differentiation.
     */
    protected array $maleVoices = ['lola', 'shoira'];
    protected array $femaleVoices = ['lola', 'shoira'];

    /**
     * Pitch offsets in semitones for each "round" of voice reuse.
     * Round 0 = first use (no shift), round 1 = second use (+1.5 semitones), etc.
     * Keeps speakers distinguishable even when sharing the same base voice.
     */
    protected array $pitchRounds = [0, 1.5, -1.5, 3.0, -3.0];

    /**
     * Emotion variants available per voice model.
     * Models not listed here only support their base (neutral) form.
     */
    protected array $emotionVariants = [
        'davron'  => ['neutral', 'happy', 'angry'],
        'jahongir' => ['neutral', 'angry'],
        'dilfuza' => ['neutral', 'happy', 'angry', 'sad'],
        'fotima'  => ['neutral', 'angry'],
        // shoira and lola have no emotion variants (single form)
    ];

    public function __construct()
    {
        $this->apiUrl = config('services.uzbekvoice.url', 'https://uzbekvoice.ai/api/v1');
        $this->apiKey = config('services.uzbekvoice.api_key', '');
    }

    public function name(): string
    {
        return 'uzbekvoice';
    }

    public function supportsVoiceCloning(): bool
    {
        return false;
    }

    public function supportsEmotions(): bool
    {
        return true;
    }

    public function getVoices(string $language): array
    {
        if ($language !== 'uz') {
            return [];
        }

        return [
            'male' => $this->maleVoices,
            'female' => $this->femaleVoices,
        ];
    }

    public function getCostPerCharacter(): float
    {
        return 0.0;
    }

    public function synthesize(
        string $text,
        Speaker $speaker,
        VideoSegment $segment,
        array $options = []
    ): string {
        $language = $options['language'] ?? 'uz';
        $emotion = strtolower($options['emotion'] ?? $segment->emotion ?? 'neutral');

        // Normalize text (numbers to words, punctuation cleanup)
        $text = TextNormalizer::normalize($text, $language);

        // Select base voice model by speaker gender + index
        $baseVoice = $this->selectVoice($speaker);

        // Calculate pitch shift for repeated voices (3rd male speaker reuses davron, etc.)
        $pitchShiftSemitones = $this->getPitchShift($speaker);

        // Select emotion variant (e.g. davron-happy, dilfuza-angry)
        $model = $this->selectModel($baseVoice, $emotion);

        $videoId = $segment->video_id;
        $segmentId = $segment->id;

        $outDir = Storage::disk('local')->path("audio/tts/{$videoId}");
        @mkdir($outDir, 0777, true);

        $outputWav = "{$outDir}/seg_{$segmentId}.wav";
        @unlink($outputWav);

        // Call uzbekvoice.ai TTS API
        $audioContent = $this->callApi($text, $model, $segmentId);

        // Write downloaded WAV to temp file
        $tmpRaw = "{$outDir}/seg_{$segmentId}.raw.audio";
        file_put_contents($tmpRaw, $audioContent);

        // Convert to 48kHz stereo WAV (pipeline standard)
        $this->convertToWav($tmpRaw, $outputWav, $options, $pitchShiftSemitones);
        @unlink($tmpRaw);

        Log::info('UzbekVoice: synthesis complete', [
            'segment_id' => $segmentId,
            'speaker_id' => $speaker->id,
            'model' => $model,
            'base_voice' => $baseVoice,
            'emotion' => $emotion,
            'pitch_shift' => $pitchShiftSemitones,
            'text_length' => mb_strlen($text),
        ]);

        return $outputWav;
    }

    public function cloneVoice(string $audioPath, string $name, array $options = []): string
    {
        throw new RuntimeException('UzbekVoice does not support voice cloning');
    }

    /**
     * Call the uzbekvoice.ai TTS API with blocking="true" and download the result WAV.
     * Retries on rate limit (400) and server errors (500) with backoff.
     */
    protected function callApi(string $text, string $model, int $segmentId): string
    {
        $maxRetries = 5;
        $data = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $response = Http::timeout(120)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Accept' => 'application/json',
                ])
                ->post("{$this->apiUrl}/tts", [
                    'text' => $text,
                    'model' => $model,
                    'blocking' => 'true',
                ]);

            // Rate limit or server overload — back off and retry
            if (in_array($response->status(), [400, 429, 500, 502, 503])) {
                $body = $response->body();
                $isRateLimit = str_contains($body, 'Too many') || str_contains($body, 'active requests');

                if ($attempt < $maxRetries && ($isRateLimit || $response->status() >= 500)) {
                    $delay = $isRateLimit ? $attempt * 5 : $attempt * 3;
                    Log::info("UzbekVoice: rate limited, retry {$attempt}/{$maxRetries} in {$delay}s", [
                        'segment_id' => $segmentId,
                        'status' => $response->status(),
                    ]);
                    sleep($delay);
                    continue;
                }
            }

            if ($response->failed()) {
                Log::error('UzbekVoice: API call failed', [
                    'segment_id' => $segmentId,
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                    'attempt' => $attempt,
                ]);
                throw new RuntimeException(
                    "UzbekVoice API failed for segment {$segmentId}: HTTP {$response->status()}"
                );
            }

            $data = $response->json();

            if (($data['status'] ?? '') === 'SUCCESS' && !empty($data['result']['url'])) {
                break;
            }

            // API returned non-SUCCESS (PENDING/STARTED) even with blocking — retry
            if ($attempt < $maxRetries) {
                Log::info("UzbekVoice: got {$data['status']}, retry {$attempt}/{$maxRetries}", [
                    'segment_id' => $segmentId,
                ]);
                sleep($attempt * 2);
                continue;
            }

            Log::error('UzbekVoice: unexpected API response after retries', [
                'segment_id' => $segmentId,
                'model' => $model,
                'response' => mb_substr(json_encode($data), 0, 500),
            ]);
            throw new RuntimeException(
                "UzbekVoice API returned non-success for segment {$segmentId}: " . ($data['status'] ?? 'unknown')
            );
        }

        $audioUrl = $data['result']['url'];

        // Download the WAV file from the CDN URL
        $audioResponse = Http::timeout(60)->get($audioUrl);

        if ($audioResponse->failed()) {
            Log::error('UzbekVoice: failed to download audio', [
                'segment_id' => $segmentId,
                'url' => $audioUrl,
                'status' => $audioResponse->status(),
            ]);
            throw new RuntimeException(
                "UzbekVoice: failed to download audio for segment {$segmentId}: HTTP {$audioResponse->status()}"
            );
        }

        $body = $audioResponse->body();

        if (strlen($body) < 1000) {
            Log::error('UzbekVoice: downloaded audio too small', [
                'segment_id' => $segmentId,
                'size' => strlen($body),
            ]);
            throw new RuntimeException(
                "UzbekVoice: invalid audio for segment {$segmentId} (" . strlen($body) . " bytes)"
            );
        }

        return $body;
    }

    /**
     * Select base voice model based on speaker gender, cycling for variety.
     */
    protected function selectVoice(Speaker $speaker): string
    {
        // Use speaker's configured voice if already assigned by SpeakerTuning
        if (!empty($speaker->tts_voice)) {
            // Ensure it's a valid uzbekvoice model name (not an edge-tts voice)
            $allVoices = array_merge($this->maleVoices, $this->femaleVoices);
            if (in_array($speaker->tts_voice, $allVoices)) {
                return $speaker->tts_voice;
            }
        }

        $gender = strtolower($speaker->gender ?? 'male');
        if (!in_array($gender, ['male', 'female'])) {
            $gender = 'male';
        }

        $voices = ($gender === 'female') ? $this->femaleVoices : $this->maleVoices;

        // Cycle through voices using speaker ID for deterministic variety
        return $voices[$speaker->id % count($voices)];
    }

    /**
     * Get pitch shift in semitones for this speaker.
     * First round of voices (index < pool size) gets 0 shift.
     * Subsequent rounds get increasing shifts to differentiate repeated voices.
     */
    protected function getPitchShift(Speaker $speaker): float
    {
        $gender = strtolower($speaker->gender ?? 'male');
        $voices = ($gender === 'female') ? $this->femaleVoices : $this->maleVoices;
        $poolSize = count($voices);

        // Use speaker ID to determine which "round" of reuse this is
        $round = intdiv($speaker->id % ($poolSize * count($this->pitchRounds)), $poolSize);
        $round = min($round, count($this->pitchRounds) - 1);

        return $this->pitchRounds[$round];
    }

    /**
     * Select the full model name with emotion variant.
     *
     * Maps detected emotion to available model variants.
     * Falls back to base model name when emotion isn't available.
     * Neutral emotion uses the base name (e.g. "davron", not "davron-neutral").
     */
    protected function selectModel(string $baseVoice, string $emotion): string
    {
        $availableEmotions = $this->emotionVariants[$baseVoice] ?? [];

        // No emotion variants → return base model name as-is
        if (empty($availableEmotions)) {
            return $baseVoice;
        }

        // Map pipeline emotions to uzbekvoice emotion variants
        $emotionMap = [
            'happy' => 'happy',
            'excited' => 'happy',
            'angry' => 'angry',
            'frustration' => 'angry',
            'contempt' => 'angry',
            'sad' => 'sad',
            'fear' => 'sad',
            'anxious' => 'sad',
        ];

        $mapped = $emotionMap[$emotion] ?? 'neutral';

        // Check if the mapped emotion is available for this voice
        if (!in_array($mapped, $availableEmotions)) {
            $mapped = 'neutral';
        }

        // Neutral uses base model name (e.g. "davron"), non-neutral appends suffix (e.g. "davron-happy")
        if ($mapped === 'neutral') {
            return $baseVoice;
        }

        return "{$baseVoice}-{$mapped}";
    }

    /**
     * Convert API response audio to pipeline-standard 48kHz stereo WAV.
     * Optionally applies pitch shift (in semitones) to differentiate repeated voices.
     */
    protected function convertToWav(string $input, string $output, array $options, float $pitchShiftSemitones = 0.0): void
    {
        $gainDb = (float) ($options['gain_db'] ?? 0.0);

        $filters = [];

        // Pitch shift using rubberband (preserves tempo, shifts pitch)
        if (abs($pitchShiftSemitones) > 0.1) {
            $filters[] = "rubberband=pitch=" . round(2 ** ($pitchShiftSemitones / 12), 6);
        }

        $filters[] = "aresample=48000";
        $filters[] = "aformat=sample_fmts=fltp:channel_layouts=stereo";

        if (abs($gainDb) > 0.1) {
            $sign = $gainDb >= 0 ? '+' : '';
            $filters[] = "volume={$sign}" . round($gainDb, 1) . 'dB';
        }

        $filter = implode(',', $filters);

        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -vn -af %s -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($input),
            escapeshellarg($filter),
            escapeshellarg($output)
        );

        exec($cmd, $out, $code);

        if ($code !== 0 || !file_exists($output) || filesize($output) < 1000) {
            Log::error('UzbekVoice: audio conversion failed', [
                'exit_code' => $code,
                'output' => implode("\n", $out),
            ]);
            throw new RuntimeException('UzbekVoice audio conversion to WAV failed');
        }
    }
}
