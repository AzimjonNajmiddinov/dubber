<?php

namespace App\Services\MmsTts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MmsTtsClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.mms_tts.url', 'http://localhost:8005'), '/');
    }

    /**
     * Clone a voice from an audio sample.
     *
     * @param  string   $name          Voice name
     * @param  string[] $audioFilePaths Local file paths (first file used as reference)
     * @return string                  voice_id
     */
    public function addVoice(string $name, array $audioFilePaths): string
    {
        $filePath = $audioFilePaths[0] ?? null;
        if (!$filePath || !file_exists($filePath)) {
            throw new \RuntimeException("MMS TTS clone: no audio file provided");
        }

        $response = Http::timeout(60)
            ->attach('audio', file_get_contents($filePath), basename($filePath))
            ->post("{$this->baseUrl}/clone", [
                'name'     => $name,
                'language' => 'uz',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("MMS TTS clone failed: " . $response->body());
        }

        $data = $response->json();
        return $data['voice_id'] ?? throw new \RuntimeException("MMS TTS clone: no voice_id in response");
    }

    /**
     * Synthesize text with a cloned voice.
     * Returns raw WAV bytes.
     *
     * @param  string  $voiceId
     * @param  string  $text
     * @param  array   $options  ['language' => string, 'speed' => float, 'tau' => float]
     * @return string  WAV binary data
     */
    public function synthesize(string $voiceId, string $text, array $options = []): string
    {
        $response = Http::timeout(120)
            ->post("{$this->baseUrl}/synthesize", [
                'voice_id' => $voiceId,
                'text'     => $text,
                'language' => $options['language'] ?? 'uz',
                'speed'    => $options['speed']    ?? 1.0,
                'tau'      => $options['tau']       ?? 0.9,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("MMS TTS synthesize failed: " . $response->body());
        }

        return $response->body();
    }

    /**
     * Delete a cloned voice from the MMS TTS service.
     */
    public function deleteVoice(string $voiceId): void
    {
        try {
            Http::timeout(10)->delete("{$this->baseUrl}/voices/{$voiceId}");
        } catch (\Throwable $e) {
            Log::warning("MMS TTS deleteVoice failed for {$voiceId}: " . $e->getMessage());
        }
    }
}
