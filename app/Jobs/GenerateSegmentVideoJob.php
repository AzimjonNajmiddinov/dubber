<?php

namespace App\Jobs;

use App\Models\VideoSegment;
use App\Services\SegmentVideoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateSegmentVideoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $segmentId
    ) {}

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return 'segment_video_' . $this->segmentId;
    }

    /**
     * Execute the job.
     */
    public function handle(SegmentVideoService $service): void
    {
        $segment = VideoSegment::find($this->segmentId);

        if (!$segment) {
            Log::warning('GenerateSegmentVideoJob: Segment not found', [
                'segment_id' => $this->segmentId,
            ]);
            return;
        }

        // Check if already exists
        if ($service->isSegmentReady($segment)) {
            Log::info('GenerateSegmentVideoJob: Segment already ready', [
                'segment_id' => $this->segmentId,
            ]);
            return;
        }

        Log::info('GenerateSegmentVideoJob: Generating segment', [
            'segment_id' => $this->segmentId,
            'video_id' => $segment->video_id,
        ]);

        $result = $service->getOrGenerateSegment($segment);

        if ($result) {
            Log::info('GenerateSegmentVideoJob: Segment generated successfully', [
                'segment_id' => $this->segmentId,
                'path' => $result,
            ]);
        } else {
            Log::error('GenerateSegmentVideoJob: Failed to generate segment', [
                'segment_id' => $this->segmentId,
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateSegmentVideoJob: Job failed', [
            'segment_id' => $this->segmentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
