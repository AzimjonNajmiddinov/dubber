<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstantDubSegment extends Model
{
    protected $fillable = [
        'instant_dub_id', 'segment_index', 'speaker',
        'start_time', 'end_time', 'slot_end',
        'source_text', 'translated_text',
        'aac_path', 'aac_duration', 'needs_retts', 'approved',
    ];

    protected $casts = [
        'needs_retts' => 'boolean',
        'approved' => 'boolean',
    ];

    public function instantDub(): BelongsTo
    {
        return $this->belongsTo(InstantDub::class);
    }
}
