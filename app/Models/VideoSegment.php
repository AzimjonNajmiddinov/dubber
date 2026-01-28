<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoSegment extends Model
{
    protected $fillable = [
        'video_id',
        'speaker_id',
        'start_time',
        'end_time',
        'text',
        'gender',
        'translated_text',
        'tts_audio_path',
        'emotion',
        'tts_gain_db',
        'tts_lufs'
    ];

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class);
    }
}

