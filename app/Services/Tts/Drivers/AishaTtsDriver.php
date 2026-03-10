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

class AishaTtsDriver implements TtsDriverInterface
{
    protected array $voiceMap = [
        'uz' => [
            'male'   => ['jaxongir'],
            'female' => ['gulnoza'],
        ],
        'ru' => [
            'male'   => ['jaxongir'],
            'female' => ['gulnoza'],
        ],
        'en' => [
            'male'   => ['jaxongir'],
            'female' => ['gulnoza'],
        ],
    ];

    public function name(): string
    {
        return 'aisha';
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
        return $this->voiceMap[$language] ?? $this->voiceMap['uz'];
    }

    public function synthesize(
        string $text,
        Speaker $speaker,
        VideoSegment $segment,
        array $options = []
    ): string {
        $language = $options['language'] ?? 'uz';
        $voice = $this->selectVoice($speaker, $language);
        $mood = $options['mood'] ?? 'neutral';

        $videoId = $segment->video_id;
        $segmentId = $segment->id;

        $outDir = Storage::disk('local')->path("audio/tts/{$videoId}");
        @mkdir($outDir, 0777, true);

        $rawMp3 = "{$outDir}/seg_{$segmentId}.raw.mp3";
        $outputWav = "{$outDir}/seg_{$segmentId}.wav";
        @unlink($rawMp3);
        @unlink($outputWav);

        $text = TextNormalizer::normalize($text, $language);

        Log::info('AISHA TTS synthesis', [
            'segment_id' => $segmentId,
            'voice' => $voice,
            'mood' => $mood,
            'text_length' => mb_strlen($text),
        ]);

        $mp3Data = $this->callAishaApi($text, $language, $voice, $mood);
        file_put_contents($rawMp3, $mp3Data);

        // Convert MP3 to 48kHz stereo WAV (pipeline standard)
        $this->normalizeAudio($rawMp3, $outputWav);
        @unlink($rawMp3);

        return $outputWav;
    }

    public function callAishaApi(
        string $text,
        string $language,
        string $model,
        string $mood = 'neutral',
        string $format = 'mp3',
        string $rate = '16000',
        string $quality = '64k',
        string $channels = 'stereo',
    ): string {
        $apiUrl = config('services.aisha.url', 'https://back.aisha.group/api/v1');
        $apiKey = config('services.aisha.api_key', '');

        $response = Http::timeout(30)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'X-Channels' => $channels,
                'X-Quality' => $quality,
                'X-Rate' => $rate,
                'X-Format' => $format,
            ])
            ->asMultipart()
            ->post("{$apiUrl}/tts/post/", [
                ['name' => 'transcript', 'contents' => $text],
                ['name' => 'language', 'contents' => $language],
                ['name' => 'model', 'contents' => $model],
                ['name' => 'mood', 'contents' => $mood],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'AISHA TTS failed: HTTP ' . $response->status() . ' — ' . substr($response->body(), 0, 300)
            );
        }

        $body = $response->body();
        if (strlen($body) < 200) {
            throw new RuntimeException('AISHA TTS returned empty/tiny response (' . strlen($body) . ' bytes)');
        }

        return $body;
    }

    protected function selectVoice(Speaker $speaker, string $language): string
    {
        if (!empty($speaker->tts_voice)) {
            return $speaker->tts_voice;
        }

        $gender = strtolower($speaker->gender ?? 'male');
        if (!in_array($gender, ['male', 'female'])) {
            $gender = 'male';
        }

        $langVoices = $this->voiceMap[$language] ?? $this->voiceMap['uz'];
        $genderVoices = $langVoices[$gender] ?? $langVoices['male'];

        return $genderVoices[0];
    }

    public function cloneVoice(string $audioPath, string $name, array $options = []): string
    {
        throw new RuntimeException('AISHA TTS does not support voice cloning');
    }

    public function getCostPerCharacter(): float
    {
        return 0.0;
    }

    protected function normalizeAudio(string $input, string $output): void
    {
        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -vn -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($input),
            escapeshellarg($output)
        );

        exec($cmd, $out, $code);

        if ($code !== 0 || !file_exists($output) || filesize($output) < 1000) {
            Log::error('AISHA audio normalization failed', [
                'exit_code' => $code,
                'output' => implode("\n", $out),
            ]);
            throw new RuntimeException("AISHA audio normalization failed");
        }
    }
}
