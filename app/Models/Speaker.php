<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Speaker extends Model
{
    protected $fillable = [
        'video_id',
        'external_key',
        'gender',
        'tts_voice',
        'label',
        'age_group',
        'emotion',
        'gender_confidence',
        'pitch_median_hz',
        'tts_gain_db',
        'tts_rate',
        'tts_pitch',
        'emotion_confidence',
        // TTS driver settings
        'tts_driver',           // edge, elevenlabs, openai, xtts
        'elevenlabs_voice_id',  // ElevenLabs voice ID (cloned or preset)
        'xtts_voice_id',        // XTTS cloned voice ID
        'voice_sample_path',    // Path to extracted voice sample
        'voice_cloned',         // Whether voice has been cloned
    ];

    protected $casts = [
        'voice_cloned' => 'boolean',
        'gender_confidence' => 'float',
        'emotion_confidence' => 'float',
        'pitch_median_hz' => 'float',
        'tts_gain_db' => 'float',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(VideoSegment::class);
    }

    /**
     * Get the effective TTS driver for this speaker.
     */
    public function getEffectiveTtsDriver(): string
    {
        return $this->tts_driver ?? config('dubber.tts.default', 'edge');
    }

    /**
     * Check if this speaker has a cloned voice available.
     */
    public function hasClonedVoice(): bool
    {
        return $this->voice_cloned &&
            ($this->xtts_voice_id || $this->elevenlabs_voice_id);
    }

    /**
     * Get the best voice ID for TTS based on driver.
     */
    public function getVoiceIdForDriver(string $driver): ?string
    {
        return match ($driver) {
            'elevenlabs' => $this->elevenlabs_voice_id,
            'xtts' => $this->xtts_voice_id,
            'edge', 'openai' => $this->tts_voice,
            default => null,
        };
    }
}


