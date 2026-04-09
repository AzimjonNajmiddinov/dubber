<?php

namespace App\Services\Tts\Drivers;

use App\Contracts\TtsDriverInterface;
use App\Http\Controllers\AdminVoicePoolController;
use App\Models\Speaker;
use App\Models\VideoSegment;
use App\Services\MmsTts\MmsTtsClient;
use App\Services\TextNormalizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MmsTtsDriver implements TtsDriverInterface
{
    public function name(): string
    {
        return 'mms';
    }

    public function supportsVoiceCloning(): bool
    {
        return true;
    }

    public function supportsEmotions(): bool
    {
        return false;
    }

    public function getVoices(string $language): array
    {
        return [
            'male'   => [['id' => 'mms-male',   'name' => 'MMS Male',   'gender' => 'male']],
            'female' => [['id' => 'mms-female',  'name' => 'MMS Female', 'gender' => 'female']],
        ];
    }

    public function getCostPerCharacter(): float
    {
        return 0.0;
    }

    public function synthesize(
        string $text,
        Speaker $speaker,
        VideoSegment $segment,
        array $options = []
    ): string {
        $language = $options['language'] ?? 'uz';
        $text     = TextNormalizer::normalize($text, $language);

        $gender  = strtolower($speaker->gender ?? 'male');
        $voiceId = $this->resolveVoiceId($gender, $speaker->id);

        $videoId   = $segment->video_id;
        $segmentId = $segment->id;
        $outDir    = Storage::disk('local')->path("audio/tts/{$videoId}");
        @mkdir($outDir, 0777, true);
        $outputWav = "{$outDir}/seg_{$segmentId}.wav";

        $client = new MmsTtsClient();

        if ($voiceId) {
            try {
                $voiceFile = $this->poolFileForGender($gender, $speaker->id);
                $speed = $voiceFile ? AdminVoicePoolController::getSpeed(
                    pathinfo(dirname($voiceFile), PATHINFO_FILENAME),
                    pathinfo($voiceFile, PATHINFO_FILENAME)
                ) : 1.0;
                $tau = $voiceFile ? AdminVoicePoolController::getTau(
                    pathinfo(dirname($voiceFile), PATHINFO_FILENAME),
                    pathinfo($voiceFile, PATHINFO_FILENAME)
                ) : 0.7;

                $wavData = $client->synthesize($voiceId, $text, [
                    'language' => $language,
                    'speed'    => $speed,
                    'tau'      => $tau,
                ]);
                file_put_contents($outputWav, $wavData);
            } catch (\Throwable $e) {
                Log::warning("[MmsTtsDriver] Synthesis failed for seg {$segmentId}, fallback Edge TTS: " . $e->getMessage());
                $this->fallbackEdgeTts($text, $language, $gender, $outputWav);
            }
        } else {
            $this->fallbackEdgeTts($text, $language, $gender, $outputWav);
        }

        if (!file_exists($outputWav) || filesize($outputWav) < 200) {
            throw new RuntimeException("MmsTtsDriver: synthesis produced no audio for segment {$segmentId}");
        }

        // Resample to 48kHz stereo (Flow 1 pipeline standard)
        $tmp = $outputWav . '.tmp.wav';
        $cmd = sprintf(
            'ffmpeg -y -hide_banner -loglevel error -i %s -ar 48000 -ac 2 -c:a pcm_s16le %s',
            escapeshellarg($outputWav), escapeshellarg($tmp)
        );
        exec($cmd, $out, $code);
        if ($code === 0 && file_exists($tmp) && filesize($tmp) > 200) {
            rename($tmp, $outputWav);
        } else {
            @unlink($tmp);
        }

        return $outputWav;
    }

    public function cloneVoice(string $audioPath, string $name, array $options = []): string
    {
        $client = new MmsTtsClient();
        return $client->addVoice($name, [$audioPath]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function resolveVoiceId(string $gender, int $speakerId): ?string
    {
        $file = $this->poolFileForGender($gender, $speakerId);
        if (!$file) return null;

        $cacheKey = 'voice-pool-id:mms:' . md5($file);
        $voiceId  = Redis::get($cacheKey);

        if (!$voiceId) {
            try {
                $client  = new MmsTtsClient();
                $name    = pathinfo($file, PATHINFO_FILENAME);
                $voiceId = $client->addVoice("pool-{$name}", [$file]);
                Redis::setex($cacheKey, 604800, $voiceId);
                Log::info("[MmsTtsDriver] Cloned pool voice '{$name}' → {$voiceId}");
            } catch (\Throwable $e) {
                Log::warning("[MmsTtsDriver] Pool voice clone failed for {$file}: " . $e->getMessage());
                return null;
            }
        }

        return $voiceId;
    }

    private function poolFileForGender(string $gender, int $speakerId): ?string
    {
        if (!in_array($gender, ['male', 'female', 'child'])) {
            $gender = 'male';
        }

        $dir   = storage_path("app/voice-pool/{$gender}");
        $files = is_dir($dir) ? glob("{$dir}/*.{wav,mp3,m4a}", GLOB_BRACE) : [];

        if (empty($files)) {
            // Fallback to male pool if requested gender has no files
            $dir   = storage_path('app/voice-pool/male');
            $files = is_dir($dir) ? glob("{$dir}/*.{wav,mp3,m4a}", GLOB_BRACE) : [];
        }

        if (empty($files)) return null;

        return $files[$speakerId % count($files)];
    }

    private function fallbackEdgeTts(string $text, string $language, string $gender, string $outputWav): void
    {
        $variants  = \App\Services\VoiceVariants::forLanguage($language);
        $voiceData = ($gender === 'female') ? ($variants['female'][0] ?? $variants['male'][0]) : ($variants['male'][0] ?? null);

        if (!$voiceData) return;

        $tmpDir = dirname($outputWav);
        $stem   = pathinfo($outputWav, PATHINFO_FILENAME);
        $tmpTxt = "{$tmpDir}/text_{$stem}.txt";
        $tmpMp3 = "{$tmpDir}/{$stem}_edge.mp3";
        file_put_contents($tmpTxt, $text);

        $edgeTts = trim(shell_exec('which edge-tts 2>/dev/null') ?? '') ?: 'edge-tts';
        exec(implode(' ', array_map('escapeshellarg', [
            $edgeTts, '-f', $tmpTxt,
            '--voice', $voiceData['voice'],
            "--pitch={$voiceData['pitch']}", "--rate={$voiceData['rate']}",
            '--write-media', $tmpMp3,
        ])));

        @unlink($tmpTxt);

        if (file_exists($tmpMp3) && filesize($tmpMp3) > 200) {
            $cmd = sprintf(
                'ffmpeg -y -hide_banner -loglevel error -i %s -ac 2 -ar 48000 -c:a pcm_s16le %s',
                escapeshellarg($tmpMp3), escapeshellarg($outputWav)
            );
            exec($cmd);
            @unlink($tmpMp3);
        }
    }
}
