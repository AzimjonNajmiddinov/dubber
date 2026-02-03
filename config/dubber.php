<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TTS (Text-to-Speech) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which TTS engine to use for dubbing. Available drivers:
    | - edge: Free Microsoft Edge TTS (robotic, but free)
    | - elevenlabs: High quality, emotional, supports voice cloning (paid)
    | - openai: Natural sounding (paid)
    | - xtts: Local voice cloning with Coqui XTTS (free, self-hosted)
    |
    */

    'tts' => [
        // Default TTS driver to use
        'default' => env('TTS_DRIVER', 'xtts'),

        // Fallback driver if primary fails
        'fallback' => env('TTS_FALLBACK', 'edge'),

        // Auto-clone voices for speakers (requires xtts or elevenlabs)
        'auto_clone' => env('TTS_AUTO_CLONE', true),

        // Preferred driver for voice cloning
        'cloning_driver' => env('TTS_CLONING_DRIVER', 'xtts'),

        // Use SSML markup for Edge-TTS (per-sentence prosody, breaks, intonation)
        'edge_ssml' => env('TTS_EDGE_SSML', true),
    ],

    // Uzbek Edge TTS voices available on your system:
    'voices' => [
        'uz' => [
            'female' => 'uz-UZ-MadinaNeural',
            'male' => 'uz-UZ-SardorNeural',
            'default' => 'uz-UZ-MadinaNeural',
        ],
        'en' => [
            'default' => 'en-US-AriaNeural',
        ],
    ],

    // Default loudness preference (applies as gain_db used in your TTS job)
    'default_gain_db' => 0.0,

    // Emotion → prosody adjustments (Edge TTS supports --rate and --pitch)
    // Values kept mild to avoid unnatural/ultrasound-like voices
    'emotion_presets' => [
        // rate: percentage string, pitch: Hz string, gain_db: float
        'neutral' => ['rate' => '+0%', 'pitch' => '+0Hz', 'gain_db' => 0.0],

        'happy' => ['rate' => '+2%', 'pitch' => '+5Hz', 'gain_db' => 0.3],
        'excited' => ['rate' => '+4%', 'pitch' => '+8Hz', 'gain_db' => 0.6],

        'sad' => ['rate' => '-2%', 'pitch' => '-5Hz', 'gain_db' => -0.4],
        'angry' => ['rate' => '+3%', 'pitch' => '-3Hz', 'gain_db' => 0.6],

        // common alternates in some emotion models
        'fear' => ['rate' => '+2%', 'pitch' => '+4Hz', 'gain_db' => 0.2],
        'surprise' => ['rate' => '+4%', 'pitch' => '+6Hz', 'gain_db' => 0.4],
        'disgust' => ['rate' => '+1%', 'pitch' => '-2Hz', 'gain_db' => 0.0],
    ],

    // Age-group adjustments (optional). Applied on top of emotion.
    // Kept mild to avoid unnatural voices
    'age_presets' => [
        'child' => ['rate' => '+4%', 'pitch' => '+12Hz', 'gain_db' => 0.2],
        'young_adult' => ['rate' => '+1%', 'pitch' => '+2Hz', 'gain_db' => 0.0],
        'adult' => ['rate' => '+0%', 'pitch' => '+0Hz', 'gain_db' => 0.0],
        'senior' => ['rate' => '-3%', 'pitch' => '-4Hz', 'gain_db' => -0.2],
        'unknown' => ['rate' => '+0%', 'pitch' => '+0Hz', 'gain_db' => 0.0],
    ],

    // Cleanup settings - delete original files after dubbing to save storage
    'cleanup' => [
        // Delete original video and intermediate files after dubbing completes
        'delete_after_dubbing' => env('DELETE_AFTER_DUBBING', true),
    ],

    // Safety clamps (reduced to avoid ultrasound-like high pitches)
    'clamp' => [
        'gain_db_min' => -6.0,
        'gain_db_max' => 6.0,
        // rate in percent (string like +10%), clamp by numeric
        'rate_min' => -10,
        'rate_max' => 12,
        // pitch in Hz (string like +3Hz) - kept mild for natural sound
        'pitch_min' => -20,
        'pitch_max' => 20,
    ],
];
