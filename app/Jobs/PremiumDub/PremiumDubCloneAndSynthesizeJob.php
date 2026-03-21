<?php

namespace App\Jobs\PremiumDub;

use App\Services\ElevenLabs\ElevenLabsClient;
use App\Services\TextNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PremiumDubCloneAndSynthesizeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min for many segments
    public int $tries = 1;

    // ElevenLabs emotion → voice_settings mapping
    private const EMOTION_SETTINGS = [
        'neutral'    => ['stability' => 0.50, 'similarity_boost' => 0.75, 'style' => 0.0],
        'happy'      => ['stability' => 0.35, 'similarity_boost' => 0.70, 'style' => 0.5],
        'angry'      => ['stability' => 0.30, 'similarity_boost' => 0.80, 'style' => 0.7],
        'sad'        => ['stability' => 0.40, 'similarity_boost' => 0.70, 'style' => 0.4],
        'fearful'    => ['stability' => 0.35, 'similarity_boost' => 0.75, 'style' => 0.5],
        'surprised'  => ['stability' => 0.30, 'similarity_boost' => 0.75, 'style' => 0.6],
        'whispering' => ['stability' => 0.60, 'similarity_boost' => 0.80, 'style' => 0.2],
        'serious'    => ['stability' => 0.55, 'similarity_boost' => 0.80, 'style' => 0.1],
        'sarcastic'  => ['stability' => 0.35, 'similarity_boost' => 0.70, 'style' => 0.4],
        'excited'    => ['stability' => 0.25, 'similarity_boost' => 0.75, 'style' => 0.8],
    ];

    public function __construct(
        public string $dubId,
    ) {}

    public function handle(): void
    {
        $session = $this->getSession();
        $segments = $session['translated_segments'] ?? [];
        $vocalsPath = $session['vocals_path'] ?? null;
        $language = $session['language'] ?? 'uz';

        if (empty($segments)) {
            $this->updateStatus('error', 'No translated segments');
            return;
        }

        $client = new ElevenLabsClient();
        $workDir = storage_path("app/premium-dub/{$this->dubId}");
        $ttsDir = "{$workDir}/tts";
        @mkdir($ttsDir, 0755, true);

        $clonedVoices = []; // speaker → voice_id

        try {
            // 1. Clone voices from vocals track
            if ($vocalsPath && file_exists($vocalsPath)) {
                $this->updateStatus('cloning_voices', 'Cloning speaker voices...');
                $clonedVoices = $this->cloneVoices($client, $segments, $vocalsPath, $workDir);
                Log::info("[PREMIUM] [{$this->dubId}] Cloned " . count($clonedVoices) . " voices");
            }

            // 2. Synthesize each segment
            $this->updateStatus('synthesizing', 'Generating dubbed speech...');
            $synthesized = 0;
            $total = count($segments);

            foreach ($segments as $i => $seg) {
                $text = trim($seg['text'] ?? '');
                if ($text === '') continue;

                $speaker = $seg['speaker'] ?? 'SPEAKER_0';
                $emotion = $seg['emotion'] ?? 'neutral';
                $voiceId = $clonedVoices[$speaker] ?? null;

                $outputMp3 = "{$ttsDir}/{$i}.mp3";

                if ($voiceId) {
                    // ElevenLabs with cloned voice + emotion
                    $settings = self::EMOTION_SETTINGS[$emotion] ?? self::EMOTION_SETTINGS['neutral'];
                    $text = TextNormalizer::normalize($text, $language);

                    try {
                        $mp3Data = $client->synthesize($voiceId, $text, $settings);
                        file_put_contents($outputMp3, $mp3Data);
                    } catch (\Throwable $e) {
                        Log::warning("[PREMIUM] [{$this->dubId}] ElevenLabs synthesis failed for segment {$i}, falling back to Edge TTS: " . $e->getMessage());
                        $this->synthesizeWithEdgeTts($text, $language, $speaker, $outputMp3);
                    }
                } else {
                    // Fallback to Edge TTS
                    $this->synthesizeWithEdgeTts($text, $language, $speaker, $outputMp3);
                }

                // Adjust tempo to fit time slot
                if (file_exists($outputMp3) && filesize($outputMp3) > 200) {
                    $slotDuration = ($seg['end'] ?? 0) - ($seg['start'] ?? 0);
                    $this->adjustTempo($outputMp3, $slotDuration);
                }

                $synthesized++;
                if ($synthesized % 10 === 0) {
                    $this->updateSession(['progress' => "Synthesizing: {$synthesized}/{$total} segments..."]);
                }
            }

            // Store cloned voice IDs for cleanup later
            $this->updateSession([
                'tts_dir' => $ttsDir,
                'cloned_voices' => $clonedVoices,
                'synthesis_ready' => true,
                'segments_synthesized' => $synthesized,
            ]);

            Log::info("[PREMIUM] [{$this->dubId}] Synthesis complete: {$synthesized}/{$total} segments");

            // Next step: mix audio
            PremiumDubMixJob::dispatch($this->dubId)->onQueue('default');

        } catch (\Throwable $e) {
            // Cleanup cloned voices on failure
            foreach ($clonedVoices as $voiceId) {
                try { $client->deleteVoice($voiceId); } catch (\Throwable) {}
            }
            $this->updateStatus('error', 'Synthesis failed: ' . Str::limit($e->getMessage(), 100));
            Log::error("[PREMIUM] [{$this->dubId}] Synthesis failed: " . $e->getMessage());
        }
    }

    private function cloneVoices(ElevenLabsClient $client, array $segments, string $vocalsPath, string $workDir): array
    {
        // Group segments by speaker
        $speakerSegments = [];
        foreach ($segments as $seg) {
            $spk = $seg['speaker'] ?? 'SPEAKER_0';
            $speakerSegments[$spk][] = $seg;
        }

        $cloned = [];
        $samplesDir = "{$workDir}/voice_samples";
        @mkdir($samplesDir, 0755, true);

        foreach ($speakerSegments as $speaker => $segs) {
            // Extract best sample segments (longest, up to 30s total)
            usort($segs, fn($a, $b) => ($b['end'] - $b['start']) <=> ($a['end'] - $a['start']));

            $totalDur = 0;
            $sampleParts = [];
            foreach ($segs as $seg) {
                $dur = ($seg['end'] ?? 0) - ($seg['start'] ?? 0);
                if ($dur < 1.0) continue;

                $partFile = "{$samplesDir}/{$speaker}_part_" . count($sampleParts) . ".wav";
                $result = Process::timeout(10)->run([
                    'ffmpeg', '-y',
                    '-ss', (string) round($seg['start'], 3),
                    '-t', (string) round($dur, 3),
                    '-i', $vocalsPath,
                    '-af', 'highpass=f=80,lowpass=f=12000,afftdn=nf=-20',
                    '-ac', '1', '-ar', '22050', '-c:a', 'pcm_s16le',
                    $partFile,
                ]);

                if ($result->successful() && file_exists($partFile) && filesize($partFile) > 1000) {
                    $sampleParts[] = $partFile;
                    $totalDur += $dur;
                    if ($totalDur >= 25) break;
                }
            }

            if (empty($sampleParts)) {
                Log::warning("[PREMIUM] [{$this->dubId}] No voice sample for {$speaker}");
                continue;
            }

            // Concatenate parts into single sample
            $sampleFile = "{$samplesDir}/{$speaker}.wav";
            if (count($sampleParts) === 1) {
                rename($sampleParts[0], $sampleFile);
            } else {
                $concatList = "{$samplesDir}/{$speaker}_list.txt";
                file_put_contents($concatList, implode("\n", array_map(fn($p) => "file '{$p}'", $sampleParts)));
                Process::timeout(15)->run([
                    'ffmpeg', '-y', '-f', 'concat', '-safe', '0',
                    '-i', $concatList,
                    '-c:a', 'pcm_s16le', $sampleFile,
                ]);
                @unlink($concatList);
            }

            // Cleanup parts
            foreach ($sampleParts as $part) {
                @unlink($part);
            }

            if (!file_exists($sampleFile) || filesize($sampleFile) < 2000) continue;

            // Clone voice via ElevenLabs
            try {
                $voiceId = $client->addVoice("dub-{$this->dubId}-{$speaker}", [$sampleFile]);
                $cloned[$speaker] = $voiceId;
                Log::info("[PREMIUM] [{$this->dubId}] Cloned voice for {$speaker}: {$voiceId}");
            } catch (\Throwable $e) {
                Log::warning("[PREMIUM] [{$this->dubId}] Voice clone failed for {$speaker}: " . $e->getMessage());
            }

            @unlink($sampleFile);
        }

        return $cloned;
    }

    private function synthesizeWithEdgeTts(string $text, string $language, string $speaker, string $outputMp3): void
    {
        $variants = \App\Services\VoiceVariants::forLanguage($language);
        $isFemale = str_contains(strtolower($speaker), 'female') || str_contains($speaker, 'F');
        $voice = $isFemale ? $variants['female'][0] : $variants['male'][0];

        $tmpDir = dirname($outputMp3);
        $tmpTxt = "{$tmpDir}/text_{basename($outputMp3, '.mp3')}.txt";
        file_put_contents($tmpTxt, $text);

        $edgeTts = trim(shell_exec('which edge-tts 2>/dev/null') ?? '') ?: 'edge-tts';

        Process::timeout(30)->run([
            $edgeTts, '-f', $tmpTxt,
            '--voice', $voice['voice'],
            "--pitch={$voice['pitch']}", "--rate={$voice['rate']}",
            '--write-media', $outputMp3,
        ]);

        @unlink($tmpTxt);
    }

    private function adjustTempo(string $mp3Path, float $slotDuration): void
    {
        if ($slotDuration < 0.5) return;

        $ttsDuration = $this->getAudioDuration($mp3Path);
        if ($ttsDuration < 0.1) return;

        $ratio = $ttsDuration / $slotDuration;
        if ($ratio <= 1.05 && $ratio >= 0.9) return; // Close enough

        $tempo = null;
        if ($ratio > 1.05) {
            $tempo = min($ratio, 1.35);
        } elseif ($ratio < 0.9) {
            $tempo = max($ttsDuration / ($slotDuration * 0.95), 0.8);
        }

        if ($tempo === null) return;

        $adjusted = $mp3Path . '.adj.mp3';
        $result = Process::timeout(15)->run([
            'ffmpeg', '-y', '-i', $mp3Path,
            '-filter:a', "atempo={$tempo}",
            '-codec:a', 'libmp3lame', '-b:a', '128k',
            $adjusted,
        ]);

        if ($result->successful() && file_exists($adjusted) && filesize($adjusted) > 200) {
            rename($adjusted, $mp3Path);
        } else {
            @unlink($adjusted);
        }
    }

    private function getAudioDuration(string $path): float
    {
        $result = Process::timeout(10)->run([
            'ffprobe', '-hide_banner', '-loglevel', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1', $path,
        ]);
        return (float) trim($result->output());
    }

    private function getSession(): array
    {
        $json = Redis::get("premium-dub:{$this->dubId}");
        return $json ? json_decode($json, true) : [];
    }

    private function updateStatus(string $status, string $progress = ''): void
    {
        $key = "premium-dub:{$this->dubId}";
        $json = Redis::get($key);
        $session = $json ? json_decode($json, true) : [];
        $session['status'] = $status;
        if ($progress) $session['progress'] = $progress;
        Redis::setex($key, 86400, json_encode($session));
    }

    private function updateSession(array $data): void
    {
        $key = "premium-dub:{$this->dubId}";
        $json = Redis::get($key);
        $session = $json ? json_decode($json, true) : [];
        Redis::setex($key, 86400, json_encode(array_merge($session, $data)));
    }

    public function failed(\Throwable $e): void
    {
        // Cleanup cloned voices
        $session = $this->getSession();
        $clonedVoices = $session['cloned_voices'] ?? [];
        $client = new ElevenLabsClient();
        foreach ($clonedVoices as $voiceId) {
            try { $client->deleteVoice($voiceId); } catch (\Throwable) {}
        }

        $this->updateStatus('error', 'Synthesis failed');
        Log::error("[PREMIUM] [{$this->dubId}] PremiumDubCloneAndSynthesizeJob failed: " . $e->getMessage());
    }
}
