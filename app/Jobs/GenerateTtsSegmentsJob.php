<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoSegment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Traits\DetectsEnglish;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateTtsSegmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use DetectsEnglish;

    public int $timeout = 1800;
    public int $tries = 3;

    /**
     * Exponential backoff between retries (seconds).
     */
    public array $backoff = [30, 60, 120];

    public function __construct(public int $videoId) {}

    public function handle(): void
    {
        $lock = Cache::lock("video:{$this->videoId}:tts", 1800);
        if (!$lock->get()) {
            return;
        }

        try {
            /** @var Video $video */
            $video = Video::query()->findOrFail($this->videoId);

            $segments = VideoSegment::query()
                ->with('speaker')
                ->where('video_id', $video->id)
                ->whereNotNull('translated_text')
                ->where('translated_text', '!=', '')
                ->orderBy('start_time')
                ->get();

            if ($segments->isEmpty()) {
                throw new \RuntimeException("No translated segments found for video {$video->id}");
            }

            $outDirRel = "audio/tts/{$video->id}";
            $outDirAbs = Storage::disk('local')->path($outDirRel);
            @mkdir($outDirAbs, 0777, true);

            // Loudness targets for dialog
            $lufsTarget = -16.0;
            $tpTarget   = -1.5;
            $lraTarget  = 11.0;

            foreach ($segments as $seg) {
                $speaker = $seg->speaker;

                $voice = (string)($speaker?->tts_voice ?: 'uz-UZ-SardorNeural');

                // Base settings from DB (or defaults)
                $rate  = (string)($speaker?->tts_rate  ?: '+0%');
                $pitch = (string)($speaker?->tts_pitch ?: '+0Hz');

                // Speaker gain from DB (float)
                $baseGainDb = is_numeric($speaker?->tts_gain_db) ? (float)$speaker->tts_gain_db : 0.0;

                // Emotion adjustments (light)
                $emotion = strtolower((string)($seg->emotion ?: ($speaker?->emotion ?: 'neutral')));
                [$emoRateAdj, $emoPitchAdjHz, $emoGainAdjDb] = $this->emotionMap($emotion);

                $finalRate   = $this->mergeRate($rate, $emoRateAdj);
                $finalPitch  = $this->mergePitchHz($pitch, $emoPitchAdjHz);
                $finalGainDb = $baseGainDb + $emoGainAdjDb;

                // Calculate slot duration for timing fit
                $slotDuration = (float)$seg->end_time - (float)$seg->start_time;

                // Estimate needed speedup based on text length (Uzbek ~6-8 chars/sec at normal rate)
                $textLen = mb_strlen(trim((string)$seg->translated_text));
                $estimatedDuration = $textLen / 7.0; // rough chars-per-second estimate

                if ($slotDuration > 0 && $estimatedDuration > $slotDuration) {
                    // Need to speed up - calculate rate adjustment
                    $speedupFactor = $estimatedDuration / $slotDuration;
                    $rateBoost = (int)round(($speedupFactor - 1) * 100);
                    $rateBoost = min($rateBoost, 40); // cap at +40% for quality
                    $finalRate = $this->mergeRate($finalRate, '+' . $rateBoost . '%');
                }

                // Clamp to safe ranges for Edge-TTS (expanded for emotional expression)
                $finalRate   = $this->clampRate($finalRate, -20, +60);
                $finalPitch  = $this->clampPitchHz($finalPitch, -50, +80);
                $finalGainDb = $this->clampFloat($finalGainDb, -4.0, +5.0);

                $text = trim((string)$seg->translated_text);
                if ($text === '') {
                    continue;
                }

                // Guard: avoid accidental English TTS
                if ($this->looksLikeEnglish($text)) {
                    Log::error('TTS blocked: translated_text looks English', [
                        'video_id'   => $video->id,
                        'segment_id' => $seg->id,
                        'sample'     => mb_substr($text, 0, 180),
                    ]);
                    throw new \RuntimeException("Translated text looks English for segment {$seg->id}. Fix TranslateAudioJob output.");
                }

                // Output paths
                $rawMp3Abs = "{$outDirAbs}/seg_{$seg->id}.raw.mp3";
                $normAbs   = "{$outDirAbs}/seg_{$seg->id}.wav";

                @unlink($rawMp3Abs);
                @unlink($normAbs);

                Log::info('TTS generating', [
                    'video_id'     => $video->id,
                    'segment_id'   => $seg->id,
                    'voice'        => $voice,
                    'rate'         => $finalRate,
                    'pitch'        => $finalPitch,
                    'gain_db'      => $finalGainDb,
                    'emotion'      => $emotion,
                    'text_sample'  => mb_substr($text, 0, 140),
                ]);

                // 1) Edge-TTS -> MP3
                $tmpTxtAbs = "/tmp/tts_{$video->id}_{$seg->id}_" . Str::random(8) . ".txt";
                file_put_contents($tmpTxtAbs, $text);

                $out1 = [];
                $cmdTts = sprintf(
                // -f = file input for text (avoids shell quoting edge cases)
                // use --rate= and --pitch= forms (stable parsing)
                    'edge-tts -f %s --voice %s --rate=%s --pitch=%s --write-media %s 2>&1',
                    escapeshellarg($tmpTxtAbs),
                    escapeshellarg($voice),
                    escapeshellarg($finalRate),
                    escapeshellarg($finalPitch),
                    escapeshellarg($rawMp3Abs)
                );

                exec($cmdTts, $out1, $code1);
                @unlink($tmpTxtAbs);

                if ($code1 !== 0 || !file_exists($rawMp3Abs) || filesize($rawMp3Abs) < 500) {
                    Log::error('TTS failed', [
                        'video_id'   => $video->id,
                        'segment_id' => $seg->id,
                        'exit_code'  => $code1,
                        'cmd'        => $cmdTts,
                        'stderr'     => implode("\n", array_slice($out1 ?? [], -80)),
                        'mp3_exists' => file_exists($rawMp3Abs),
                        'mp3_size'   => file_exists($rawMp3Abs) ? filesize($rawMp3Abs) : null,
                    ]);
                    throw new \RuntimeException("TTS failed for segment {$seg->id}");
                }

                // 2) First pass: probe TTS duration to check if time-stretching needed
                $rawDuration = $this->probeAudioDuration($rawMp3Abs);
                $atempoFilter = '';

                if ($rawDuration > 0 && $slotDuration > 0 && $rawDuration > $slotDuration * 1.05) {
                    // TTS is more than 5% longer than slot - need to time-stretch
                    $speedupRatio = $rawDuration / $slotDuration;
                    // atempo range is 0.5 to 2.0, chain multiple for larger ratios
                    $atempoFilter = $this->buildAtempoChain($speedupRatio);

                    Log::info('TTS time-stretching required', [
                        'video_id' => $video->id,
                        'segment_id' => $seg->id,
                        'tts_duration' => $rawDuration,
                        'slot_duration' => $slotDuration,
                        'speedup_ratio' => $speedupRatio,
                        'atempo_filter' => $atempoFilter,
                    ]);
                }

                // 3) Normalize/convert to 48kHz WAV with optional time-stretch
                // Get emotion-specific audio processing
                $emotionFilters = $this->getEmotionAudioFilters($emotion);

                $filter = sprintf(
                // Ensure stable sample rate for everything downstream
                    'aresample=48000,' .
                    'aformat=sample_fmts=fltp:channel_layouts=stereo,' .
                    // Apply time-stretch if needed (before other filters for better quality)
                    '%s' .
                    // dialog cleanup
                    'highpass=f=80,' .
                    'lowpass=f=10000,' .
                    // Emotion-specific audio processing (EQ, compression, effects)
                    '%s' .
                    // per-speaker gain
                    'volume=%sdB,' .
                    // loudness normalization
                    'loudnorm=I=%s:TP=%s:LRA=%s:print_format=summary,' .
                    // force final resample (belt & suspenders)
                    'aresample=48000',
                    $atempoFilter ? $atempoFilter . ',' : '',
                    $emotionFilters,
                    $this->fmtDb($finalGainDb),
                    $lufsTarget,
                    $tpTarget,
                    $lraTarget
                );

                $out2 = [];
                $cmdNorm = sprintf(
                // -ar 48000 is important: without it, ffmpeg may pick unexpected SR
                    'ffmpeg -y -hide_banner -loglevel error -i %s -vn -af %s -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
                    escapeshellarg($rawMp3Abs),
                    escapeshellarg($filter),
                    escapeshellarg($normAbs)
                );

                exec($cmdNorm, $out2, $code2);

                if ($code2 !== 0 || !file_exists($normAbs) || filesize($normAbs) < 5000) {
                    Log::error('FFmpeg normalize failed', [
                        'video_id'   => $video->id,
                        'segment_id' => $seg->id,
                        'exit_code'  => $code2,
                        'cmd'        => $cmdNorm,
                        'stderr'     => implode("\n", array_slice($out2 ?? [], -160)),
                        'wav_exists' => file_exists($normAbs),
                        'wav_size'   => file_exists($normAbs) ? filesize($normAbs) : null,
                    ]);
                    throw new \RuntimeException("Normalize failed for segment {$seg->id}");
                }

                // Optional: parse loudnorm output
                $parsed = $this->parseLoudnormSummary($out2 ?? []);
                $outI = $parsed['output_i'] ?? null;

                // Optional: probe duration + SR (debug)
                $probe = $this->probeAudio($normAbs);

                Log::info('TTS ready', [
                    'video_id'     => $video->id,
                    'segment_id'   => $seg->id,
                    'wav_rel'      => "{$outDirRel}/seg_{$seg->id}.wav",
                    'wav_size'     => @filesize($normAbs) ?: null,
                    'wav_duration' => $probe['duration'] ?? null,
                    'wav_sr'       => $probe['sr'] ?? null,
                    'lufs_out'     => $outI,
                ]);

                $seg->update([
                    'tts_audio_path' => "{$outDirRel}/seg_{$seg->id}.wav",
                    'tts_gain_db'    => $finalGainDb,
                    'tts_lufs'       => $outI,
                ]);

                @unlink($rawMp3Abs);
            }

            $video->update(['status' => 'tts_generated']);
            MixDubbedAudioJob::dispatch($video->id);

        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Map emotion to TTS parameters - CLEAR and PRONOUNCED adjustments
     * Returns [rate adjustment, pitch Hz adjustment, gain dB adjustment]
     */
    private function emotionMap(string $emotion): array
    {
        $rate = '+0%';
        $pitchHz = 0;
        $gainDb = 0.0;

        switch ($emotion) {
            case 'happy':
                // Upbeat, energetic - faster pace, higher pitch, slightly louder
                $rate = '+12%';  $pitchHz = 25;  $gainDb = 1.0;  break;
            case 'excited':
                // Very energetic - fast, high pitch, loud
                $rate = '+18%';  $pitchHz = 35;  $gainDb = 1.5;  break;
            case 'sad':
                // Slow, low energy - slower pace, lower pitch, quieter
                $rate = '-15%';  $pitchHz = -20;  $gainDb = -1.5; break;
            case 'angry':
                // Intense, aggressive - faster, lower pitch (growl), louder
                $rate = '+15%';  $pitchHz = -15;  $gainDb = 2.0;  break;
            case 'frustration':
                // Tense, strained - slightly faster, lower pitch, louder
                $rate = '+10%';  $pitchHz = -10;  $gainDb = 1.5;  break;
            case 'fear':
                // Trembling, urgent - faster, higher pitch (panic), slightly quieter
                $rate = '+20%';  $pitchHz = 30;   $gainDb = 0.5;  break;
            case 'surprise':
                // Sudden, exclamation - fast burst, high pitch, loud
                $rate = '+15%';  $pitchHz = 40;  $gainDb = 1.5;  break;
            default:
                // neutral - no adjustments
                break;
        }

        return [$rate, $pitchHz, $gainDb];
    }

    private function mergeRate(string $base, string $adj): string
    {
        $b = $this->parseSignedNumber($base);
        $a = $this->parseSignedNumber($adj);
        $sum = $b + $a;
        $sign = $sum >= 0 ? '+' : '';
        return $sign . (string)intval(round($sum)) . '%';
    }

    private function mergePitchHz(string $base, int $adjHz): string
    {
        $b = (int)round($this->parseSignedNumber($base));
        $sum = $b + $adjHz;
        $sign = $sum >= 0 ? '+' : '';
        return $sign . (string)$sum . 'Hz';
    }

    private function parseSignedNumber(string $s): float
    {
        $s = trim(strtolower($s));
        $s = str_replace(['%','hz'], '', $s);
        $s = preg_replace('/[^0-9\.\-\+]/', '', $s);

        if ($s === '' || $s === '+' || $s === '-') return 0.0;
        return (float)$s;
    }

    private function fmtDb(float $db): string
    {
        $db = round($db, 2);
        $sign = $db >= 0 ? '+' : '';
        return $sign . $db;
    }

    private function clampRate(string $rate, int $minPct, int $maxPct): string
    {
        $v = (int)round($this->parseSignedNumber($rate));
        $v = max($minPct, min($maxPct, $v));
        return ($v >= 0 ? '+' : '') . $v . '%';
    }

    private function clampPitchHz(string $pitch, int $minHz, int $maxHz): string
    {
        $v = (int)round($this->parseSignedNumber($pitch));
        $v = max($minHz, min($maxHz, $v));
        return ($v >= 0 ? '+' : '') . $v . 'Hz';
    }

    private function clampFloat(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    private function parseLoudnormSummary(array $lines): array
    {
        $text = implode("\n", $lines);
        $out = [];

        if (preg_match('/Output Integrated:\s*([\-0-9\.]+)\s*LUFS/i', $text, $m)) {
            $out['output_i'] = (float)$m[1];
        }
        if (preg_match('/Input Integrated:\s*([\-0-9\.]+)\s*LUFS/i', $text, $m)) {
            $out['input_i'] = (float)$m[1];
        }

        return $out;
    }

    /**
     * Probe SR + duration for debugging.
     */
    private function probeAudio(string $absPath): array
    {
        if (!file_exists($absPath)) return [];

        $cmd = sprintf(
            'ffprobe -hide_banner -loglevel error -select_streams a:0 ' .
            '-show_entries stream=sample_rate -show_entries format=duration ' .
            '-of default=nw=1:nk=1 %s 2>/dev/null',
            escapeshellarg($absPath)
        );

        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code !== 0) return [];

        // Output order is stream.sample_rate then format.duration (because of two show_entries)
        $sr = isset($out[0]) && is_numeric(trim($out[0])) ? (int)trim($out[0]) : null;
        $dur = isset($out[1]) && is_numeric(trim($out[1])) ? (float)trim($out[1]) : null;

        return ['sr' => $sr, 'duration' => $dur];
    }

    /**
     * Probe audio duration only.
     */
    private function probeAudioDuration(string $absPath): float
    {
        if (!file_exists($absPath)) return 0.0;

        $cmd = sprintf(
            'ffprobe -hide_banner -loglevel error -show_entries format=duration -of default=nw=1:nk=1 %s 2>/dev/null',
            escapeshellarg($absPath)
        );

        $out = @shell_exec($cmd);
        if (!is_string($out)) return 0.0;

        $dur = (float)trim($out);
        return $dur > 0 ? $dur : 0.0;
    }

    /**
     * Build atempo filter chain for speedup ratios.
     * atempo filter only supports 0.5 to 2.0, so chain multiple for larger ratios.
     */
    private function buildAtempoChain(float $ratio): string
    {
        if ($ratio <= 1.0) return '';

        // Cap maximum speedup at 3x to preserve quality
        $ratio = min($ratio, 3.0);

        $filters = [];
        $remaining = $ratio;

        while ($remaining > 1.0) {
            $step = min($remaining, 2.0);
            $filters[] = 'atempo=' . number_format($step, 4, '.', '');
            $remaining = $remaining / $step;

            // Safety: max 3 atempo filters
            if (count($filters) >= 3) break;
        }

        return implode(',', $filters);
    }

    /**
     * Get emotion-specific audio processing filters.
     * CLEAR and DISTINCT - minimal processing, focus on clarity.
     */
    private function getEmotionAudioFilters(string $emotion): string
    {
        switch ($emotion) {
            case 'happy':
                // Bright and clear - boost clarity frequencies
                return
                    'equalizer=f=3500:t=q:w=1.5:g=+4,' .     // clarity boost
                    'equalizer=f=7000:t=q:w=1.2:g=+2,' .     // brightness
                    'acompressor=threshold=-24dB:ratio=2:attack=15:release=100:makeup=1dB,';

            case 'excited':
                // Very bright, energetic - strong clarity
                return
                    'equalizer=f=2500:t=q:w=1.2:g=+3,' .     // presence
                    'equalizer=f=5000:t=q:w=1.5:g=+4,' .     // excitement
                    'equalizer=f=8000:t=q:w=1.0:g=+2,' .     // air
                    'acompressor=threshold=-22dB:ratio=2.5:attack=10:release=80:makeup=2dB,';

            case 'sad':
                // Softer, slightly darker but still CLEAR
                return
                    'equalizer=f=200:t=q:w=0.8:g=+2,' .      // warmth
                    'equalizer=f=3000:t=q:w=1.2:g=+1,' .     // keep some clarity
                    'equalizer=f=8000:t=q:w=1.0:g=-2,' .     // slightly darker
                    'acompressor=threshold=-26dB:ratio=1.5:attack=25:release=150:makeup=1dB,';

            case 'angry':
                // Aggressive, punchy but CLEAR - boost mid presence
                return
                    'equalizer=f=200:t=q:w=0.6:g=+3,' .      // power
                    'equalizer=f=2500:t=q:w=1.5:g=+4,' .     // aggressive clarity
                    'equalizer=f=5000:t=q:w=1.2:g=+2,' .     // bite
                    'acompressor=threshold=-20dB:ratio=3:attack=5:release=50:makeup=3dB,';

            case 'frustration':
                // Tense but CLEAR
                return
                    'equalizer=f=250:t=q:w=0.7:g=+2,' .      // body
                    'equalizer=f=3000:t=q:w=1.3:g=+3,' .     // clarity
                    'equalizer=f=5000:t=q:w=1.0:g=+2,' .     // edge
                    'acompressor=threshold=-20dB:ratio=2.5:attack=10:release=80:makeup=2dB,';

            case 'fear':
                // Urgent, higher pitch feel but CLEAR
                return
                    'equalizer=f=3000:t=q:w=1.2:g=+4,' .     // urgent clarity
                    'equalizer=f=6000:t=q:w=1.0:g=+3,' .     // bright/panic
                    'equalizer=f=9000:t=q:w=1.0:g=+2,' .     // air
                    'acompressor=threshold=-20dB:ratio=3:attack=8:release=60:makeup=2dB,';

            case 'surprise':
                // Bright, sudden but CLEAR
                return
                    'equalizer=f=2500:t=q:w=1.0:g=+3,' .     // presence
                    'equalizer=f=5000:t=q:w=1.2:g=+4,' .     // bright
                    'equalizer=f=8000:t=q:w=1.0:g=+2,' .     // air
                    'acompressor=threshold=-20dB:ratio=2.5:attack=5:release=70:makeup=2dB,';

            default:
                // Neutral - MAXIMUM CLARITY, minimal processing
                return
                    'equalizer=f=180:t=q:w=0.7:g=+1,' .      // slight warmth
                    'equalizer=f=3000:t=q:w=1.2:g=+3,' .     // CLARITY BOOST
                    'equalizer=f=6000:t=q:w=1.0:g=+2,' .     // presence
                    'acompressor=threshold=-24dB:ratio=2:attack=15:release=100:makeup=1dB,';
        }
    }
}
