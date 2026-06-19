<?php

namespace Tests\Unit;

use App\Jobs\PrepareInstantDubJob;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class InstantDubSubtitleSelectionTest extends TestCase
{
    public function test_subtitle_selection_prefers_complete_track_over_broken_priority_language(): void
    {
        [$lang, $result] = $this->invoke('selectSubtitleResult', [
            [
                'ru' => ['cues' => 20, 'bytes' => 500, 'srt' => 'short'],
                'en' => ['cues' => 1000, 'bytes' => 20000, 'srt' => 'full'],
            ],
            ['ru', 'uz', 'en', 'tr'],
        ]);

        $this->assertSame('en', $lang);
        $this->assertSame(1000, $result['cues']);
    }

    public function test_subtitle_selection_keeps_priority_language_when_coverage_is_close(): void
    {
        [$lang, $result] = $this->invoke('selectSubtitleResult', [
            [
                'ru' => ['cues' => 850, 'bytes' => 18000, 'srt' => 'ru'],
                'en' => ['cues' => 1000, 'bytes' => 20000, 'srt' => 'en'],
            ],
            ['ru', 'uz', 'en', 'tr'],
        ]);

        $this->assertSame('ru', $lang);
        $this->assertSame(850, $result['cues']);
    }

    public function test_vtt_playlist_accepts_signed_segment_urls(): void
    {
        $uris = $this->invoke('subtitlePlaylistUris', [
            "#EXTM3U\n#EXTINF:10,\nsub-0001.vtt?token=abc\n#EXTINF:10,\nsub-0002.webvtt?token=def\n",
        ]);

        $this->assertSame(['sub-0001.vtt?token=abc', 'sub-0002.webvtt?token=def'], $uris);
    }

    public function test_direct_vtt_is_parsed_without_playlist(): void
    {
        [$srt, $cues] = $this->invoke('vttToSrt', [
            "WEBVTT\n\ncue-1\n00:01.000 --> 00:03.500 align:start\n<c>Hello</c> world\n\n00:00:04.000 --> 00:00:05.000\nSecond line\n",
        ]);

        $this->assertSame(2, $cues);
        $this->assertStringContainsString("00:00:01,000 --> 00:00:03,500\nHello world", $srt);
        $this->assertStringContainsString("00:00:04,000 --> 00:00:05,000\nSecond line", $srt);
    }

    public function test_hls_subtitle_fetch_handles_root_relative_playlists_and_segments(): void
    {
        Http::fake([
            'https://cdn.test/movie/master.m3u8?token=abc' => Http::response(
                "#EXTM3U\n#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subs\",LANGUAGE=\"en\",NAME=\"English\",URI=\"/subs/en.m3u8\"\n",
                200,
            ),
            'https://cdn.test/subs/en.m3u8?token=abc' => Http::response(
                "#EXTM3U\n#EXTINF:2,\n/vtt/en-0.vtt\n",
                200,
            ),
            'https://cdn.test/vtt/en-0.vtt?token=abc' => Http::response(
                "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\nHello from root\n",
                200,
            ),
        ]);

        $result = $this->invoke('fetchSubsFromHls', [
            'https://cdn.test/movie/master.m3u8?token=abc',
        ]);

        $this->assertSame('en', $result['language']);
        $this->assertStringContainsString('Hello from root', $result['srt']);
    }

    private function invoke(string $method, array $args): mixed
    {
        $job = new PrepareInstantDubJob('subtitle-test', 'https://example.com/master.m3u8', 'uz', 'auto', '');
        $reflection = new ReflectionMethod($job, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($job, $args);
    }
}
