<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstantDub extends Model
{
    protected $fillable = [
        'title', 'video_url', 'language', 'translate_from',
        'tts_driver', 'status', 'total_segments', 'aac_dir', 'session_id',
    ];

    public function segments(): HasMany
    {
        return $this->hasMany(InstantDubSegment::class)->orderBy('segment_index');
    }

    public function voiceMap(): HasMany
    {
        return $this->hasMany(InstantDubVoiceMap::class);
    }
}
