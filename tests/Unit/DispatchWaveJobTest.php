<?php

namespace Tests\Unit;

use App\Jobs\DispatchWaveJob;
use App\Jobs\TranslateInstantDubBatchJob;
use App\Support\DubSession;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class DispatchWaveJobTest extends TestCase
{
    public function test_wave_job_dispatches_only_first_translation_batch_without_incrementing_wave_counter(): void
    {
        Queue::fake();

        $sessionId = 'wave-job-test';
        $session = [
            'status' => 'processing',
            'title' => 'Long Movie',
            'video_url' => 'https://youtube.com/watch?v=test',
        ];
        $segments = [];
        for ($i = 0; $i < 16; $i++) {
            $segments[] = [
                'start' => 300.0 + ($i * 4.0),
                'end' => 302.0 + ($i * 4.0),
                'text' => "Line {$i}",
                'speaker' => 'M1',
            ];
        }

        Redis::shouldReceive('get')
            ->with(DubSession::key($sessionId))
            ->twice()
            ->andReturn(json_encode($session));

        Redis::shouldReceive('get')
            ->with(DubSession::waveKey($sessionId, 1))
            ->once()
            ->andReturn(json_encode($segments));

        Redis::shouldReceive('setex')
            ->with(DubSession::waveProgressKey($sessionId, 1), DubSession::TTL, json_encode(['total' => 16, 'ready' => 0]))
            ->once();

        Redis::shouldReceive('setex')
            ->with("instant-dub:{$sessionId}:w1:batch:0", DubSession::TTL, Mockery::type('string'))
            ->once();
        Redis::shouldReceive('setex')
            ->with("instant-dub:{$sessionId}:w1:batch:1", DubSession::TTL, Mockery::type('string'))
            ->once();
        Redis::shouldReceive('setex')
            ->with("instant-dub:{$sessionId}:w1:batches-remaining", DubSession::TTL, 2)
            ->once();

        Redis::shouldReceive('del')
            ->with(DubSession::waveKey($sessionId, 1))
            ->once();

        Redis::shouldReceive('incr')->never();

        (new DispatchWaveJob($sessionId, 1, 'uz', 'en', 80))->handle();

        Queue::assertPushed(TranslateInstantDubBatchJob::class, function (TranslateInstantDubBatchJob $job) {
            return $job->batchIndex === 0
                && $job->totalBatches === 2
                && $job->segmentOffset === 80
                && $job->waveIndex === 1;
        });

        Queue::assertPushed(TranslateInstantDubBatchJob::class, 1);
    }

    public function test_wave_job_recovers_translate_from_from_session_when_argument_is_blank(): void
    {
        Queue::fake();

        $sessionId = 'wave-job-session-source-test';
        $session = [
            'status' => 'processing',
            'title' => 'Long Movie',
            'language' => 'uz',
            'translate_from' => 'en',
            'video_url' => 'https://youtube.com/watch?v=test',
        ];
        $segments = [];
        for ($i = 0; $i < 2; $i++) {
            $segments[] = [
                'start' => 300.0 + ($i * 4.0),
                'end' => 302.0 + ($i * 4.0),
                'text' => "Line {$i}",
                'speaker' => 'M1',
            ];
        }

        Redis::shouldReceive('get')
            ->with(DubSession::key($sessionId))
            ->twice()
            ->andReturn(json_encode($session));

        Redis::shouldReceive('get')
            ->with(DubSession::waveKey($sessionId, 1))
            ->once()
            ->andReturn(json_encode($segments));

        Redis::shouldReceive('setex')
            ->with(DubSession::waveProgressKey($sessionId, 1), DubSession::TTL, json_encode(['total' => 2, 'ready' => 0]))
            ->once();

        Redis::shouldReceive('setex')
            ->with("instant-dub:{$sessionId}:w1:batch:0", DubSession::TTL, Mockery::type('string'))
            ->once();
        Redis::shouldReceive('setex')
            ->with("instant-dub:{$sessionId}:w1:batches-remaining", DubSession::TTL, 1)
            ->once();

        Redis::shouldReceive('del')
            ->with(DubSession::waveKey($sessionId, 1))
            ->once();

        (new DispatchWaveJob($sessionId, 1, 'uz', '', 80))->handle();

        Queue::assertPushed(TranslateInstantDubBatchJob::class, function (TranslateInstantDubBatchJob $job) {
            return $job->translateFrom === 'en'
                && $job->language === 'uz'
                && $job->segmentOffset === 80
                && $job->waveIndex === 1;
        });

        Queue::assertPushed(TranslateInstantDubBatchJob::class, 1);
    }
}
