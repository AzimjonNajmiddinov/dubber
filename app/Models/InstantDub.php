<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstantDub extends Model
{
    protected $fillable = [
        'title', 'video_url', 'video_content_key', 'language', 'translate_from',
        'tts_driver', 'status', 'total_segments', 'aac_dir', 'session_id',
    ];

    /**
     * Extract a stable content identifier from a signed/tokenized video URL.
     * Returns a 32-char hex hash embedded in the path, or falls back to the
     * normalized URL (without query string) if no hash found.
     */
    public static function extractContentKey(string $url): string
    {
        // YouTube: extract video ID from ?v= or youtu.be/
        if (preg_match('/(?:youtube\.com\/watch[^#]*[?&]v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
            return 'yt:' . $m[1];
        }
        $path = strtok($url, '?');
        // Match the last 32-char hex segment in the URL path (content hash)
        if (preg_match('/\/([a-f0-9]{32})(?:\/|$)/i', $path, $m)) {
            return strtolower($m[1]);
        }
        return $path;
    }

    public function segments(): HasMany
    {
        return $this->hasMany(InstantDubSegment::class)->orderBy('segment_index');
    }

    public function voiceMap(): HasMany
    {
        return $this->hasMany(InstantDubVoiceMap::class);
    }
}
