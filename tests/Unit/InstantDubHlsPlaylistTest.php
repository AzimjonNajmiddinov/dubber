<?php

namespace Tests\Unit;

use App\Http\Controllers\InstantDubController;
use App\Support\DubSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class InstantDubHlsPlaylistTest extends TestCase
{
    private array $files = [];
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        foreach (array_reverse($this->dirs) as $dir) {
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function test_audio_playlist_does_not_advertise_unverified_post_intro_dub(): void
    {
        $sessionId = 'playlist-unverified-test';
        $aacDir = $this->tempDir();
        $session = [
            'hls_dub_start_time' => 45.0,
            'total_bg_chunks' => 3,
            'aac_base_dir' => $aacDir,
            'status' => 'processing',
        ];

        Redis::shouldReceive('get')
            ->with(DubSession::key($sessionId))
            ->once()
            ->andReturn(json_encode($session));

        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([
                '0' => json_encode(['start' => 0.0, 'end' => 30.0, 'path' => '/tmp/raw-0.aac']),
                '1' => json_encode(['start' => 30.0, 'end' => 60.0, 'path' => '/tmp/raw-1.aac', 'dub_ready' => false]),
                '2' => json_encode(['start' => 60.0, 'end' => 90.0, 'path' => '/tmp/raw-2.aac', 'dub_ready' => true]),
            ]);

        $content = (new InstantDubController())->hlsAudioPlaylist($sessionId)->getContent();

        $this->assertStringContainsString('dub-segment/source-bg-0.ts', $content);
        $this->assertStringContainsString('dub-segment/source-bg-1-to-15000.ts', $content);
        $this->assertDoesNotMatchRegularExpression('/^dub-segment\/bg-0\.ts$/m', $content);
        $this->assertStringNotContainsString('dub-segment/bg-1-from-15000.ts', $content);
        $this->assertStringNotContainsString('dub-segment/bg-2.ts', $content);
        $this->assertStringNotContainsString('#EXT-X-ENDLIST', $content);
    }

    public function test_audio_playlist_advertises_contiguous_verified_dub_only(): void
    {
        $sessionId = 'playlist-verified-test';
        $aacDir = $this->tempDir();
        $raw0 = $this->tempFile("{$aacDir}/raw-0.ts", str_repeat('0', 128));
        $raw1 = $this->tempFile("{$aacDir}/raw-1.ts", str_repeat('1', 128));
        $raw2 = $this->tempFile("{$aacDir}/raw-2.ts", str_repeat('2', 128));
        $this->tempFile("{$aacDir}/bg-1.ts", str_repeat('a', 128));
        $this->tempFile("{$aacDir}/bg-2.ts", str_repeat('b', 128));
        $session = [
            'hls_dub_start_time' => 45.0,
            'total_bg_chunks' => 3,
            'aac_base_dir' => $aacDir,
            'status' => 'processing',
        ];

        Redis::shouldReceive('get')
            ->with(DubSession::key($sessionId))
            ->once()
            ->andReturn(json_encode($session));

        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([
                '0' => json_encode(['start' => 0.0, 'end' => 30.0, 'path' => $raw0]),
                '1' => json_encode(['start' => 30.0, 'end' => 60.0, 'path' => $raw1, 'dub_ready' => true]),
                '2' => json_encode(['start' => 60.0, 'end' => 90.0, 'path' => $raw2, 'dub_ready' => true]),
            ]);

        $content = (new InstantDubController())->hlsAudioPlaylist($sessionId)->getContent();

        $this->assertStringContainsString('dub-segment/source-bg-0.ts', $content);
        $this->assertStringContainsString('dub-segment/source-bg-1-to-15000.ts', $content);
        $this->assertDoesNotMatchRegularExpression('/^dub-segment\/bg-0\.ts$/m', $content);
        $this->assertStringContainsString('dub-segment/bg-1-from-15000.ts', $content);
        $this->assertStringContainsString('dub-segment/bg-2.ts', $content);
        $this->assertSame(3, substr_count($content, '#EXT-X-DISCONTINUITY'));
        $this->assertStringContainsString('#EXT-X-ENDLIST', $content);
    }

    public function test_audio_playlist_does_not_endlist_until_pre_dub_source_is_ready(): void
    {
        $sessionId = 'playlist-missing-source-test';
        $aacDir = $this->tempDir();
        $this->tempFile("{$aacDir}/bg-1.ts", str_repeat('a', 128));
        $session = [
            'hls_dub_start_time' => 30.0,
            'total_bg_chunks' => 2,
            'aac_base_dir' => $aacDir,
            'status' => 'processing',
        ];

        Redis::shouldReceive('get')
            ->with(DubSession::key($sessionId))
            ->once()
            ->andReturn(json_encode($session));

        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([
                '0' => json_encode(['start' => 0.0, 'end' => 30.0, 'planned' => true]),
                '1' => json_encode(['start' => 30.0, 'end' => 60.0, 'dub_ready' => true]),
            ]);

        $content = (new InstantDubController())->hlsAudioPlaylist($sessionId)->getContent();

        $this->assertStringContainsString('dub-segment/bg-1.ts', $content);
        $this->assertStringNotContainsString('#EXT-X-ENDLIST', $content);
        $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:EVENT', $content);
    }

    public function test_bg_segment_endpoint_never_serves_source_audio_for_unverified_dub_url(): void
    {
        $sessionId = 'bg-endpoint-dub-only-test';
        $aacDir = $this->tempDir();
        $raw0 = $this->tempFile("{$aacDir}/raw-0.ts", str_repeat('r', 128));
        $session = [
            'hls_dub_start_time' => 60.0,
            'total_bg_chunks' => 2,
            'aac_base_dir' => $aacDir,
            'status' => 'processing',
        ];

        Redis::shouldReceive('get')
            ->with(DubSession::key($sessionId))
            ->once()
            ->andReturn(json_encode($session));
        Redis::shouldReceive('hget')
            ->with(DubSession::bgChunksKey($sessionId), '0')
            ->once()
            ->andReturn(json_encode(['start' => 0.0, 'end' => 30.0, 'path' => $raw0]));

        $response = (new InstantDubController())->hlsBgSegment($sessionId, 0);

        $this->assertNotInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('video/mp2t', $response->headers->get('Content-Type'));
    }

    public function test_source_segment_endpoint_does_not_serve_original_after_dub_start(): void
    {
        $sessionId = 'source-endpoint-intro-only-test';
        $aacDir = $this->tempDir();
        $raw2 = $this->tempFile("{$aacDir}/raw-2.ts", str_repeat('r', 128));
        $session = [
            'hls_dub_start_time' => 30.0,
            'total_bg_chunks' => 3,
            'aac_base_dir' => $aacDir,
            'status' => 'processing',
        ];

        Redis::shouldReceive('get')
            ->with(DubSession::key($sessionId))
            ->once()
            ->andReturn(json_encode($session));
        Redis::shouldReceive('hget')
            ->with(DubSession::bgChunksKey($sessionId), '2')
            ->once()
            ->andReturn(json_encode(['start' => 60.0, 'end' => 90.0, 'path' => $raw2]));

        $response = (new InstantDubController())->hlsBgSourceSegment($sessionId, 2);

        $this->assertNotInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('video/mp2t', $response->headers->get('Content-Type'));
    }

    public function test_cached_hls_slice_refreshes_when_source_chunk_is_newer(): void
    {
        $aacDir = $this->tempDir();
        $sliceFile = $this->tempFile("{$aacDir}/bg-2-from-2500.ts", str_repeat('s', 128));
        $sourceFile = $this->tempFile("{$aacDir}/bg-2.ts", str_repeat('d', 128));

        touch($sliceFile, time() - 20);
        touch($sourceFile, time());

        $method = new ReflectionMethod(InstantDubController::class, 'hlsSliceNeedsRefresh');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(new InstantDubController(), $sliceFile, $sourceFile));

        touch($sliceFile, time() + 20);

        $this->assertFalse($method->invoke(new InstantDubController(), $sliceFile, $sourceFile));
    }

    public function test_low_bitrate_cached_hls_slice_refreshes_when_speech_is_expected(): void
    {
        $aacDir = $this->tempDir();
        $sliceFile = $this->tempFile("{$aacDir}/bg-2-from-2500.ts", str_repeat('s', 350));
        $sourceFile = $this->tempFile("{$aacDir}/bg-2.ts", str_repeat('d', 1000));

        touch($sourceFile, time() - 20);
        touch($sliceFile, time());

        $method = new ReflectionMethod(InstantDubController::class, 'hlsSliceNeedsRefresh');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            new InstantDubController(),
            $sliceFile,
            $sourceFile,
            30.0,
            25.0,
            true,
        ));

        file_put_contents($sliceFile, str_repeat('s', 700));

        $this->assertFalse($method->invoke(
            new InstantDubController(),
            $sliceFile,
            $sourceFile,
            30.0,
            25.0,
            true,
        ));
    }

    public function test_audio_playlist_versions_dub_segment_urls(): void
    {
        $sessionId = 'playlist-versioned-dub-test';
        $aacDir = $this->tempDir();
        $raw0 = $this->tempFile("{$aacDir}/raw-0.ts", str_repeat('0', 128));
        $raw1 = $this->tempFile("{$aacDir}/raw-1.ts", str_repeat('1', 128));
        $this->tempFile("{$aacDir}/bg-1.ts", str_repeat('d', 128));
        $session = [
            'hls_dub_start_time' => 45.0,
            'total_bg_chunks' => 2,
            'aac_base_dir' => $aacDir,
            'status' => 'processing',
        ];

        Redis::shouldReceive('get')
            ->with(DubSession::key($sessionId))
            ->once()
            ->andReturn(json_encode($session));

        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([
                '0' => json_encode(['start' => 0.0, 'end' => 30.0, 'path' => $raw0]),
                '1' => json_encode(['start' => 30.0, 'end' => 60.0, 'path' => $raw1, 'dub_ready' => true]),
            ]);

        $content = (new InstantDubController())->hlsAudioPlaylist($sessionId)->getContent();

        $this->assertMatchesRegularExpression('/dub-segment\/bg-1-from-15000\.ts\?v=\d+/', $content);
    }

    public function test_verified_hls_format_without_current_runway_is_not_playable(): void
    {
        $sessionId = 'verified-switch-test';
        $session = [
            'playable' => true,
            'hls_switch_verified' => true,
            'hls_verified_format' => 'ts',
            'total_bg_chunks' => 3,
            'hls_dub_start_time' => 0.0,
            'hls_ready_seconds' => 0.0,
            'hls_last_ready_bg_idx' => null,
            'video_duration' => 7200.0,
            'created_at' => now()->subMinutes(30)->toIso8601String(),
        ];

        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([]);

        Redis::shouldReceive('eval')
            ->once()
            ->andReturn(1);

        $state = $this->resolvePlayableState($sessionId, $session);

        $this->assertFalse($state['playable']);
        $this->assertTrue($state['session']['hls_switch_verified']);
        $this->assertSame('ts', $state['session']['hls_verified_format']);
    }

    public function test_stale_verified_state_without_ts_format_must_reprove_readiness(): void
    {
        $sessionId = 'stale-verified-format-test';
        $aacDir = $this->tempDir();
        $this->tempFile("{$aacDir}/bg-0.aac", str_repeat('a', 128));
        $session = [
            'video_url' => 'https://cdn.test/movie/master.m3u8',
            'playable' => true,
            'hls_switch_verified' => true,
            'total_bg_chunks' => 1,
            'hls_dub_start_time' => 0.0,
            'aac_base_dir' => $aacDir,
            'video_duration' => 30.0,
            'created_at' => now()->toIso8601String(),
        ];

        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([
                '0' => json_encode(['start' => 0.0, 'end' => 30.0, 'dub_ready' => true]),
            ]);
        Redis::shouldReceive('eval')
            ->once()
            ->andReturn(1);

        $state = $this->resolvePlayableState($sessionId, $session);

        $this->assertFalse($state['playable']);
        $this->assertFalse($state['session']['hls_switch_verified']);
    }

    public function test_hls_session_with_no_bg_plan_is_not_playable(): void
    {
        $sessionId = 'hls-no-bg-plan-test';
        $session = [
            'video_url' => 'https://cdn.test/movie/master.m3u8',
            'playable' => true,
            'hls_switch_verified' => true,
            'total_bg_chunks' => 0,
        ];

        Redis::shouldReceive('eval')
            ->once()
            ->andReturn(1);

        $state = $this->resolvePlayableState($sessionId, $session);

        $this->assertFalse($state['playable']);
        $this->assertFalse($state['session']['hls_switch_verified']);
    }

    public function test_controller_hls_url_resolver_handles_root_relative_paths(): void
    {
        $method = new ReflectionMethod(InstantDubController::class, 'resolveHlsUrl');
        $method->setAccessible(true);

        $url = $method->invoke(
            new InstantDubController(),
            'https://cdn.test/movie/nested/',
            '/root/variant.m3u8',
            'token=abc',
        );

        $this->assertSame('https://cdn.test/root/variant.m3u8?token=abc', $url);
    }

    public function test_waiting_master_wraps_direct_media_playlist_without_dub_audio_track(): void
    {
        $sessionId = 'direct-media-master-test';
        $videoUrl = 'https://cdn.test/movie/media.m3u8?token=abc';
        $session = [
            'video_url' => $videoUrl,
            'video_base_url' => 'https://cdn.test/movie/',
            'video_query' => 'token=abc',
            'language' => 'uz',
            'playable' => false,
            'total_bg_chunks' => 0,
        ];

        Http::fake([
            $videoUrl => Http::response("#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6.0,\nseg-0.ts\n", 200),
        ]);

        Redis::shouldReceive('get')
            ->with(DubSession::key($sessionId))
            ->once()
            ->andReturn(json_encode($session));
        Redis::shouldReceive('get')
            ->with(DubSession::rewrittenMasterCacheKey($sessionId, false))
            ->once()
            ->andReturn(null);
        Redis::shouldReceive('get')
            ->with(DubSession::masterPlaylistKey($sessionId))
            ->once()
            ->andReturn(null);
        Redis::shouldReceive('setex')
            ->twice()
            ->andReturn(true);

        $content = (new InstantDubController())->hlsMaster($sessionId)->getContent();

        $this->assertStringContainsString('#EXT-X-STREAM-INF:BANDWIDTH=3000000,AUDIO="audio",SUBTITLES="subs"', $content);
        $this->assertStringContainsString('NAME="Original",DEFAULT=YES,AUTOSELECT=YES', $content);
        $this->assertStringNotContainsString('URI="dub-audio.m3u8"', $content);
        $this->assertStringContainsString($videoUrl, $content);
    }

    public function test_playable_synthetic_master_includes_dub_audio_track(): void
    {
        $method = new ReflectionMethod(InstantDubController::class, 'syntheticMasterForMediaPlaylist');
        $method->setAccessible(true);

        $content = $method->invoke(
            new InstantDubController(),
            'https://cdn.test/movie/media.m3u8',
            'audio',
            'subs',
            "O'zbek dublyaj",
            "O'zbek",
            'uz',
            true,
        );

        $this->assertStringContainsString('URI="dub-audio.m3u8",DEFAULT=YES,AUTOSELECT=YES', $content);
        $this->assertStringContainsString('NAME="Original",DEFAULT=NO,AUTOSELECT=NO', $content);
    }

    public function test_playable_master_injects_dub_into_every_audio_group(): void
    {
        $sessionId = 'multi-audio-group-master-test';
        $videoUrl = 'https://cdn.test/movie/master.m3u8?token=abc';
        $aacDir = $this->tempDir();
        $this->tempFile("{$aacDir}/bg-0.ts", str_repeat('0', 128));
        $this->tempFile("{$aacDir}/bg-1.ts", str_repeat('1', 128));
        $session = [
            'video_url' => $videoUrl,
            'video_base_url' => 'https://cdn.test/movie/',
            'video_query' => 'token=abc',
            'language' => 'uz',
            'playable' => true,
            'hls_switch_verified' => true,
            'hls_verified_format' => 'ts',
            'total_bg_chunks' => 2,
            'hls_dub_start_time' => 0.0,
            'hls_ready_seconds' => 60.0,
            'hls_last_ready_bg_idx' => 1,
            'aac_base_dir' => $aacDir,
            'video_duration' => 60.0,
            'created_at' => now()->toIso8601String(),
        ];

        Http::fake([
            $videoUrl => Http::response(implode("\n", [
                '#EXTM3U',
                '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="aud-low",NAME="Original Low",DEFAULT=YES,AUTOSELECT=YES,URI="low/audio.m3u8"',
                '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="aud-high",NAME="Original High",DEFAULT=YES,AUTOSELECT=YES,URI="high/audio.m3u8"',
                '#EXT-X-STREAM-INF:BANDWIDTH=1000000,AUDIO="aud-low"',
                'low/prog.m3u8',
                '#EXT-X-STREAM-INF:BANDWIDTH=3000000,AUDIO="aud-high"',
                'high/prog.m3u8',
            ]), 200),
        ]);

        Redis::shouldReceive('get')
            ->with(DubSession::key($sessionId))
            ->once()
            ->andReturn(json_encode($session));
        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([
                '0' => json_encode(['start' => 0.0, 'end' => 30.0, 'dub_ready' => true]),
                '1' => json_encode(['start' => 30.0, 'end' => 60.0, 'dub_ready' => true]),
            ]);
        Redis::shouldReceive('get')
            ->with(DubSession::rewrittenMasterCacheKey($sessionId, true))
            ->once()
            ->andReturn(null);
        Redis::shouldReceive('get')
            ->with(DubSession::masterPlaylistKey($sessionId))
            ->once()
            ->andReturn(null);
        Redis::shouldReceive('setex')
            ->twice()
            ->andReturn(true);

        $content = (new InstantDubController())->hlsMaster($sessionId)->getContent();

        $this->assertSame(2, substr_count($content, 'URI="dub-audio.m3u8"'));
        $this->assertStringContainsString('GROUP-ID="aud-low",NAME="O\'zbek dublyaj"', $content);
        $this->assertStringContainsString('GROUP-ID="aud-high",NAME="O\'zbek dublyaj"', $content);
        $this->assertStringContainsString('NAME="Original Low",DEFAULT=NO,AUTOSELECT=NO', $content);
        $this->assertStringContainsString('NAME="Original High",DEFAULT=NO,AUTOSELECT=NO', $content);
    }

    public function test_verified_format_without_current_runway_does_not_expose_dub_in_master(): void
    {
        $sessionId = 'stale-runway-master-test';
        $videoUrl = 'https://cdn.test/movie/master.m3u8?token=abc';
        $session = [
            'video_url' => $videoUrl,
            'video_base_url' => 'https://cdn.test/movie/',
            'video_query' => 'token=abc',
            'language' => 'uz',
            'playable' => true,
            'hls_switch_verified' => true,
            'hls_verified_format' => 'ts',
            'total_bg_chunks' => 4,
            'hls_dub_start_time' => 0.0,
            'hls_ready_seconds' => 0.0,
            'hls_last_ready_bg_idx' => null,
            'video_duration' => 7200.0,
            'created_at' => now()->subMinutes(30)->toIso8601String(),
        ];

        Http::fake([
            $videoUrl => Http::response(implode("\n", [
                '#EXTM3U',
                '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="Original",DEFAULT=YES,AUTOSELECT=YES,URI="audio.m3u8"',
                '#EXT-X-STREAM-INF:BANDWIDTH=1000000,AUDIO="audio"',
                'main.m3u8',
            ]), 200),
        ]);

        Redis::shouldReceive('get')
            ->with(DubSession::key($sessionId))
            ->once()
            ->andReturn(json_encode($session));
        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([]);
        Redis::shouldReceive('eval')
            ->once()
            ->andReturn(1);
        Redis::shouldReceive('get')
            ->with(DubSession::rewrittenMasterCacheKey($sessionId, false))
            ->once()
            ->andReturn(null);
        Redis::shouldReceive('get')
            ->with(DubSession::masterPlaylistKey($sessionId))
            ->once()
            ->andReturn(null);
        Redis::shouldReceive('setex')
            ->twice()
            ->andReturn(true);

        $content = (new InstantDubController())->hlsMaster($sessionId)->getContent();

        $this->assertStringNotContainsString('URI="dub-audio.m3u8"', $content);
        $this->assertStringContainsString('NAME="Original",DEFAULT=YES,AUTOSELECT=YES', $content);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/instant-dub-playlist-' . uniqid();
        @mkdir($dir, 0755, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    private function tempFile(string $path, string $contents): string
    {
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    private function resolvePlayableState(string $sessionId, array $session): array
    {
        $method = new ReflectionMethod(InstantDubController::class, 'resolveHlsPlayableState');
        $method->setAccessible(true);

        return $method->invoke(new InstantDubController(), $sessionId, $session);
    }
}
