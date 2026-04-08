<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

class AdminVoicePoolController extends Controller
{
    private const GENDERS = ['male', 'female', 'child'];

    public function index()
    {
        $pool = [];
        foreach (self::GENDERS as $gender) {
            $dir = storage_path("app/voice-pool/{$gender}");
            if (!is_dir($dir)) continue;
            foreach (glob("{$dir}/*.wav") as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $ref_text = Redis::get('voice-pool-ref:' . md5($file)) ?: null;
                $pool[] = [
                    'gender'   => $gender,
                    'name'     => $name,
                    'size'     => round(filesize($file) / 1024) . ' KB',
                    'duration' => $this->getAudioDuration($file),
                    'speed'    => $this->getSpeed($gender, $name),
                    'ref_text' => $ref_text,
                ];
            }
        }

        return view('admin.voice-pool', compact('pool'));
    }

    public function add(Request $request)
    {
        $request->validate([
            'youtube_url' => 'required|string',
            'gender'      => 'required|in:male,female,child',
            'name'        => 'required|string|max:50|regex:/^[a-zA-Z0-9_-]+$/',
            'start'       => 'nullable|integer|min:0',
            'duration'    => 'nullable|integer|min:5|max:60',
            'ref_text'    => 'nullable|string|max:500',
        ]);

        $gender   = $request->input('gender');
        $name     = $request->input('name');
        $url      = $request->input('youtube_url');
        $start    = (int) ($request->input('start', 0));
        $duration = (int) ($request->input('duration', 25));
        $refText  = $request->input('ref_text');

        $dir = storage_path("app/voice-pool/{$gender}");
        @mkdir($dir, 0775, true);

        $tmpFile = storage_path("app/voice-pool/tmp_{$name}_" . time());
        $outWav  = "{$dir}/{$name}.wav";

        $dlResult = Process::timeout(120)->run([
            'yt-dlp', '--no-playlist',
            '-x', '--audio-format', 'wav',
            '-o', $tmpFile . '.%(ext)s',
            $url,
        ]);

        $downloaded = $tmpFile . '.wav';
        if (!file_exists($downloaded)) {
            foreach (glob($tmpFile . '.*') as $f) { $downloaded = $f; break; }
        }

        if (!file_exists($downloaded)) {
            return back()->withErrors(['youtube_url' => 'Download failed: ' . substr($dlResult->errorOutput(), -300)]);
        }

        $ffResult = Process::timeout(30)->run([
            'ffmpeg', '-y',
            '-ss', (string) $start, '-t', (string) $duration,
            '-i', $downloaded,
            '-af', 'loudnorm',
            '-ac', '1', '-ar', '22050', '-c:a', 'pcm_s16le', $outWav,
        ]);

        @unlink($downloaded);

        if (!$ffResult->successful() || !file_exists($outWav) || filesize($outWav) < 2000) {
            return back()->withErrors(['youtube_url' => 'Audio extraction failed: ' . substr($ffResult->errorOutput(), -200)]);
        }

        Redis::del('voice-pool-id:' . md5($outWav));
        if ($refText) {
            Redis::setex('voice-pool-ref:' . md5($outWav), 30 * 86400, $refText);
        }
        Log::info("[VOICE POOL] Added {$gender}/{$name} from {$url} (start={$start}s, dur={$duration}s)");

        return back()->with('success', "Voice '{$name}' added to {$gender} pool ({$duration}s).");
    }

    public function upload(Request $request)
    {
        $request->validate([
            'audio'    => 'required|file|max:204800',
            'gender'   => 'required|in:male,female,child',
            'name'     => 'required|string|max:50|regex:/^[a-zA-Z0-9_-]+$/',
            'start'    => 'nullable|integer|min:0',
            'duration' => 'nullable|integer|min:5|max:60',
            'ref_text' => 'nullable|string|max:500',
        ]);

        $gender   = $request->input('gender');
        $name     = $request->input('name');
        $start    = (int) ($request->input('start', 0));
        $duration = (int) ($request->input('duration', 25));
        $refText  = $request->input('ref_text');

        $dir = storage_path("app/voice-pool/{$gender}");
        @mkdir($dir, 0775, true);

        $tmpFile = $request->file('audio')->store('voice-pool/tmp');
        $tmpPath = storage_path("app/{$tmpFile}");
        $outWav  = "{$dir}/{$name}.wav";

        $ffResult = Process::timeout(30)->run([
            'ffmpeg', '-y',
            '-ss', (string) $start, '-t', (string) $duration,
            '-i', $tmpPath,
            '-af', 'loudnorm',
            '-ac', '1', '-ar', '22050', '-c:a', 'pcm_s16le', $outWav,
        ]);

        @unlink($tmpPath);

        if (!$ffResult->successful() || !file_exists($outWav) || filesize($outWav) < 2000) {
            return back()->withErrors(['audio' => 'Audio extraction failed: ' . substr($ffResult->errorOutput(), -200)]);
        }

        Redis::del('voice-pool-id:' . md5($outWav));
        if ($refText) {
            Redis::setex('voice-pool-ref:' . md5($outWav), 30 * 86400, $refText);
        }
        Log::info("[VOICE POOL] Uploaded {$gender}/{$name} (start={$start}s, dur={$duration}s)");

        return back()->with('success', "Voice '{$name}' added to {$gender} pool ({$duration}s).");
    }

    public function saveRefText(Request $request, string $gender, string $name)
    {
        if (!in_array($gender, self::GENDERS)) abort(400);
        $request->validate(['ref_text' => 'nullable|string|max:500']);

        $file = storage_path("app/voice-pool/{$gender}/{$name}.wav");
        if (!file_exists($file)) {
            return response()->json(['error' => 'Voice not found'], 404);
        }

        $refText = $request->input('ref_text', '');
        if ($refText) {
            Redis::setex('voice-pool-ref:' . md5($file), 30 * 86400, $refText);
        } else {
            Redis::del('voice-pool-ref:' . md5($file));
        }
        // Force re-clone on next synthesis so TTS services pick up new ref_text
        Redis::del('voice-pool-id:' . md5($file));
        Redis::del('voice-pool-id:mms:' . md5($file));

        return response()->json(['ok' => true]);
    }

    public function saveSpeed(Request $request, string $gender, string $name)
    {
        if (!in_array($gender, self::GENDERS)) abort(400);
        $request->validate(['speed' => 'required|numeric|min:0.5|max:2.0']);

        $file = storage_path("app/voice-pool/{$gender}/{$name}.wav");
        if (!file_exists($file)) {
            return response()->json(['error' => 'Voice not found'], 404);
        }

        $this->writeSpeed($gender, $name, (float) $request->input('speed'));

        return response()->json(['ok' => true]);
    }

    public function test(Request $request)
    {
        $request->validate([
            'gender'     => 'required|in:male,female,child',
            'name'       => 'required|string|max:50|regex:/^[a-zA-Z0-9_-]+$/',
            'text'       => 'required|string|max:500',
            'language'   => 'required|string|max:10',
            'speed'      => 'nullable|numeric|min:0.5|max:2.0',
            'tau'        => 'nullable|numeric|min:0.1|max:1.0',
            'tts_engine' => 'nullable|in:f5tts,mms',
        ]);

        $gender = $request->input('gender');
        $name   = $request->input('name');
        $speed  = (float) ($request->input('speed') ?? $this->getSpeed($gender, $name));
        $file   = storage_path("app/voice-pool/{$gender}/{$name}.wav");

        if (!file_exists($file)) {
            return response()->json(['error' => 'Voice file not found'], 404);
        }

        $xttsUrl  = rtrim(config('services.mms_tts.url', env('MMS_TTS_SERVICE_URL')), '/');
        $cacheKey = 'voice-pool-id:mms:' . md5($file);

        $voiceId  = Redis::get($cacheKey);

        $manualRefText = Redis::get('voice-pool-ref:' . md5($file));

        $cloneIfNeeded = function () use ($xttsUrl, $file, $name, $request, $cacheKey, &$voiceId, $manualRefText) {
            $postData = ['name' => $name, 'language' => $request->input('language')];
            if ($manualRefText) {
                $postData['ref_text'] = $manualRefText;
            }
            $cloneResp = Http::timeout(60)
                ->attach('audio', file_get_contents($file), $name . '.wav')
                ->post("{$xttsUrl}/clone", $postData);

            if (!$cloneResp->successful()) {
                return response()->json(['error' => 'Clone failed: ' . $cloneResp->body()], 500);
            }

            $voiceId = $cloneResp->json('voice_id');
            Redis::setex($cacheKey, 7 * 86400, $voiceId);
            if ($refText = $cloneResp->json('ref_text')) {
                Redis::setex('voice-pool-ref:' . md5($file), 30 * 86400, $refText);
            }
            return null;
        };

        if (!$voiceId) {
            if ($err = $cloneIfNeeded()) return $err;
        }

        $tau = (float) ($request->input('tau', 0.9));
        $synthResp = Http::timeout(120)->post("{$xttsUrl}/synthesize", [
            'text'     => $request->input('text'),
            'voice_id' => $voiceId,
            'language' => $request->input('language'),
            'speed'    => $speed,
            'tau'      => $tau,
        ]);

        // Voice lost after pod restart — re-clone and retry once
        if ($synthResp->status() === 404 && str_contains($synthResp->body(), 'not found')) {
            Redis::del($cacheKey);
            if ($err = $cloneIfNeeded()) return $err;

            $synthResp = Http::timeout(120)->post("{$xttsUrl}/synthesize", [
                'text'     => $request->input('text'),
                'voice_id' => $voiceId,
                'language' => $request->input('language'),
                'speed'    => $speed,
                'tau'      => $tau,
            ]);
        }

        if (!$synthResp->successful()) {
            return response()->json(['error' => 'Synthesis failed: ' . $synthResp->body()], 500);
        }

        return response($synthResp->body(), 200, [
            'Content-Type'        => 'audio/wav',
            'Content-Disposition' => 'inline; filename="preview.wav"',
        ]);
    }

    public function play(string $gender, string $name)
    {
        if (!in_array($gender, self::GENDERS)) abort(400);
        $file = storage_path("app/voice-pool/{$gender}/{$name}.wav");
        if (!file_exists($file)) abort(404);
        return response()->file($file, ['Content-Type' => 'audio/wav']);
    }

    public function delete(string $gender, string $name)
    {
        if (!in_array($gender, self::GENDERS)) abort(400);

        $file = storage_path("app/voice-pool/{$gender}/{$name}.wav");
        if (file_exists($file)) {
            Redis::del('voice-pool-id:' . md5($file));
            Redis::del('voice-pool-id:mms:' . md5($file));
            unlink($file);
        }

        $json = storage_path("app/voice-pool/{$gender}/{$name}.json");
        if (file_exists($json)) unlink($json);

        return back()->with('success', "Voice '{$name}' deleted.");
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public static function getSpeed(string $gender, string $name): float
    {
        $json = storage_path("app/voice-pool/{$gender}/{$name}.json");
        if (file_exists($json)) {
            $data = json_decode(file_get_contents($json), true);
            return (float) ($data['speed'] ?? 1.0);
        }
        return 1.0;
    }

    private function writeSpeed(string $gender, string $name, float $speed): void
    {
        $json = storage_path("app/voice-pool/{$gender}/{$name}.json");
        $data = file_exists($json) ? (json_decode(file_get_contents($json), true) ?? []) : [];
        $data['speed'] = $speed;
        file_put_contents($json, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function getAudioDuration(string $path): string
    {
        $result = Process::timeout(5)->run([
            'ffprobe', '-hide_banner', '-loglevel', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1', $path,
        ]);
        $sec = (float) trim($result->output());
        return $sec > 0 ? round($sec, 1) . 's' : '?';
    }
}
