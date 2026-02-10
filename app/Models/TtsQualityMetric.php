<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TtsQualityMetric extends Model
{
    protected $fillable = [
        'video_segment_id',
        'speaker_id',
        'duration_ratio',
        'rms_db',
        'pitch_hz',
        'tempo_applied',
        'was_trimmed',
    ];

    protected $casts = [
        'duration_ratio' => 'float',
        'rms_db' => 'float',
        'pitch_hz' => 'float',
        'tempo_applied' => 'float',
        'was_trimmed' => 'boolean',
    ];

    public function videoSegment(): BelongsTo
    {
        return $this->belongsTo(VideoSegment::class);
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class);
    }
}
