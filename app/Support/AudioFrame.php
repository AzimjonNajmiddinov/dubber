<?php

namespace App\Support;

/**
 * AAC frame-aligned duration calculator.
 *
 * AAC encodes audio in 1024-sample frames at 44100 Hz.
 * All segment durations must align to frame boundaries so HLS decoders
 * do not accumulate drift across segments.
 */
class AudioFrame
{
    const SAMPLE_RATE = 44100;
    const FRAME_SIZE  = 1024;

    public static function alignedDuration(float $start, float $end): float
    {
        $startFrames = (int) round($start * self::SAMPLE_RATE / self::FRAME_SIZE);
        $endFrames   = (int) round($end   * self::SAMPLE_RATE / self::FRAME_SIZE);
        return max(1, $endFrames - $startFrames) * self::FRAME_SIZE / self::SAMPLE_RATE;
    }
}
