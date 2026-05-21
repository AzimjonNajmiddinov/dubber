<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

/**
 * Centralises all Redis key names, TTL, and session CRUD for the instant-dub pipeline.
 * Import this class instead of writing inline "instant-dub:{$id}:*" strings.
 */
class DubSession
{
    const TTL = 86400; // 24 hours

    // ── Key builders ──────────────────────────────────────────────────────────

    public static function key(string $id): string
    {
        return "instant-dub:{$id}";
    }

    public static function chunkKey(string $id, int $index): string
    {
        return "instant-dub:{$id}:chunk:{$index}";
    }

    public static function bgChunksKey(string $id): string
    {
        return "instant-dub:{$id}:bgchunks";
    }

    public static function voicesKey(string $id): string
    {
        return "instant-dub:{$id}:voices";
    }

    public static function audioSegmentsKey(string $id): string
    {
        return "instant-dub:{$id}:audio-segments";
    }

    public static function allSegmentsKey(string $id): string
    {
        return "instant-dub:{$id}:all-segments";
    }

    public static function fullDialogueKey(string $id): string
    {
        return "instant-dub:{$id}:full-dialogue";
    }

    public static function batchKey(string $id, int $index): string
    {
        return "instant-dub:{$id}:batch:{$index}";
    }

    public static function elevenLabsVoicesKey(string $id): string
    {
        return "instant-dub:{$id}:elevenlabs-voices";
    }

    public static function rewrittenMasterKey(string $id): string
    {
        return "instant-dub:{$id}:rewritten-master";
    }

    public static function masterPlaylistKey(string $id): string
    {
        return "instant-dub:{$id}:master-playlist";
    }

    public static function vttCacheKey(string $id): string
    {
        return "instant-dub:{$id}:vtt-cache";
    }

    public static function characterContextKey(string $id): string
    {
        return "instant-dub:{$id}:character-context";
    }

    public static function bgGenLockKey(string $id, int $chunkIndex): string
    {
        return "bg-gen:{$id}:{$chunkIndex}";
    }

    public static function bgLockKey(string $id): string
    {
        return "instant-dub:{$id}:bg-lock";
    }

    public static function audioPollKey(string $id): string
    {
        return "instant-dub:{$id}:audio-poll-count";
    }

    public static function persistLockKey(string $id): string
    {
        return "instant-dub:{$id}:persist-lock";
    }

    public static function voicesLockKey(string $id): string
    {
        return "instant-dub:{$id}:voices-lock";
    }

    // ── Session CRUD ──────────────────────────────────────────────────────────

    public static function get(string $id): ?array
    {
        $json = Redis::get(static::key($id));
        return $json ? json_decode($json, true) : null;
    }

    public static function save(string $id, array $session): void
    {
        Redis::setex(static::key($id), static::TTL, json_encode($session));
    }

    /** Merge $data into existing session atomically (Lua prevents GET+SET races). */
    public static function patch(string $id, array $data): void
    {
        $ttl   = static::TTL;
        $patch = json_encode($data);
        $lua   = <<<LUA
            local raw = redis.call('GET', KEYS[1])
            if not raw then return 0 end
            local session = cjson.decode(raw)
            local patch = cjson.decode(ARGV[1])
            for k, v in pairs(patch) do
                session[k] = v
            end
            redis.call('SETEX', KEYS[1], {$ttl}, cjson.encode(session))
            return 1
        LUA;
        Redis::eval($lua, 1, static::key($id), $patch);
    }

    public static function isStopped(string $id): bool
    {
        $session = static::get($id);
        return ($session['status'] ?? '') === 'stopped';
    }

    // ── Domain helpers ────────────────────────────────────────────────────────

    /** Infer gender from speaker tag (M→male, F→female, C→child). */
    public static function genderFromTag(string $tag): string
    {
        return \App\Services\VoiceMapBuilder::genderFromTag($tag);
    }

    public static function aacDir(string $id, ?array $session = null): string
    {
        $session ??= static::get($id);
        return $session['aac_base_dir'] ?? storage_path("app/instant-dub/{$id}/aac");
    }

    public static function audioDir(string $id): string
    {
        return storage_path("app/instant-dub/{$id}/audio");
    }

    /** Returns all Redis keys to delete when a session is stopped. */
    public static function allDeleteKeys(string $id, int $totalSegments, int $totalBatches): array
    {
        $keys = [
            static::rewrittenMasterKey($id),
            static::masterPlaylistKey($id),
            static::vttCacheKey($id),
            static::voicesKey($id),
            static::fullDialogueKey($id),
            static::allSegmentsKey($id),
            static::characterContextKey($id),
            static::bgChunksKey($id),
        ];
        for ($i = 0; $i < $totalBatches; $i++) {
            $keys[] = static::batchKey($id, $i);
        }
        for ($i = 0; $i < $totalSegments; $i++) {
            $keys[] = static::chunkKey($id, $i);
        }
        return $keys;
    }
}
