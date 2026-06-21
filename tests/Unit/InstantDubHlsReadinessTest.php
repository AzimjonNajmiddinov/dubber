<?php

namespace Tests\Unit;

use App\Support\DubSession;
use App\Support\InstantDubHlsReadiness;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class InstantDubHlsReadinessTest extends TestCase
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

    public function test_chunk_with_speech_waits_for_tts_audio(): void
    {
        $sessionId = 'readiness-test';
        $session = ['total_segments' => 1];
        $speechPlan = [[
            'index' => 0,
            'start_time' => 10.0,
            'end_time' => 12.0,
            'text' => 'Hello',
            'speaker' => 'M1',
        ]];

        Redis::shouldReceive('get')
            ->with(DubSession::speakableSegmentsKey($sessionId))
            ->twice()
            ->andReturn(json_encode($speechPlan));

        Redis::shouldReceive('mget')
            ->with([DubSession::chunkKey($sessionId, 0)])
            ->once()
            ->andReturn([null]);

        $waiting = InstantDubHlsReadiness::chunkMixCoverage($sessionId, $session, 0.0, 30.0);
        $this->assertFalse($waiting['can_mix']);
        $this->assertSame(1, $waiting['expected_speech']);
        $this->assertSame(0, $waiting['ready_speech']);

        $ttsFile = $this->tempFile('tts-ready.mp3', str_repeat('a', 256));
        Redis::shouldReceive('mget')
            ->with([DubSession::chunkKey($sessionId, 0)])
            ->once()
            ->andReturn([json_encode([
                'index' => 0,
                'start_time' => 10.0,
                'end_time' => 12.0,
                'text' => 'Salom',
                'audio_path' => $ttsFile,
            ])]);

        $ready = InstantDubHlsReadiness::chunkMixCoverage($sessionId, $session, 0.0, 30.0);
        $this->assertTrue($ready['can_mix']);
        $this->assertSame(1, $ready['ready_speech']);
        $this->assertCount(1, $ready['ready_chunks']);
    }

    public function test_chunk_waits_when_segment_plan_is_missing(): void
    {
        $sessionId = 'missing-plan-test';

        Redis::shouldReceive('get')
            ->with(DubSession::speakableSegmentsKey($sessionId))
            ->once()
            ->andReturn(null);
        Redis::shouldReceive('get')
            ->with(DubSession::allSegmentsKey($sessionId))
            ->once()
            ->andReturn(null);

        $coverage = InstantDubHlsReadiness::chunkMixCoverage($sessionId, [], 0.0, 30.0);

        $this->assertFalse($coverage['can_mix']);
        $this->assertTrue($coverage['missing_segment_plan']);
    }

    public function test_ready_window_requires_verified_dub_flag_and_file(): void
    {
        $sessionId = 'ready-window-test';
        $aacDir = sys_get_temp_dir() . '/instant-dub-readiness-' . uniqid();
        @mkdir($aacDir, 0755, true);
        $this->dirs[] = $aacDir;
        $this->files[] = "{$aacDir}/bg-0.ts";
        file_put_contents("{$aacDir}/bg-0.ts", str_repeat('b', 128));

        $session = [
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
                '0' => json_encode(['start' => 0.0, 'end' => 30.0, 'dub_ready' => false]),
            ]);

        $notReady = InstantDubHlsReadiness::readyWindow($sessionId, $session, $aacDir);
        $this->assertFalse($notReady['ready']);
        $this->assertNull($notReady['last_ready_bg_idx']);

        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([
                '0' => json_encode(['start' => 0.0, 'end' => 30.0, 'dub_ready' => true]),
            ]);

        $ready = InstantDubHlsReadiness::readyWindow($sessionId, $session, $aacDir);
        $this->assertTrue($ready['ready']);
        $this->assertTrue($ready['complete']);
        $this->assertSame(0, $ready['last_ready_bg_idx']);
    }

    public function test_ready_window_ignores_stale_aac_dub_file_for_hls_ts_flow(): void
    {
        $sessionId = 'stale-aac-ready-window-test';
        $aacDir = sys_get_temp_dir() . '/instant-dub-readiness-' . uniqid();
        @mkdir($aacDir, 0755, true);
        $this->dirs[] = $aacDir;
        $this->files[] = "{$aacDir}/bg-0.aac";
        file_put_contents("{$aacDir}/bg-0.aac", str_repeat('x', 128));

        $session = [
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

        $notReady = InstantDubHlsReadiness::readyWindow($sessionId, $session, $aacDir);

        $this->assertFalse($notReady['ready']);
        $this->assertNull($notReady['last_ready_bg_idx']);
    }

    public function test_ready_window_requires_source_audio_before_dub_start(): void
    {
        $sessionId = 'source-before-dub-test';
        $aacDir = sys_get_temp_dir() . '/instant-dub-readiness-' . uniqid();
        @mkdir($aacDir, 0755, true);
        $this->dirs[] = $aacDir;
        $this->files[] = "{$aacDir}/bg-1.ts";
        file_put_contents("{$aacDir}/bg-1.ts", str_repeat('d', 128));

        $session = [
            'total_bg_chunks' => 2,
            'hls_dub_start_time' => 30.0,
            'aac_base_dir' => $aacDir,
            'video_duration' => 60.0,
            'created_at' => now()->toIso8601String(),
        ];

        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([
                '0' => json_encode(['start' => 0.0, 'end' => 30.0, 'planned' => true]),
                '1' => json_encode(['start' => 30.0, 'end' => 60.0, 'dub_ready' => true]),
            ]);

        $notReady = InstantDubHlsReadiness::readyWindow($sessionId, $session, $aacDir);
        $this->assertFalse($notReady['ready']);
        $this->assertFalse($notReady['source_ready']);

        $sourceFile = $this->tempFile('source-0.ts', str_repeat('s', 128));
        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([
                '0' => json_encode(['start' => 0.0, 'end' => 30.0, 'path' => $sourceFile]),
                '1' => json_encode(['start' => 30.0, 'end' => 60.0, 'path' => $sourceFile, 'dub_ready' => true]),
            ]);

        $ready = InstantDubHlsReadiness::readyWindow($sessionId, $session, $aacDir);
        $this->assertTrue($ready['ready']);
        $this->assertTrue($ready['source_ready']);
        $this->assertTrue($ready['complete']);
    }

    public function test_ready_window_scans_past_switch_threshold_to_detect_completion(): void
    {
        $sessionId = 'completion-window-test';
        $aacDir = sys_get_temp_dir() . '/instant-dub-readiness-' . uniqid();
        @mkdir($aacDir, 0755, true);
        $this->dirs[] = $aacDir;

        $chunks = [];
        for ($i = 0; $i < 5; $i++) {
            $this->files[] = "{$aacDir}/bg-{$i}.ts";
            file_put_contents("{$aacDir}/bg-{$i}.ts", str_repeat('c', 128));
            $chunks[(string) $i] = json_encode([
                'start' => $i * 30.0,
                'end' => ($i + 1) * 30.0,
                'dub_ready' => true,
            ]);
        }

        $session = [
            'total_bg_chunks' => 5,
            'hls_dub_start_time' => 0.0,
            'aac_base_dir' => $aacDir,
            'video_duration' => 150.0,
            'created_at' => now()->toIso8601String(),
        ];

        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn($chunks);

        $ready = InstantDubHlsReadiness::readyWindow($sessionId, $session, $aacDir);
        $this->assertTrue($ready['ready']);
        $this->assertTrue($ready['complete']);
        $this->assertSame(4, $ready['last_ready_bg_idx']);
        $this->assertEquals(150.0, $ready['continuous_until']);
    }

    public function test_long_hls_movie_waits_for_configured_post_intro_runway(): void
    {
        config(['dubber.instant_dub.min_hls_switch_runway' => 90.0]);

        $sessionId = 'long-hls-runway-switch-test';
        $aacDir = sys_get_temp_dir() . '/instant-dub-readiness-' . uniqid();
        @mkdir($aacDir, 0755, true);
        $this->dirs[] = $aacDir;
        $sourceFile = $this->tempFile('source-0.ts', str_repeat('s', 128));
        foreach ([1, 2, 3] as $idx) {
            $this->files[] = "{$aacDir}/bg-{$idx}.ts";
            file_put_contents("{$aacDir}/bg-{$idx}.ts", str_repeat('d', 128));
        }

        $session = [
            'total_bg_chunks' => 240,
            'hls_dub_start_time' => 30.0,
            'aac_base_dir' => $aacDir,
            'video_duration' => 7200.0,
            'created_at' => now()->subMinutes(20)->toIso8601String(),
        ];

        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([
                '0' => json_encode(['start' => 0.0, 'end' => 30.0, 'path' => $sourceFile]),
                '1' => json_encode(['start' => 30.0, 'end' => 60.0, 'path' => $sourceFile, 'dub_ready' => true]),
                '2' => json_encode(['start' => 60.0, 'end' => 90.0, 'planned' => true]),
            ]);

        $notReady = InstantDubHlsReadiness::readyWindow($sessionId, $session, $aacDir);

        $this->assertFalse($notReady['ready']);
        $this->assertSame(1, $notReady['last_ready_bg_idx']);
        $this->assertEquals(30.0, $notReady['ready_seconds']);
        $this->assertEquals(90.0, $notReady['required_seconds']);

        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn([
                '0' => json_encode(['start' => 0.0, 'end' => 30.0, 'path' => $sourceFile]),
                '1' => json_encode(['start' => 30.0, 'end' => 60.0, 'path' => $sourceFile, 'dub_ready' => true]),
                '2' => json_encode(['start' => 60.0, 'end' => 90.0, 'path' => $sourceFile, 'dub_ready' => true]),
                '3' => json_encode(['start' => 90.0, 'end' => 120.0, 'path' => $sourceFile, 'dub_ready' => true]),
                '4' => json_encode(['start' => 120.0, 'end' => 150.0, 'planned' => true]),
            ]);

        $ready = InstantDubHlsReadiness::readyWindow($sessionId, $session, $aacDir);
        $this->assertTrue($ready['ready']);
        $this->assertSame(3, $ready['last_ready_bg_idx']);
        $this->assertEquals(90.0, $ready['ready_seconds']);
        $this->assertEquals(90.0, $ready['required_seconds']);
        $this->assertEquals(120.0, $ready['continuous_until']);
    }

    private function tempFile(string $name, string $contents): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('dub-test-', true) . '-' . $name;
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
