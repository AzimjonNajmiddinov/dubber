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
        'tts_driver',           // edge, hybrid_uzbek
        'openvoice_speaker_key', // OpenVoice speaker embedding key
        'voice_sample_path',    // Path to extracted voice sample
        'voice_cloned',         // Whether voice has been cloned
        // Per-speaker voice DNA — makes each speaker sound unique
        'voice_profile',        // deep, bright, bass, thin, warm, default
        'speaking_rate_factor', // 0.85-1.15 — base speed multiplier
        'expressiveness',       // 0.2-1.0 — how much emotion DSP to apply
        'openvoice_tau',        // 0.0-1.0 — voice cloning strength
        'voice_sample_duration', // seconds of usable voice sample
    ];

    protected $casts = [
        'voice_cloned' => 'boolean',
        'gender_confidence' => 'float',
        'emotion_confidence' => 'float',
        'pitch_median_hz' => 'float',
        'tts_gain_db' => 'float',
        'speaking_rate_factor' => 'float',
        'expressiveness' => 'float',
        'openvoice_tau' => 'float',
        'voice_sample_duration' => 'float',
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
        return $this->voice_cloned && $this->openvoice_speaker_key;
    }

    /**
     * Get the best voice ID for TTS based on driver.
     */
    public function getVoiceIdForDriver(string $driver): ?string
    {
        return match ($driver) {
            'hybrid_uzbek' => $this->openvoice_speaker_key,
            'edge' => $this->tts_voice,
            default => null,
        };
    }
}


