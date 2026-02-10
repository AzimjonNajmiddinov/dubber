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

class XttsDriver implements TtsDriverInterface
{
    protected string $baseUrl;

    /**
     * Direction to synthesis parameters mapping.
     * These modify temperature, speed, volume, and optional audio filters
     * to match the acting direction detected from the dialogue.
     */
    protected array $directionParams = [
        'whisper' => [
            'temperature' => 0.5,
            'speed_mult' => 0.85,
            'volume_db' => -6,
            'filter' => 'highpass=f=200,lowpass=f=6000',
        ],
        'soft' => [
            'temperature' => 0.6,
            'speed_mult' => 0.92,
            'volume_db' => -3,
        ],
        'normal' => [
            'temperature' => 0.7,
            'speed_mult' => 1.0,
            'volume_db' => 0,
        ],
        'loud' => [
            'temperature' => 0.75,
            'speed_mult' => 1.05,
            'volume_db' => 2,
        ],
        'shout' => [
            'temperature' => 0.85,
            'speed_mult' => 1.15,
            'volume_db' => 4,
            'filter' => 'acompressor=threshold=-20dB:ratio=2:attack=5:release=50',
        ],
        'sarcastic' => [
            'temperature' => 0.72,
            'speed_mult' => 0.95,
            'volume_db' => 0,
        ],
        'playful' => [
            'temperature' => 0.8,
            'speed_mult' => 1.08,
            'volume_db' => 1,
        ],
        'cold' => [
            'temperature' => 0.5,
            'speed_mult' => 0.98,
            'volume_db' => -1,
        ],
        'warm' => [
            'temperature' => 0.75,
            'speed_mult' => 0.95,
            'volume_db' => 0,
        ],
    ];

    public function __construct()
    {
        $this->baseUrl = config('services.xtts.url') ?? 'http://xtts:8000';
    }

    public function name(): string
    {
        return 'xtts';
    }

    public function supportsVoiceCloning(): bool
    {
        return true;
    }

    public function supportsEmotions(): bool
    {
        return true;
    }

    public function getVoices(string $language): array
    {
        try {
            $response = Http::timeout(10)->get("{$this->baseUrl}/voices");

            if ($response->failed()) {
                Log::warning('XTTS get voices failed', [
                    'status' => $response->status(),
                ]);
                return [];
            }

            return $response->json('voices', []);

        } catch (\Throwable $e) {
            Log::warning('XTTS service unavailable', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function synthesize(
        string $text,
        Speaker $speaker,
        VideoSegment $segment,
        array $options = []
    ): string {
        $voiceId = $options['voice_id'] ?? $speaker->xtts_voice_id;

        if (!$voiceId) {
            throw new RuntimeException("No XTTS voice ID configured for speaker {$speaker->id}");
        }

        $language = $options['language'] ?? $segment->video->target_language ?? 'uz';
        $emotion = $options['emotion'] ?? $segment->emotion ?? $speaker->emotion ?? 'neutral';
        $direction = $options['direction'] ?? $segment->direction ?? 'normal';
        $speed = $options['speed'] ?? 1.0;

        // Get direction parameters
        $dirParams = $this->directionParams[$direction] ?? $this->directionParams['normal'];

        // Apply direction speed modifier
        $speed *= ($dirParams['speed_mult'] ?? 1.0);

        // Normalize text for TTS (converts numbers to words, normalizes apostrophes, etc.)
        $text = TextNormalizer::normalize($text, $language);

        $videoId = $segment->video_id;
        $segmentId = $segment->id;

        // Output path (relative to storage)
        $outputRel = "audio/tts/{$videoId}/seg_{$segmentId}.wav";

        $outputAbs = Storage::disk('local')->path($outputRel);
        @mkdir(dirname($outputAbs), 0777, true);

        // Synthesize via XTTS - response is the audio file directly
        $response = Http::timeout(600)
            ->withOptions(['sink' => $outputAbs])
            ->post("{$this->baseUrl}/synthesize", [
                'text' => $text,
                'voice_id' => $voiceId,
                'language' => $language,
                'emotion' => $emotion,
                'speed' => $speed,
                'output_path' => $outputRel,
            ]);

        if ($response->failed()) {
            @unlink($outputAbs);
            Log::error('XTTS synthesis failed', [
                'segment_id' => $segmentId,
                'voice_id' => $voiceId,
                'status' => $response->status(),
            ]);
            throw new RuntimeException("XTTS synthesis failed for segment {$segmentId}");
        }

        if (!file_exists($outputAbs) || filesize($outputAbs) < 1000) {
            throw new RuntimeException("XTTS output file missing for segment {$segmentId}");
        }

        // Normalize audio with direction-specific adjustments
        $normalizeOptions = $options;
        $normalizeOptions['direction_volume_db'] = $dirParams['volume_db'] ?? 0;
        $normalizeOptions['direction_filter'] = $dirParams['filter'] ?? null;
        $this->normalizeAudio($outputAbs, $normalizeOptions);

        Log::info('XTTS synthesis complete', [
            'segment_id' => $segmentId,
            'voice_id' => $voiceId,
            'emotion' => $emotion,
            'direction' => $direction,
            'language' => $language,
        ]);

        return $outputAbs;
    }

    public function cloneVoice(string $audioPath, string $name, array $options = []): string
    {
        if (!file_exists($audioPath)) {
            throw new RuntimeException("Audio file not found: {$audioPath}");
        }

        $language = $options['language'] ?? 'uz';
        $description = $options['description'] ?? "Cloned voice: {$name}";

        // Send audio as base64 encoded data (XTTS server prefers this)
        $audioContent = file_get_contents($audioPath);
        $audioBase64 = base64_encode($audioContent);

        $response = Http::timeout(120)
            ->asJson()
            ->post("{$this->baseUrl}/clone", [
                'name' => $name,
                'description' => $description,
                'language' => $language,
                'audio_base64' => $audioBase64,
                'audio_format' => 'wav',
            ]);

        if ($response->failed()) {
            Log::error('XTTS voice cloning failed', [
                'name' => $name,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException("XTTS voice cloning failed: " . $response->body());
        }

        $voiceId = $response->json('voice_id');

        Log::info('XTTS voice cloned', [
            'name' => $name,
            'voice_id' => $voiceId,
        ]);

        return $voiceId;
    }

    public function getCostPerCharacter(): float
    {
        return 0.0; // Free (self-hosted)
    }

    /**
     * Extract speaker voice sample from original audio.
     */
    public function extractVoiceSample(int $videoId, int $speakerId): string
    {
        $speaker = Speaker::findOrFail($speakerId);
        $video = $speaker->video;

        // Get segments for this speaker
        $segments = VideoSegment::where('video_id', $videoId)
            ->where('speaker_id', $speakerId)
            ->orderBy('end_time', 'desc')
            ->orderByRaw('(end_time - start_time) DESC')
            ->limit(5)
            ->get();

        if ($segments->isEmpty()) {
            throw new RuntimeException("No segments found for speaker {$speakerId}");
        }

        // Use the vocals track if available, otherwise original audio
        $audioPath = Storage::disk('local')->path("audio/stems/{$videoId}/vocals.wav");
        if (!file_exists($audioPath)) {
            $audioPath = Storage::disk('local')->path("audio/original/{$videoId}.wav");
        }

        if (!file_exists($audioPath)) {
            throw new RuntimeException("No audio source found for voice extraction");
        }

        // Extract segments and concatenate
        $samplePath = Storage::disk('local')->path("audio/voice_samples/{$videoId}/speaker_{$speakerId}.wav");
        @mkdir(dirname($samplePath), 0777, true);

        $filterParts = [];
        foreach ($segments as $i => $seg) {
            $start = max(0, $seg->start_time);
            $end = $seg->end_time;
            $filterParts[] = sprintf(
                "[0:a]atrim=start=%s:end=%s,asetpts=PTS-STARTPTS[s%d]",
                $start,
                $end,
                $i
            );
        }

        $concatInputs = implode('', array_map(fn($i) => "[s{$i}]", range(0, count($segments) - 1)));
        $filter = implode(';', $filterParts) . ";{$concatInputs}concat=n=" . count($segments) . ":v=0:a=1[out]";

        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -filter_complex %s -map "[out]" -c:a pcm_s16le -ar 22050 -ac 1 %s 2>&1',
            escapeshellarg($audioPath),
            escapeshellarg($filter),
            escapeshellarg($samplePath)
        );

        exec($cmd, $output, $code);

        if ($code !== 0 || !file_exists($samplePath) || filesize($samplePath) < 5000) {
            Log::error('Voice sample extraction failed', [
                'speaker_id' => $speakerId,
                'exit_code' => $code,
                'output' => implode("\n", array_slice($output, -20)),
            ]);
            throw new RuntimeException("Failed to extract voice sample for speaker {$speakerId}");
        }

        return $samplePath;
    }

    protected function normalizeAudio(string $path, array $options): void
    {
        $gainDb = $options['gain_db'] ?? 0.0;
        $directionVolumeDb = $options['direction_volume_db'] ?? 0.0;
        $directionFilter = $options['direction_filter'] ?? null;
        $tempPath = $path . '.tmp.wav';

        // Only resample to 48kHz stereo (XTTS outputs 24kHz mono).
        // No loudnorm here — the final mix handles loudness normalization once.
        $filter = 'aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo';

        // Apply direction-specific filter (e.g., whisper highpass, shout compression)
        if ($directionFilter) {
            $filter .= ',' . $directionFilter;
        }

        // Combine base gain with direction volume adjustment
        $totalGainDb = $gainDb + $directionVolumeDb;
        if (abs($totalGainDb) > 0.1) {
            $sign = $totalGainDb >= 0 ? '+' : '';
            $filter .= ",volume={$sign}{$totalGainDb}dB";
        }

        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -af %s -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($path),
            escapeshellarg($filter),
            escapeshellarg($tempPath)
        );

        exec($cmd, $out, $code);

        if ($code === 0 && file_exists($tempPath) && filesize($tempPath) > 5000) {
            rename($tempPath, $path);
        } else {
            @unlink($tempPath);
            // Keep original if normalization fails
            Log::warning('Audio normalization failed, keeping original', [
                'path' => $path,
            ]);
        }
    }
}
