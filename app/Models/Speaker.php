<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'emotion_confidence'
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}


