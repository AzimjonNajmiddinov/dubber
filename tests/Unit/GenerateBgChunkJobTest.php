<?php

namespace Tests\Unit;

use App\Jobs\GenerateBgChunkJob;
use App\Support\DubSession;
use Illuminate\Support\Facades\Redis;
use ReflectionMethod;
use Tests\TestCase;

class GenerateBgChunkJobTest extends TestCase
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

    public function test_waiting_coverage_does_not_unpublish_already_verified_hls_chunk(): void
    {
        $sessionId = 'bg-monotonic-test';
        $aacDir = $this->tempDir();
        $rawFile = $this->tempFile("{$aacDir}/raw-0.ts", str_repeat('r', 256));
        $dubFile = $this->tempFile("{$aacDir}/bg-0.ts", str_repeat('d', 256));
        $session = [
            'aac_base_dir' => $aacDir,
            'total_bg_chunks' => 1,
            'hls_dub_start_time' => 0.0,
            'video_duration' => 30.0,
            'created_at' => now()->toIso8601String(),
        ];
        $bg = [
            'start' => 0.0,
            'end' => 30.0,
            'path' => $rawFile,
            'dub_ready' => true,
        ];

        Redis::shouldReceive('get')
            ->with(DubSession::speakableSegmentsKey($sessionId))
            ->once()
            ->andReturn(null);
        Redis::shouldReceive('get')
            ->with(DubSession::allSegmentsKey($sessionId))
            ->once()
            ->andReturn(null);
        Redis::shouldReceive('hget')
            ->with(DubSession::bgChunksKey($sessionId), '0')
            ->once()
            ->andReturn(json_encode($bg));
        Redis::shouldReceive('get')
            ->with(DubSession::key($sessionId))
            ->once()
            ->andReturn(json_encode($session));
        Redis::shouldReceive('hgetall')
            ->with(DubSession::bgChunksKey($sessionId))
            ->once()
            ->andReturn(['0' => json_encode($bg)]);
        Redis::shouldReceive('eval')
            ->once()
            ->andReturn(1);
        Redis::shouldReceive('hset')->never();

        $job = new GenerateBgChunkJob($sessionId, 0, 0.0, 30.0);
        $method = new ReflectionMethod($job, 'mix');
        $method->setAccessible(true);
        $method->invoke($job, $rawFile, $session);

        $this->assertFileExists($dubFile);
        $this->assertGreaterThan(10, filesize($dubFile));
    }

    public function test_mono_dialogue_chunks_do_not_keep_original_audio_bed(): void
    {
        $filter = $this->backgroundFilterForMix(channels: 1, expectedSpeech: 2);

        $this->assertStringContainsString('volume=0.0', $filter);
        $this->assertStringNotContainsString('volume=1.0', $filter);
    }

    public function test_remixed_chunk_clears_cached_partial_slices(): void
    {
        $aacDir = $this->tempDir();
        $sliceA = $this->tempFile("{$aacDir}/bg-2-from-2500.ts", str_repeat('s', 128));
        $sliceB = $this->tempFile("{$aacDir}/bg-2-from-5000.ts", str_repeat('s', 128));
        $otherChunkSlice = $this->tempFile("{$aacDir}/bg-3-from-2500.ts", str_repeat('o', 128));

        $job = new GenerateBgChunkJob('slice-clear-test', 2, 64.0, 96.0);
        $method = new ReflectionMethod($job, 'clearCachedSlices');
        $method->setAccessible(true);
        $method->invoke($job, $aacDir);

        $this->assertFileDoesNotExist($sliceA);
        $this->assertFileDoesNotExist($sliceB);
        $this->assertFileExists($otherChunkSlice);
    }

    public function test_stereo_dialogue_chunks_are_center_cancelled_and_suppressed(): void
    {
        $filter = $this->backgroundFilterForMix(channels: 2, expectedSpeech: 2);

        $this->assertStringContainsString('c0=c0-0.95*c1', $filter);
        $this->assertStringContainsString('volume=0.18', $filter);
        $this->assertStringNotContainsString('volume=1.0', $filter);
    }

    public function test_non_dialogue_chunks_keep_source_audio_bed(): void
    {
        $filter = $this->backgroundFilterForMix(channels: 1, expectedSpeech: 0);

        $this->assertStringContainsString('volume=1.0', $filter);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/instant-dub-bg-job-' . uniqid();
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

    private function backgroundFilterForMix(int $channels, int $expectedSpeech): string
    {
        $job = new GenerateBgChunkJob('bg-filter-test', 0, 0.0, 30.0);
        $method = new ReflectionMethod($job, 'backgroundFilterForMix');
        $method->setAccessible(true);

        return $method->invoke($job, $channels, $expectedSpeech);
    }
}
