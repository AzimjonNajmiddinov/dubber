<?php

namespace App\Jobs\PremiumDub;

use App\Services\MmsTts\MmsTtsClient;
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

        $client = new MmsTtsClient();
        $workDir = storage_path("app/premium-dub/{$this->dubId}");
        $ttsDir = "{$workDir}/tts";
        @mkdir($ttsDir, 0755, true);

        $clonedVoices = []; // speaker → voice_id

        try {
            // 1. Clone voices
            $this->updateStatus('cloning_voices', 'Cloning speaker voices...');
            $clonedVoices = $this->cloneVoices($client, $segments, $vocalsPath, $workDir, $language);
            Log::info("[PREMIUM] [{$this->dubId}] Cloned " . count($clonedVoices) . " voices");

            // 2. Synthesize each segment
            $this->updateStatus('synthesizing', 'Generating dubbed speech...');
            $synthesized = 0;
            $total = count($segments);

            foreach ($segments as $i => $seg) {
                $text = trim($seg['text'] ?? '');
                if ($text === '') continue;

                $speaker   = $seg['speaker'] ?? 'SPEAKER_0';
                $emotion   = $seg['emotion'] ?? 'neutral';
                $voiceInfo = $clonedVoices[$speaker] ?? null;
                $voiceId   = is_array($voiceInfo) ? ($voiceInfo['voice_id'] ?? null) : $voiceInfo;
                $speed     = is_array($voiceInfo) ? ($voiceInfo['speed'] ?? 1.0) : 1.0;

                $outputWav = "{$ttsDir}/{$i}.wav";

                $text = TextNormalizer::normalize($text, $language);

                if ($voiceId) {
                    try {
                        $tau = is_array($voiceInfo) ? ($voiceInfo['tau'] ?? 0.9) : 0.9;
                        $wavData = $client->synthesize($voiceId, $text, [
                            'language'  => $language,
                            'speed'     => $speed,
                            'tau'       => $tau,
                        ]);
                        file_put_contents($outputWav, $wavData);
                    } catch (\Throwable $e) {
                        Log::warning("[PREMIUM] [{$this->dubId}] XTTS synthesis failed for segment {$i}, falling back to Edge TTS: " . $e->getMessage());
                        $this->synthesizeWithEdgeTts($text, $language, $speaker, $outputWav);
                    }
                } else {
                    $this->synthesizeWithEdgeTts($text, $language, $speaker, $outputWav);
                }

                // Adjust tempo to fit time slot
                if (file_exists($outputWav) && filesize($outputWav) > 200) {
                    $slotDuration = ($seg['end'] ?? 0) - ($seg['start'] ?? 0);
                    $this->adjustTempo($outputWav, $slotDuration);
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

    private function cloneVoices(MmsTtsClient $client, array $segments, ?string $vocalsPath, string $workDir, string $language = 'uz'): array
    {
        $speakers = array_unique(array_map(fn($s) => $s['speaker'] ?? 'SPEAKER_0', $segments));
        $speakersInfo = $this->getSession()['speakers_info'] ?? [];

        // Try voice pool first (pre-recorded Uzbek actor voices)
        $poolVoices = $this->loadVoicePool();
        if (!empty($poolVoices)) {
            return $this->assignPoolVoices($client, $speakers, $speakersInfo, $poolVoices);
        }

        // Fallback: generate Edge TTS Uzbek reference
        Log::info("[PREMIUM] [{$this->dubId}] No voice pool found, using Edge TTS reference");
        $refFile = $this->generateEdgeTtsReference($workDir, $language);
        if ($refFile) {
            try {
                $voiceId = $client->addVoice("dub-{$this->dubId}-fallback", [$refFile]);
                @unlink($refFile);
                $cloned = [];
                foreach ($speakers as $speaker) {
                    $cloned[$speaker] = $voiceId;
                }
                Log::info("[PREMIUM] [{$this->dubId}] Using Edge TTS fallback voice for all speakers");
                return $cloned;
            } catch (\Throwable $e) {
                Log::warning("[PREMIUM] [{$this->dubId}] Fallback voice clone failed: " . $e->getMessage());
            }
        }

        return [];
    }

    /**
     * Load voice pool files from storage/app/voice-pool/{male,female,child}/
     * Returns ['male' => [...paths], 'female' => [...paths], 'child' => [...paths]]
     */
    private function loadVoicePool(): array
    {
        $pool = [];
        $poolDir = storage_path('app/voice-pool');
        foreach (['male', 'female', 'child'] as $gender) {
            $dir = "{$poolDir}/{$gender}";
            if (!is_dir($dir)) continue;
            $files = glob("{$dir}/*.{wav,mp3,m4a,ogg,flac}", GLOB_BRACE);
            if (!empty($files)) {
                $pool[$gender] = array_values($files);
            }
        }
        return $pool;
    }

    /**
     * Assign pool voices to speakers, cycling through pool if more speakers than voices.
     * Uses gender info from WhisperX if available, otherwise defaults to 'male'.
     * Caches voice_ids in Redis so the same file isn't re-cloned on next dub.
     */
    private function assignPoolVoices(MmsTtsClient $client, array $speakers, array $speakersInfo, array $pool): array
    {
        $counters = ['male' => 0, 'female' => 0, 'child' => 0];
        $cloned = [];

        foreach ($speakers as $speaker) {
            $gender = $speakersInfo[$speaker]['gender'] ?? 'unknown';
            // Map unknown/ambiguous to male
            if (!isset($pool[$gender]) || empty($pool[$gender])) {
                $gender = isset($pool['male']) ? 'male' : array_key_first($pool);
            }

            $files = $pool[$gender];
            $file = $files[$counters[$gender] % count($files)];
            $counters[$gender]++;

            // Cache key: hash of file path so same file → same voice_id across runs
            $cacheKey = 'voice-pool-id:mms:' . md5($file);
            $voiceId = \Illuminate\Support\Facades\Redis::get($cacheKey);

            Log::info("[PREMIUM] [{$this->dubId}] Speaker {$speaker} → gender:{$gender}, voice:{$file}");

            if (!$voiceId) {
                try {
                    $name = pathinfo($file, PATHINFO_FILENAME);
                    $voiceId = $client->addVoice("pool-{$name}", [$file]);
                    // Cache for 7 days (pod restarts clear XTTS voices anyway)
                    \Illuminate\Support\Facades\Redis::setex($cacheKey, 604800, $voiceId);
                    Log::info("[PREMIUM] [{$this->dubId}] Cloned pool voice '{$name}' → {$voiceId}");
                } catch (\Throwable $e) {
                    Log::warning("[PREMIUM] [{$this->dubId}] Pool voice clone failed for {$file}: " . $e->getMessage());
                    continue;
                }
            } else {
                Log::info("[PREMIUM] [{$this->dubId}] Using cached pool voice for {$speaker}: {$voiceId}");
            }

            $voiceGender = pathinfo(dirname($file), PATHINFO_FILENAME);
            $voiceName   = pathinfo($file, PATHINFO_FILENAME);
            $cloned[$speaker] = [
                'voice_id' => $voiceId,
                'speed'    => \App\Http\Controllers\AdminVoicePoolController::getSpeed($voiceGender, $voiceName),
                'tau'      => \App\Http\Controllers\AdminVoicePoolController::getTau($voiceGender, $voiceName),
            ];
        }

        return $cloned;
    }

    private function generateEdgeTtsReference(string $workDir, string $language): ?string
    {
        $voices = \App\Services\VoiceVariants::forLanguage($language);
        $voice = $voices['male'][0] ?? $voices['female'][0] ?? null;
        if (!$voice) return null;

        $refMp3 = "{$workDir}/ref_edge.mp3";
        $refWav = "{$workDir}/ref_edge.wav";
        $text = "Salom, bu ovoz namunasi. Men o'zbek tilida gapiraman.";

        $edgeTts = trim(shell_exec('which edge-tts 2>/dev/null') ?? '') ?: 'edge-tts';
        $result = Process::timeout(20)->run([
            $edgeTts, '--text', $text, '--voice', $voice['voice'], '--write-media', $refMp3,
        ]);

        if (!$result->successful() || !file_exists($refMp3)) return null;

        Process::timeout(10)->run([
            'ffmpeg', '-y', '-i', $refMp3,
            '-ac', '1', '-ar', '22050', '-c:a', 'pcm_s16le', $refWav,
        ]);
        @unlink($refMp3);

        return (file_exists($refWav) && filesize($refWav) > 2000) ? $refWav : null;
    }

    private function synthesizeWithEdgeTts(string $text, string $language, string $speaker, string $outputWav): void
    {
        $variants = \App\Services\VoiceVariants::forLanguage($language);
        $isFemale = str_contains(strtolower($speaker), 'female') || str_contains($speaker, 'F');
        $voice = $isFemale ? $variants['female'][0] : $variants['male'][0];

        $tmpDir = dirname($outputWav);
        $stem = pathinfo($outputWav, PATHINFO_FILENAME);
        $tmpTxt = "{$tmpDir}/text_{$stem}.txt";
        $tmpMp3 = "{$tmpDir}/{$stem}_edge.mp3";
        file_put_contents($tmpTxt, $text);

        $edgeTts = trim(shell_exec('which edge-tts 2>/dev/null') ?? '') ?: 'edge-tts';

        Process::timeout(30)->run([
            $edgeTts, '-f', $tmpTxt,
            '--voice', $voice['voice'],
            "--pitch={$voice['pitch']}", "--rate={$voice['rate']}",
            '--write-media', $tmpMp3,
        ]);

        @unlink($tmpTxt);

        // Convert mp3 → wav so MixJob always gets wav files
        if (file_exists($tmpMp3) && filesize($tmpMp3) > 200) {
            Process::timeout(15)->run([
                'ffmpeg', '-y', '-i', $tmpMp3,
                '-ac', '1', '-ar', '24000', '-c:a', 'pcm_s16le',
                $outputWav,
            ]);
            @unlink($tmpMp3);
        }
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

        $adjusted = $mp3Path . '.adj.wav';
        $result = Process::timeout(15)->run([
            'ffmpeg', '-y', '-i', $mp3Path,
            '-filter:a', "atempo={$tempo}",
            '-c:a', 'pcm_s16le',
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
        $client = new MmsTtsClient();
        foreach ($clonedVoices as $voiceId) {
            try { $client->deleteVoice($voiceId); } catch (\Throwable) {}
        }

        $this->updateStatus('error', 'Synthesis failed');
        Log::error("[PREMIUM] [{$this->dubId}] PremiumDubCloneAndSynthesizeJob failed: " . $e->getMessage());
    }
}
