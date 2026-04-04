<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

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
                $pool[] = [
                    'gender' => $gender,
                    'name'   => pathinfo($file, PATHINFO_FILENAME),
                    'size'   => round(filesize($file) / 1024) . ' KB',
                    'duration' => $this->getAudioDuration($file),
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
        ]);

        $gender   = $request->input('gender');
        $name     = $request->input('name');
        $url      = $request->input('youtube_url');
        $start    = (int) ($request->input('start', 0));
        $duration = (int) ($request->input('duration', 25));

        $dir = storage_path("app/voice-pool/{$gender}");
        @mkdir($dir, 0775, true);

        $tmpFile = storage_path("app/voice-pool/tmp_{$name}_" . time());
        $outWav  = "{$dir}/{$name}.wav";

        // Download best audio with yt-dlp
        $dlResult = Process::timeout(120)->run([
            'yt-dlp',
            '--no-playlist',
            '-x', '--audio-format', 'wav',
            '-o', $tmpFile . '.%(ext)s',
            $url,
        ]);

        $downloaded = $tmpFile . '.wav';

        // yt-dlp may produce webm/m4a first then convert — find what was created
        if (!file_exists($downloaded)) {
            foreach (glob($tmpFile . '.*') as $f) {
                $downloaded = $f;
                break;
            }
        }

        if (!file_exists($downloaded)) {
            return back()->withErrors(['youtube_url' => 'Download failed: ' . substr($dlResult->errorOutput(), -300)]);
        }

        // Extract the requested segment
        $ffResult = Process::timeout(30)->run([
            'ffmpeg', '-y',
            '-ss', (string) $start,
            '-t',  (string) $duration,
            '-i',  $downloaded,
            '-af', 'highpass=f=80,lowpass=f=12000,afftdn=nf=-20',
            '-ac', '1', '-ar', '22050', '-c:a', 'pcm_s16le',
            $outWav,
        ]);

        @unlink($downloaded);

        if (!$ffResult->successful() || !file_exists($outWav) || filesize($outWav) < 2000) {
            return back()->withErrors(['youtube_url' => 'Audio extraction failed: ' . substr($ffResult->errorOutput(), -200)]);
        }

        // Invalidate cached voice_id for this file if it exists
        $cacheKey = 'voice-pool-id:' . md5($outWav);
        \Illuminate\Support\Facades\Redis::del($cacheKey);

        Log::info("[VOICE POOL] Added {$gender}/{$name} from {$url} (start={$start}s, dur={$duration}s)");

        return back()->with('success', "Voice '{$name}' added to {$gender} pool ({$duration}s).");
    }

    public function upload(Request $request)
    {
        $request->validate([
            'audio'    => 'required|file|mimes:wav,mp3,ogg,flac,m4a,webm|max:51200',
            'gender'   => 'required|in:male,female,child',
            'name'     => 'required|string|max:50|regex:/^[a-zA-Z0-9_-]+$/',
            'start'    => 'nullable|integer|min:0',
            'duration' => 'nullable|integer|min:5|max:60',
        ]);

        $gender   = $request->input('gender');
        $name     = $request->input('name');
        $start    = (int) ($request->input('start', 0));
        $duration = (int) ($request->input('duration', 25));

        $dir = storage_path("app/voice-pool/{$gender}");
        @mkdir($dir, 0775, true);

        $tmpFile = $request->file('audio')->store('voice-pool/tmp');
        $tmpPath = storage_path("app/{$tmpFile}");
        $outWav  = "{$dir}/{$name}.wav";

        $ffResult = Process::timeout(30)->run([
            'ffmpeg', '-y',
            '-ss', (string) $start,
            '-t',  (string) $duration,
            '-i',  $tmpPath,
            '-af', 'highpass=f=80,lowpass=f=12000,afftdn=nf=-20',
            '-ac', '1', '-ar', '22050', '-c:a', 'pcm_s16le',
            $outWav,
        ]);

        @unlink($tmpPath);

        if (!$ffResult->successful() || !file_exists($outWav) || filesize($outWav) < 2000) {
            return back()->withErrors(['audio' => 'Audio extraction failed: ' . substr($ffResult->errorOutput(), -200)]);
        }

        $cacheKey = 'voice-pool-id:' . md5($outWav);
        \Illuminate\Support\Facades\Redis::del($cacheKey);

        Log::info("[VOICE POOL] Uploaded {$gender}/{$name} (start={$start}s, dur={$duration}s)");

        return back()->with('success', "Voice '{$name}' added to {$gender} pool ({$duration}s).");
    }

    public function delete(string $gender, string $name)
    {
        if (!in_array($gender, self::GENDERS)) abort(400);

        $file = storage_path("app/voice-pool/{$gender}/{$name}.wav");
        if (file_exists($file)) {
            $cacheKey = 'voice-pool-id:' . md5($file);
            \Illuminate\Support\Facades\Redis::del($cacheKey);
            unlink($file);
        }

        return back()->with('success', "Voice '{$name}' deleted.");
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
