<?php

namespace App\Services\ElevenLabs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ElevenLabsClient
{
    private string $apiKey;
    private string $baseUrl = 'https://api.elevenlabs.io/v1';

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? config('services.elevenlabs.api_key', '');
    }

    public function addVoice(string $name, array $audioFilePaths): string
    {
        $multipart = [
            ['name' => 'name', 'contents' => $name],
        ];

        foreach ($audioFilePaths as $i => $path) {
            $multipart[] = [
                'name' => 'files',
                'contents' => fopen($path, 'r'),
                'filename' => basename($path),
            ];
        }

        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
        ])->timeout(30)->asMultipart()->post("{$this->baseUrl}/voices/add", $multipart);

        if ($response->failed()) {
            throw new RuntimeException(
                'ElevenLabs addVoice failed: HTTP ' . $response->status() . ' — ' . substr($response->body(), 0, 300)
            );
        }

        $voiceId = $response->json('voice_id');
        if (!$voiceId) {
            throw new RuntimeException('ElevenLabs addVoice: no voice_id in response');
        }

        Log::info('ElevenLabs voice cloned', ['name' => $name, 'voice_id' => $voiceId]);
        return $voiceId;
    }

    public function synthesize(string $voiceId, string $text, array $settings = []): string
    {
        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post(
            "{$this->baseUrl}/text-to-speech/{$voiceId}?output_format=mp3_44100_128",
            [
                'text' => $text,
                'model_id' => 'eleven_multilingual_v2',
                'voice_settings' => array_merge([
                    'stability' => 0.5,
                    'similarity_boost' => 0.75,
                ], $settings),
            ]
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'ElevenLabs synthesize failed: HTTP ' . $response->status() . ' — ' . substr($response->body(), 0, 300)
            );
        }

        $body = $response->body();
        if (strlen($body) < 200) {
            throw new RuntimeException('ElevenLabs synthesize returned tiny response (' . strlen($body) . ' bytes)');
        }

        return $body;
    }

    public function deleteVoice(string $voiceId): void
    {
        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
        ])->timeout(15)->delete("{$this->baseUrl}/voices/{$voiceId}");

        if ($response->failed()) {
            Log::warning('ElevenLabs deleteVoice failed', [
                'voice_id' => $voiceId,
                'status' => $response->status(),
            ]);
        }
    }
}
