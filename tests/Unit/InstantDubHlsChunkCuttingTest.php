<?php

namespace Tests\Unit;

use App\Jobs\DownloadAudioChunkJob;
use ReflectionMethod;
use Tests\TestCase;

class InstantDubHlsChunkCuttingTest extends TestCase
{
    public function test_hls_chunk_selection_keeps_precise_seek_offset(): void
    {
        $job = new DownloadAudioChunkJob('chunk-cut-test', 0, 7.0, 37.0);
        $segments = [];
        for ($i = 0; $i < 7; $i++) {
            $segments[] = [
                'url' => "https://cdn.test/s{$i}.ts",
                'duration' => 6.0,
            ];
        }

        $selection = $this->invoke($job, 'selectHlsSegmentsForRange', [$segments]);

        $this->assertSame(6, count($selection['segments']));
        $this->assertSame('https://cdn.test/s1.ts', $selection['segments'][0]['url']);
        $this->assertEquals(1.0, $selection['seek_offset']);
    }

    public function test_hls_chunk_playlist_preserves_durations_key_and_map(): void
    {
        $job = new DownloadAudioChunkJob('chunk-cut-test', 0, 0.0, 12.0);
        $playlist = $this->invoke($job, 'buildHlsChunkPlaylist', [[
            [
                'url' => 'https://cdn.test/init-seg-0.m4s',
                'duration' => 5.5,
                'key' => '#EXT-X-KEY:METHOD=AES-128,URI="https://cdn.test/key.bin"',
                'map' => '#EXT-X-MAP:URI="https://cdn.test/init.mp4"',
            ],
            [
                'url' => 'https://cdn.test/init-seg-1.m4s',
                'duration' => 6.25,
                'key' => '#EXT-X-KEY:METHOD=AES-128,URI="https://cdn.test/key.bin"',
                'map' => '#EXT-X-MAP:URI="https://cdn.test/init.mp4"',
            ],
        ]]);

        $this->assertStringContainsString("#EXT-X-TARGETDURATION:7\n", $playlist);
        $this->assertSame(1, substr_count($playlist, '#EXT-X-KEY:'));
        $this->assertSame(1, substr_count($playlist, '#EXT-X-MAP:'));
        $this->assertStringContainsString("#EXTINF:5.5,\nhttps://cdn.test/init-seg-0.m4s", $playlist);
        $this->assertStringContainsString("#EXTINF:6.25,\nhttps://cdn.test/init-seg-1.m4s", $playlist);
    }

    private function invoke(object $job, string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod($job, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($job, $args);
    }
}
