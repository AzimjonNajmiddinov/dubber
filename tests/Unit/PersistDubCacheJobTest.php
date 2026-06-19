<?php

namespace Tests\Unit;

use App\Jobs\PersistDubCacheJob;
use ReflectionMethod;
use Tests\TestCase;

class PersistDubCacheJobTest extends TestCase
{
    public function test_cleanup_delay_covers_long_hls_movie_playback(): void
    {
        $delay = $this->cleanupDelay([
            'video_duration' => 7200.0,
            'total_bg_chunks' => 240,
        ]);

        $this->assertSame(360, $delay);
    }

    public function test_cleanup_delay_has_safe_floor_when_duration_unknown(): void
    {
        $this->assertSame(360, $this->cleanupDelay([]));
    }

    public function test_cleanup_delay_caps_at_one_day(): void
    {
        $delay = $this->cleanupDelay([
            'video_duration' => 200000.0,
        ]);

        $this->assertSame(1440, $delay);
    }

    private function cleanupDelay(array $session): int
    {
        $method = new ReflectionMethod(PersistDubCacheJob::class, 'cleanupDelayMinutes');
        $method->setAccessible(true);

        return $method->invoke(new PersistDubCacheJob('cleanup-test'), $session);
    }
}
