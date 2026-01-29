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
    'emotion_presets' => [
        // rate: percentage string, pitch: Hz string, gain_db: float
        'neutral' => ['rate' => '+0%', 'pitch' => '+0Hz', 'gain_db' => 0.0],

        'happy' => ['rate' => '+6%', 'pitch' => '+2Hz', 'gain_db' => 0.5],
        'excited' => ['rate' => '+10%', 'pitch' => '+3Hz', 'gain_db' => 1.0],

        'sad' => ['rate' => '-6%', 'pitch' => '-2Hz', 'gain_db' => -0.5],
        'angry' => ['rate' => '+8%', 'pitch' => '+1Hz', 'gain_db' => 0.5],

        // common alternates in some emotion models
        'fear' => ['rate' => '+8%', 'pitch' => '+2Hz', 'gain_db' => 0.0],
        'surprise' => ['rate' => '+10%', 'pitch' => '+3Hz', 'gain_db' => 0.5],
        'disgust' => ['rate' => '+2%', 'pitch' => '-1Hz', 'gain_db' => 0.0],
    ],

    // Age-group adjustments (optional). Applied on top of emotion.
    'age_presets' => [
        'child' => ['rate' => '+10%', 'pitch' => '+5Hz', 'gain_db' => -0.5],
        'young_adult' => ['rate' => '+2%', 'pitch' => '+1Hz', 'gain_db' => 0.0],
        'adult' => ['rate' => '+0%', 'pitch' => '+0Hz', 'gain_db' => 0.0],
        'senior' => ['rate' => '-4%', 'pitch' => '-1Hz', 'gain_db' => -0.5],
        'unknown' => ['rate' => '+0%', 'pitch' => '+0Hz', 'gain_db' => 0.0],
    ],

    // Safety clamps (so values don't get silly)
    'clamp' => [
        'gain_db_min' => -6.0,
        'gain_db_max' => 6.0,
        // rate in percent (string like +10%), clamp by numeric
        'rate_min' => -15,
        'rate_max' => 15,
        // pitch in Hz (string like +3Hz)
        'pitch_min' => -8,
        'pitch_max' => 8,
    ],
];
