<?php

namespace Tests\Unit;

use App\Jobs\PrepareInstantDubJob;
use ReflectionMethod;
use Tests\TestCase;

class PrepareInstantDubJobTest extends TestCase
{
    public function test_initial_translation_wave_claims_keep_long_movies_ahead(): void
    {
        config(['dubber.instant_dub.initial_wave_lookahead' => 4]);

        $job = new PrepareInstantDubJob('session-test', 'https://cdn.test/movie.m3u8', 'uz', 'en', '');
        $method = new ReflectionMethod($job, 'initialTranslationWaveClaims');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke($job, 1));
        $this->assertSame(3, $method->invoke($job, 3));
        $this->assertSame(4, $method->invoke($job, 12));
    }
}
