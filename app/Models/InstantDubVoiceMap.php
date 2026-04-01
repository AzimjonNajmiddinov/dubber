<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstantDubVoiceMap extends Model
{
    protected $table = 'instant_dub_voice_map';

    protected $fillable = ['instant_dub_id', 'speaker_tag', 'voice_config'];

    protected $casts = ['voice_config' => 'array'];

    public function instantDub(): BelongsTo
    {
        return $this->belongsTo(InstantDub::class);
    }
}
