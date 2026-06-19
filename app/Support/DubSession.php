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
    const HLS_MASTER_REWRITE_VERSION = 'v4';

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

    public static function speakableSegmentsKey(string $id): string
    {
        return "instant-dub:{$id}:speakable-segments";
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

    public static function rewrittenMasterCacheKey(string $id, bool $playable): string
    {
        return static::rewrittenMasterKey($id)
            . ':' . static::HLS_MASTER_REWRITE_VERSION
            . ($playable ? ':playable' : ':waiting');
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

    // ── Wave keys (batch pipeline for large movies) ───────────────────────────

    public static function waveKey(string $id, int $waveIndex): string
    {
        return "instant-dub:{$id}:wave:{$waveIndex}";
    }

    public static function waveProgressKey(string $id, int $waveIndex): string
    {
        return "instant-dub:{$id}:wave-progress:{$waveIndex}";
    }

    public static function wavesDispatchedKey(string $id): string
    {
        return "instant-dub:{$id}:waves-dispatched";
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

    /** Atomically merge metadata into one background-audio chunk. */
    public static function mergeBgChunk(string $id, int $index, array $data): void
    {
        $ttl = static::TTL;
        $patch = json_encode($data);
        $lua = <<<LUA
            local raw = redis.call('HGET', KEYS[1], ARGV[1])
            local chunk = {}
            if raw then chunk = cjson.decode(raw) end
            local patch = cjson.decode(ARGV[2])
            for k, v in pairs(patch) do
                chunk[k] = v
            end
            redis.call('HSET', KEYS[1], ARGV[1], cjson.encode(chunk))
            redis.call('EXPIRE', KEYS[1], {$ttl})
            return 1
        LUA;

        Redis::eval($lua, 1, static::bgChunksKey($id), (string) $index, $patch);
    }

    /** Merge waiting metadata only while the chunk has not already published a dub. */
    public static function mergeBgChunkIfNotDubReady(string $id, int $index, array $data): void
    {
        $ttl = static::TTL;
        $patch = json_encode($data);
        $lua = <<<LUA
            local raw = redis.call('HGET', KEYS[1], ARGV[1])
            if not raw then return 0 end
            local chunk = cjson.decode(raw)
            if chunk['dub_ready'] then return 0 end
            local patch = cjson.decode(ARGV[2])
            for k, v in pairs(patch) do
                chunk[k] = v
            end
            redis.call('HSET', KEYS[1], ARGV[1], cjson.encode(chunk))
            redis.call('EXPIRE', KEYS[1], {$ttl})
            return 1
        LUA;

        Redis::eval($lua, 1, static::bgChunksKey($id), (string) $index, $patch);
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
            static::rewrittenMasterKey($id) . ':playable',
            static::rewrittenMasterKey($id) . ':waiting',
            static::rewrittenMasterCacheKey($id, true),
            static::rewrittenMasterCacheKey($id, false),
            static::masterPlaylistKey($id),
            static::vttCacheKey($id),
            static::voicesKey($id),
            static::fullDialogueKey($id),
            static::allSegmentsKey($id),
            static::speakableSegmentsKey($id),
            static::characterContextKey($id),
            static::bgChunksKey($id),
            static::wavesDispatchedKey($id),
        ];
        for ($i = 0; $i < $totalBatches; $i++) {
            $keys[] = static::batchKey($id, $i);
        }
        for ($i = 0; $i < $totalSegments; $i++) {
            $keys[] = static::chunkKey($id, $i);
        }
        // Clean up wave keys (generous upper bound — max ~50 waves for a 4h movie)
        $totalWaves = (int) ceil($totalSegments / 85); // ~85 segments per 5min wave
        for ($i = 0; $i < max($totalWaves, 50); $i++) {
            $keys[] = static::waveKey($id, $i);
            $keys[] = static::waveProgressKey($id, $i);
        }
        return $keys;
    }
}
