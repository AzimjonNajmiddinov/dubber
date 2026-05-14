<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

/**
 * Centralises all Redis key names, TTL, and session CRUD for the instant-dub pipeline.
 * Import this class instead of writing inline "instant-dub:{$id}:*" strings.
 */
class DubSession
{
    const TTL = 50400; // 14 hours

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

    /** Merge $data into existing session without overwriting other keys. */
    public static function patch(string $id, array $data): void
    {
        $current = Redis::get(static::key($id));
        if (!$current) return;
        Redis::setex(static::key($id), static::TTL, json_encode(
            array_merge(json_decode($current, true), $data)
        ));
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
        if (str_starts_with($tag, 'F')) return 'female';
        if (str_starts_with($tag, 'C')) return 'child';
        return 'male';
    }

    public static function aacDir(string $id, ?array $session = null): string
    {
        $session ??= static::get($id);
        return $session['aac_base_dir'] ?? storage_path("app/instant-dub/{$id}/aac");
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
