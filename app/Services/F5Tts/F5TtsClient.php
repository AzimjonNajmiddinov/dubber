<?php

namespace App\Services\F5Tts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class F5TtsClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.f5tts.url', 'http://localhost:8004'), '/');
    }

    /**
     * Clone a voice from audio samples.
     * Matches ElevenLabsClient::addVoice() interface.
     *
     * @param  string   $name          Voice name
     * @param  string[] $audioFilePaths Local file paths (first file used as reference)
     * @return string                  voice_id
     */
    public function addVoice(string $name, array $audioFilePaths): string
    {
        $filePath = $audioFilePaths[0] ?? null;
        if (!$filePath || !file_exists($filePath)) {
            throw new \RuntimeException("F5-TTS clone: no audio file provided");
        }

        $response = Http::timeout(60)
            ->attach('audio', file_get_contents($filePath), basename($filePath))
            ->post("{$this->baseUrl}/clone", [
                'name'     => $name,
                'language' => 'uz',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("F5-TTS clone failed: " . $response->body());
        }

        $data = $response->json();
        return $data['voice_id'] ?? throw new \RuntimeException("F5-TTS clone: no voice_id in response");
    }

    /**
     * Synthesize text with a cloned voice.
     * Matches ElevenLabsClient::synthesize() interface — returns raw audio bytes (WAV).
     *
     * @param  string  $voiceId
     * @param  string  $text
     * @param  array   $options  ['emotion' => string, 'language' => string, 'speed' => float]
     * @return string  WAV binary data
     */
    public function synthesize(string $voiceId, string $text, array $options = []): string
    {
        $response = Http::timeout(120)
            ->post("{$this->baseUrl}/synthesize", [
                'voice_id' => $voiceId,
                'text'     => $text,
                'language' => $options['language'] ?? 'uz',
                'emotion'  => $options['emotion']  ?? 'neutral',
                'speed'    => $options['speed']    ?? 1.0,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("F5-TTS synthesize failed: " . $response->body());
        }

        return $response->body(); // WAV bytes
    }

    /**
     * Delete a cloned voice from the F5-TTS service.
     * Matches ElevenLabsClient::deleteVoice() interface.
     */
    public function deleteVoice(string $voiceId): void
    {
        try {
            Http::timeout(10)->delete("{$this->baseUrl}/voices/{$voiceId}");
        } catch (\Throwable $e) {
            Log::warning("F5-TTS deleteVoice failed for {$voiceId}: " . $e->getMessage());
        }
    }
}
