<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoSegment;
use App\Services\SegmentVideoService;
use App\Traits\DetectsEnglish;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Processes a single segment through the full pipeline:
 * 1. Translate text (if not already translated)
 * 2. Generate TTS audio (if not already generated)
 * 3. Cut video segment with dubbed audio
 *
 * This enables progressive playback - each segment becomes playable as it's processed.
 */
class ProcessSingleSegmentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use DetectsEnglish;

    public int $timeout = 300; // 5 minutes per segment
    public int $tries = 2;
    public int $uniqueFor = 300;

    public array $backoff = [10, 30];

    public function __construct(
        public int $segmentId,
        public bool $dispatchNext = true
    ) {}

    public function uniqueId(): string
    {
        return 'process_segment_' . $this->segmentId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessSingleSegmentJob failed', [
            'segment_id' => $this->segmentId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function handle(SegmentVideoService $segmentService): void
    {
        $segment = VideoSegment::with(['video', 'speaker'])->find($this->segmentId);

        if (!$segment) {
            Log::warning('ProcessSingleSegmentJob: Segment not found', ['segment_id' => $this->segmentId]);
            return;
        }

        $video = $segment->video;
        if (!$video) {
            Log::warning('ProcessSingleSegmentJob: Video not found', ['segment_id' => $this->segmentId]);
            return;
        }

        Log::info('Processing single segment', [
            'segment_id' => $segment->id,
            'video_id' => $video->id,
            'has_translation' => !empty($segment->translated_text),
            'has_tts' => !empty($segment->tts_audio_path),
        ]);

        // Step 1: Translate if needed
        if (empty($segment->translated_text)) {
            $this->translateSegment($segment, $video);
            $segment->refresh();
        }

        // Step 2: Generate TTS if needed
        if (empty($segment->tts_audio_path) && !empty($segment->translated_text)) {
            $this->generateTts($segment, $video);
            $segment->refresh();
        }

        // Step 3: Generate video segment (cut + mux)
        if (!empty($segment->tts_audio_path)) {
            $segmentService->getOrGenerateSegment($segment);
        }

        Log::info('Segment processing complete', [
            'segment_id' => $segment->id,
            'video_id' => $video->id,
        ]);

        // Dispatch next segment if requested
        if ($this->dispatchNext) {
            $this->dispatchNextSegment($video, $segment);
        }
    }

    private function translateSegment(VideoSegment $segment, Video $video): void
    {
        $text = trim((string) $segment->text);
        if ($text === '') {
            $segment->update(['translated_text' => '']);
            return;
        }

        $targetLanguage = $this->normalizeTargetLanguage((string) ($video->target_language ?: 'uz'));

        $apiKey = (string) config('services.openai.key');
        if (trim($apiKey) === '') {
            throw new \RuntimeException('OpenAI API key missing');
        }

        $client = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->retry(2, 1000);

        // Try translation with retry for English detection
        $translated = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $system = $this->buildTranslationPrompt($targetLanguage, $attempt);

            $res = $client->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'temperature' => $attempt === 1 ? 0.2 : 0.0,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $text],
                ],
            ]);

            if ($res->failed()) {
                Log::error('Translation API failed', [
                    'segment_id' => $segment->id,
                    'status' => $res->status(),
                ]);
                throw new \RuntimeException('Translation API failed');
            }

            $out = $res->json('choices.0.message.content');
            if (!is_string($out)) {
                throw new \RuntimeException('Translation returned invalid response');
            }

            $out = trim($out);

            if (!$this->looksLikeEnglish($out)) {
                $translated = $out;
                break;
            }

            Log::warning('Translation looks English, retrying', [
                'segment_id' => $segment->id,
                'attempt' => $attempt,
            ]);
        }

        $segment->update(['translated_text' => $translated ?? $out ?? '']);

        Log::info('Segment translated', [
            'segment_id' => $segment->id,
            'original' => mb_substr($text, 0, 50),
            'translated' => mb_substr($segment->translated_text, 0, 50),
        ]);
    }

    private function generateTts(VideoSegment $segment, Video $video): void
    {
        $text = trim((string) $segment->translated_text);
        if ($text === '') {
            return;
        }

        // Check for English leak
        if ($this->looksLikeEnglish($text)) {
            Log::error('TTS blocked: text looks English', [
                'segment_id' => $segment->id,
                'sample' => mb_substr($text, 0, 100),
            ]);
            return;
        }

        $speaker = $segment->speaker;
        $voice = (string) ($speaker?->tts_voice ?: 'uz-UZ-SardorNeural');
        $rate = (string) ($speaker?->tts_rate ?: '+0%');
        $pitch = (string) ($speaker?->tts_pitch ?: '+0Hz');
        $baseGainDb = is_numeric($speaker?->tts_gain_db) ? (float) $speaker->tts_gain_db : 0.0;

        // Calculate slot duration for timing fit
        $slotDuration = (float) $segment->end_time - (float) $segment->start_time;
        $textLen = mb_strlen($text);
        $estimatedDuration = $textLen / 7.0;

        if ($slotDuration > 0 && $estimatedDuration > $slotDuration) {
            $speedupFactor = $estimatedDuration / $slotDuration;
            $rateBoost = min((int) round(($speedupFactor - 1) * 100), 40);
            $rate = $this->mergeRate($rate, '+' . $rateBoost . '%');
        }

        // Clamp values
        $rate = $this->clampRate($rate, -20, 60);
        $pitch = $this->clampPitchHz($pitch, -50, 80);
        $baseGainDb = max(-4.0, min(5.0, $baseGainDb));

        // Output paths
        $outDirRel = "audio/tts/{$video->id}";
        $outDirAbs = Storage::disk('local')->path($outDirRel);
        @mkdir($outDirAbs, 0777, true);

        $rawMp3Abs = "{$outDirAbs}/seg_{$segment->id}.raw.mp3";
        $normAbs = "{$outDirAbs}/seg_{$segment->id}.wav";

        @unlink($rawMp3Abs);
        @unlink($normAbs);

        // Generate TTS with edge-tts
        $tmpTxtAbs = "/tmp/tts_{$video->id}_{$segment->id}_" . Str::random(8) . ".txt";
        file_put_contents($tmpTxtAbs, $text);

        $cmdTts = sprintf(
            'edge-tts -f %s --voice %s --rate=%s --pitch=%s --write-media %s 2>&1',
            escapeshellarg($tmpTxtAbs),
            escapeshellarg($voice),
            escapeshellarg($rate),
            escapeshellarg($pitch),
            escapeshellarg($rawMp3Abs)
        );

        $out1 = [];
        exec($cmdTts, $out1, $code1);
        @unlink($tmpTxtAbs);

        if ($code1 !== 0 || !file_exists($rawMp3Abs) || filesize($rawMp3Abs) < 500) {
            Log::error('TTS generation failed', [
                'segment_id' => $segment->id,
                'exit_code' => $code1,
                'output' => implode("\n", array_slice($out1, -20)),
            ]);
            throw new \RuntimeException("TTS failed for segment {$segment->id}");
        }

        // Probe duration for time-stretching
        $rawDuration = $this->probeAudioDuration($rawMp3Abs);
        $atempoFilter = '';

        if ($rawDuration > 0 && $slotDuration > 0 && $rawDuration > $slotDuration * 1.05) {
            $speedupRatio = min($rawDuration / $slotDuration, 3.0);
            $atempoFilter = $this->buildAtempoChain($speedupRatio);
        }

        // Normalize to WAV
        $filter = sprintf(
            'aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo,%s' .
            'highpass=f=80,lowpass=f=10000,' .
            'equalizer=f=3000:t=q:w=1.2:g=+3,' .
            'volume=%sdB,' .
            'loudnorm=I=-16:TP=-1.5:LRA=11,' .
            'aresample=48000',
            $atempoFilter ? $atempoFilter . ',' : '',
            $this->fmtDb($baseGainDb)
        );

        $cmdNorm = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -vn -af %s -ar 48000 -ac 2 -c:a pcm_s16le %s 2>&1',
            escapeshellarg($rawMp3Abs),
            escapeshellarg($filter),
            escapeshellarg($normAbs)
        );

        $out2 = [];
        exec($cmdNorm, $out2, $code2);

        if ($code2 !== 0 || !file_exists($normAbs) || filesize($normAbs) < 5000) {
            Log::error('TTS normalization failed', [
                'segment_id' => $segment->id,
                'exit_code' => $code2,
            ]);
            throw new \RuntimeException("TTS normalization failed for segment {$segment->id}");
        }

        @unlink($rawMp3Abs);

        $segment->update([
            'tts_audio_path' => "{$outDirRel}/seg_{$segment->id}.wav",
            'tts_gain_db' => $baseGainDb,
        ]);

        Log::info('TTS generated', [
            'segment_id' => $segment->id,
            'path' => "{$outDirRel}/seg_{$segment->id}.wav",
        ]);
    }

    private function dispatchNextSegment(Video $video, VideoSegment $currentSegment): void
    {
        // Find next unprocessed segment
        $next = $video->segments()
            ->where('start_time', '>', $currentSegment->start_time)
            ->whereNull('tts_audio_path')
            ->orderBy('start_time')
            ->first();

        if ($next) {
            Log::info('Dispatching next segment', [
                'current_segment_id' => $currentSegment->id,
                'next_segment_id' => $next->id,
            ]);

            ProcessSingleSegmentJob::dispatch($next->id, true)
                ->onQueue('segment-processing');
        } else {
            // All segments processed - update video status
            $video->update(['status' => 'streaming_ready']);
            Log::info('All segments processed', ['video_id' => $video->id]);
        }
    }

    // Helper methods

    private function normalizeTargetLanguage(string $raw): string
    {
        $v = trim(mb_strtolower($raw));
        if ($v === '' || $v === 'uz' || str_contains($v, 'uzbek')) {
            return "Uzbek (Latin, O'zbekcha) [uz]";
        }
        if ($v === 'ru' || str_contains($v, 'russian')) {
            return "Russian [ru]";
        }
        if ($v === 'en' || str_contains($v, 'english')) {
            return "English [en]";
        }
        return $raw;
    }

    private function buildTranslationPrompt(string $targetLanguage, int $attempt): string
    {
        $extra = $attempt >= 2
            ? "\nSTRICT MODE: Output ONLY in target language. No English words."
            : '';

        return
            "You are translating movie dialogue for PROFESSIONAL DUBBING.\n" .
            "Target language: {$targetLanguage}.\n" .
            "Rules:\n" .
            "- Output ONLY in the target language.\n" .
            "- Write as real spoken dialogue.\n" .
            "- Match the original line's length and rhythm.\n" .
            "- No explanations, no quotes, no meta text.\n" .
            $extra;
    }

    private function mergeRate(string $base, string $adj): string
    {
        $b = $this->parseSignedNumber($base);
        $a = $this->parseSignedNumber($adj);
        $sum = $b + $a;
        return ($sum >= 0 ? '+' : '') . (int) round($sum) . '%';
    }

    private function parseSignedNumber(string $s): float
    {
        $s = preg_replace('/[^0-9.\-+]/', '', trim(strtolower($s)));
        return $s === '' || $s === '+' || $s === '-' ? 0.0 : (float) $s;
    }

    private function clampRate(string $rate, int $min, int $max): string
    {
        $v = (int) round($this->parseSignedNumber($rate));
        $v = max($min, min($max, $v));
        return ($v >= 0 ? '+' : '') . $v . '%';
    }

    private function clampPitchHz(string $pitch, int $min, int $max): string
    {
        $v = (int) round($this->parseSignedNumber($pitch));
        $v = max($min, min($max, $v));
        return ($v >= 0 ? '+' : '') . $v . 'Hz';
    }

    private function fmtDb(float $db): string
    {
        return ($db >= 0 ? '+' : '') . round($db, 2);
    }

    private function probeAudioDuration(string $path): float
    {
        if (!file_exists($path)) return 0.0;
        $cmd = sprintf(
            'ffprobe -hide_banner -loglevel error -show_entries format=duration -of default=nw=1:nk=1 %s 2>/dev/null',
            escapeshellarg($path)
        );
        $out = @shell_exec($cmd);
        $dur = is_string($out) ? (float) trim($out) : 0.0;
        return $dur > 0 ? $dur : 0.0;
    }

    private function buildAtempoChain(float $ratio): string
    {
        if ($ratio <= 1.0) return '';
        $filters = [];
        $remaining = $ratio;
        while ($remaining > 1.0 && count($filters) < 3) {
            $step = min($remaining, 2.0);
            $filters[] = 'atempo=' . number_format($step, 4, '.', '');
            $remaining = $remaining / $step;
        }
        return implode(',', $filters);
    }
}
