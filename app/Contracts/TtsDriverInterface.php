<?php

namespace App\Contracts;

use App\Models\Speaker;
use App\Models\VideoSegment;

interface TtsDriverInterface
{
    /**
     * Get the driver name identifier.
     */
    public function name(): string;

    /**
     * Check if this driver supports voice cloning.
     */
    public function supportsVoiceCloning(): bool;

    /**
     * Check if this driver supports real emotions (not just prosody tweaks).
     */
    public function supportsEmotions(): bool;

    /**
     * Get available voices for a language.
     *
     * @return array<string, array{id: string, name: string, gender: string, preview_url?: string}>
     */
    public function getVoices(string $language): array;

    /**
     * Synthesize text to speech.
     *
     * @param string $text The text to synthesize
     * @param Speaker $speaker The speaker with voice settings
     * @param VideoSegment $segment The segment being synthesized
     * @param array $options Additional options (emotion, rate, pitch, etc.)
     * @return string Path to the generated audio file (absolute)
     */
    public function synthesize(
        string $text,
        Speaker $speaker,
        VideoSegment $segment,
        array $options = []
    ): string;

    /**
     * Clone a voice from audio samples.
     *
     * @param string $audioPath Path to the audio sample
     * @param string $name Name for the cloned voice
     * @param array $options Additional options
     * @return string The voice ID for the cloned voice
     */
    public function cloneVoice(string $audioPath, string $name, array $options = []): string;

    /**
     * Get estimated cost per character (for budgeting).
     */
    public function getCostPerCharacter(): float;
}
