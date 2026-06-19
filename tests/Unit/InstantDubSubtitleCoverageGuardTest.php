<?php

namespace Tests\Unit;

use App\Jobs\DownloadOriginalAudioJob;
use ReflectionMethod;
use Tests\TestCase;

class InstantDubSubtitleCoverageGuardTest extends TestCase
{
    public function test_long_hls_with_tiny_subtitle_tail_is_rejected(): void
    {
        $job = new DownloadOriginalAudioJob('coverage-test', 'https://cdn.test/movie/master.m3u8');

        $this->assertTrue($this->looksBroken($job, 61.0, 7200.0));
        $this->assertFalse($this->looksBroken($job, 6500.0, 7200.0));
        $this->assertFalse($this->looksBroken($job, 61.0, 300.0));
    }

    private function looksBroken(DownloadOriginalAudioJob $job, float $subtitleEnd, float $duration): bool
    {
        $method = new ReflectionMethod($job, 'subtitleCoverageLooksBroken');
        $method->setAccessible(true);

        return $method->invoke($job, $subtitleEnd, $duration);
    }
}
