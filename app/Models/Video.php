<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Video extends Model
{
    protected $fillable = [
        'original_path',
        'audio_path',
        'dubbed_path',
        'status',
        'target_language',
        'vocals_path',
        'music_path',
        'final_audio_path',
        'lipsynced_path'
    ];

    public function speakers(): HasMany
    {
        return $this->hasMany(Speaker::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(VideoSegment::class);
    }
}

