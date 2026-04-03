<?php

namespace App\Services\Xtts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XttsClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.xtts.url', 'http://localhost:8001'), '/');
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
            throw new \RuntimeException("XTTS clone: no audio file provided");
        }

        $response = Http::timeout(60)
            ->attach('audio', file_get_contents($filePath), basename($filePath))
            ->post("{$this->baseUrl}/clone", [
                'name'     => $name,
                'language' => 'uz',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("XTTS clone failed: " . $response->body());
        }

        $data = $response->json();
        return $data['voice_id'] ?? throw new \RuntimeException("XTTS clone: no voice_id in response");
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
            throw new \RuntimeException("XTTS synthesize failed: " . $response->body());
        }

        return $response->body(); // WAV bytes
    }

    /**
     * Delete a cloned voice from the XTTS service.
     * Matches ElevenLabsClient::deleteVoice() interface.
     */
    public function deleteVoice(string $voiceId): void
    {
        try {
            Http::timeout(10)->delete("{$this->baseUrl}/voices/{$voiceId}");
        } catch (\Throwable $e) {
            Log::warning("XTTS deleteVoice failed for {$voiceId}: " . $e->getMessage());
        }
    }
}
