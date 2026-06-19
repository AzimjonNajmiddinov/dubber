<?php

namespace Tests\Unit;

use App\Jobs\DownloadOriginalAudioJob;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class InstantDubHlsAudioPlaylistParsingTest extends TestCase
{
    public function test_audio_playlist_parser_falls_back_to_muxed_variant_playlist(): void
    {
        Http::fake([
            'https://cdn.test/movie/master.m3u8?token=abc' => Http::response(
                "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000000\n/variant/main.m3u8\n",
                200,
            ),
            'https://cdn.test/variant/main.m3u8?token=abc' => Http::response(
                "#EXTM3U\n#EXTINF:6.0,\nseg-0.ts\n#EXTINF:6.5,\nseg-1.ts\n",
                200,
            ),
        ]);

        $segments = $this->parseAudioPlaylist('https://cdn.test/movie/master.m3u8?token=abc');

        $this->assertCount(2, $segments);
        $this->assertSame('https://cdn.test/variant/seg-0.ts?token=abc', $segments[0]['url']);
        $this->assertSame(6.0, $segments[0]['duration']);
        $this->assertSame('https://cdn.test/variant/seg-1.ts?token=abc', $segments[1]['url']);
    }

    public function test_hls_url_resolver_handles_root_relative_paths(): void
    {
        $job = new DownloadOriginalAudioJob('audio-playlist-test', 'https://cdn.test/movie/master.m3u8');
        $method = new ReflectionMethod($job, 'resolveHlsUrl');
        $method->setAccessible(true);

        $this->assertSame(
            'https://cdn.test/root/audio.m3u8?token=abc',
            $method->invoke($job, 'https://cdn.test/movie/nested/', '/root/audio.m3u8', 'token=abc'),
        );
    }

    public function test_audio_playlist_parser_accepts_direct_media_playlist(): void
    {
        Http::fake([
            'https://cdn.test/movie/audio.m3u8?token=abc' => Http::response(
                "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"keys/k.bin\"\n#EXT-X-MAP:URI=\"init.mp4\"\n#EXTINF:10.0,\na-0.aac\n#EXTINF:8.0,\na-1.aac\n",
                200,
            ),
        ]);

        $segments = $this->parseAudioPlaylist('https://cdn.test/movie/audio.m3u8?token=abc');

        $this->assertCount(2, $segments);
        $this->assertSame('https://cdn.test/movie/a-0.aac?token=abc', $segments[0]['url']);
        $this->assertSame(10.0, $segments[0]['duration']);
        $this->assertSame(0.0, $segments[0]['start']);
        $this->assertSame(10.0, $segments[0]['end']);
        $this->assertSame('#EXT-X-KEY:METHOD=AES-128,URI="https://cdn.test/movie/keys/k.bin?token=abc"', $segments[0]['key']);
        $this->assertSame('#EXT-X-MAP:URI="https://cdn.test/movie/init.mp4?token=abc"', $segments[0]['map']);
    }

    private function parseAudioPlaylist(string $url): array
    {
        $job = new DownloadOriginalAudioJob('audio-playlist-test', $url);
        $method = new ReflectionMethod($job, 'parseAudioPlaylist');
        $method->setAccessible(true);

        return $method->invoke($job);
    }
}
