<?php

namespace App\Http\Controllers;

use App\Jobs\PrepareInstantDubJob;
use App\Models\InstantDub;
use App\Models\InstantDubSegment;
use App\Models\InstantDubVoiceMap;
use App\Services\VoiceVariants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class AdminDubController extends Controller
{
    public function index(Request $request)
    {
        $query = InstantDub::query()->withCount('segments')->orderByDesc('updated_at');

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('video_url', 'like', "%{$search}%");
            });
        }

        if ($lang = $request->input('lang')) {
            $query->where('language', $lang);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $dubs = $query->paginate(25)->withQueryString();

        return view('admin.dubs.index', compact('dubs'));
    }

    public function show(InstantDub $dub)
    {
        $dub->load('segments', 'voiceMap');

        $voiceVariants = $this->buildVoiceOptions($dub->language, $dub->tts_driver ?? 'edge');

        return view('admin.dubs.show', compact('dub', 'voiceVariants'));
    }

    public function updateSegment(Request $request, InstantDub $dub, InstantDubSegment $segment)
    {
        abort_if($segment->instant_dub_id !== $dub->id, 404);

        $request->validate([
            'speaker'         => 'nullable|string|max:20',
            'translated_text' => 'nullable|string',
            'approved'        => 'nullable|boolean',
        ]);

        $changed = false;
        $oldSpeaker = $segment->speaker;
        $voiceMapChanged = false;

        if ($request->has('speaker') && $request->input('speaker') !== $segment->speaker) {
            $segment->speaker = $request->input('speaker');
            $changed = true;
        }

        if ($request->has('translated_text') && $request->input('translated_text') !== $segment->translated_text) {
            $segment->translated_text = $request->input('translated_text');
            $changed = true;
        }

        if ($request->has('approved')) {
            $segment->approved = (bool) $request->input('approved');
            $segment->save();
            return response()->json(['ok' => true, 'changed' => false]);
        }

        if ($changed) {
            $segment->needs_retts = true;
            $segment->approved = false;

            if ($segment->aac_path && file_exists($segment->aac_path)) {
                unlink($segment->aac_path);
            }
            $segment->aac_path = null;
            $segment->save();

            if ($dub->status === 'complete') {
                $dub->update(['status' => 'needs_retts']);
            }

            // Sync voice map: add new speaker, remove unused old speaker
            if ($request->has('speaker')) {
                $voiceMapChanged = $this->syncVoiceMapSpeaker($dub, $oldSpeaker, $segment->speaker);
            }
        }

        $voiceMap = null;
        if ($voiceMapChanged) {
            $voiceMap = InstantDubVoiceMap::where('instant_dub_id', $dub->id)
                ->orderBy('speaker_tag')->get()
                ->map(fn($vm) => [
                    'speaker' => $vm->speaker_tag,
                    'config'  => $vm->voice_config,
                ])->values()->all();
        }

        return response()->json(['ok' => true, 'changed' => $changed, 'voiceMapChanged' => $voiceMapChanged, 'voiceMap' => $voiceMap]);
    }

    private function syncVoiceMapSpeaker(InstantDub $dub, string $oldSpeaker, string $newSpeaker): bool
    {
        $changed = false;

        // Add new speaker to voice map if missing
        $exists = InstantDubVoiceMap::where('instant_dub_id', $dub->id)
            ->where('speaker_tag', $newSpeaker)->exists();

        if (!$exists) {
            $config = $this->defaultVoiceConfig($dub, $newSpeaker);
            InstantDubVoiceMap::create([
                'instant_dub_id' => $dub->id,
                'speaker_tag'    => $newSpeaker,
                'voice_config'   => $config,
            ]);
            $changed = true;
        }

        // Remove old speaker if no segments use it anymore
        if ($oldSpeaker !== $newSpeaker) {
            $stillUsed = InstantDubSegment::where('instant_dub_id', $dub->id)
                ->where('speaker', $oldSpeaker)->exists();
            if (!$stillUsed) {
                InstantDubVoiceMap::where('instant_dub_id', $dub->id)
                    ->where('speaker_tag', $oldSpeaker)->delete();
                $changed = true;
            }
        }

        return $changed;
    }

    private function defaultVoiceConfig(InstantDub $dub, string $speaker): array
    {
        $driver = $dub->tts_driver ?? 'edge';
        $gender = str_starts_with($speaker, 'F') ? 'female'
            : (str_starts_with($speaker, 'C') ? 'child' : 'male');

        if ($driver === 'mms') {
            $dir   = storage_path("app/voice-pool/{$gender}");
            $files = is_dir($dir) ? glob("{$dir}/*.{wav,mp3,m4a}", GLOB_BRACE) : [];
            if (empty($files) && $gender !== 'male') {
                $dir   = storage_path('app/voice-pool/male');
                $files = is_dir($dir) ? glob("{$dir}/*.{wav,mp3,m4a}", GLOB_BRACE) : [];
            }
            if (!empty($files)) {
                $name = pathinfo($files[0], PATHINFO_FILENAME);
                return ['driver' => 'mms', 'gender' => $gender, 'pool_name' => $name];
            }
        }

        $variants = VoiceVariants::forLanguage($dub->language);
        $pool = $variants[$gender] ?? $variants['male'] ?? [];
        return $pool[0] ?? ['voice' => 'uz-UZ-SardorNeural', 'rate' => '+0%', 'pitch' => '+0Hz'];
    }

    public function audioSegment(InstantDub $dub, InstantDubSegment $segment)
    {
        abort_if($segment->instant_dub_id !== $dub->id, 404);

        // Try TTS mp3 first (new format)
        $path = $segment->tts_path;
        $mime = 'audio/mpeg';

        if (!$path || !file_exists($path)) {
            // Fall back to legacy mixed AAC
            $path = $segment->aac_path;
            $mime = 'audio/aac';
        }

        if (!$path || !file_exists($path) || filesize($path) < 10) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type'  => $mime,
            'Cache-Control' => 'no-store',
        ]);
    }

    public function rettsDub(Request $request, InstantDub $dub)
    {
        $sessionId = Str::uuid()->toString();
        $videoUrl  = $dub->video_url;

        $session = [
            'id'             => $sessionId,
            'title'          => $dub->title,
            'language'       => $dub->language,
            'video_url'      => $videoUrl,
            'video_base_url' => preg_replace('#/[^/]+$#', '/', $videoUrl),
            'video_query'    => '',
            'status'         => 'preparing',
            'tts_driver'     => $dub->tts_driver,
            'total_segments' => 0,
            'segments_ready' => 0,
            'cached_dub_id'  => $dub->id,
            'created_at'     => now()->toIso8601String(),
        ];

        Redis::setex("instant-dub:{$sessionId}", 50400, json_encode($session));

        $dub->update(['status' => 'processing', 'session_id' => $sessionId]);

        PrepareInstantDubJob::dispatch(
            $sessionId,
            $videoUrl,
            $dub->language,
            $dub->translate_from ?? '',
            '',
            $dub->id,
        )->onQueue('segment-generation');

        return response()->json(['ok' => true, 'session_id' => $sessionId]);
    }

    public function rettsStatus(InstantDub $dub)
    {
        $sessionId = $dub->session_id;
        if (!$sessionId) {
            return response()->json(['status' => $dub->status, 'ready' => 0, 'total' => 0]);
        }

        $sessionJson = Redis::get("instant-dub:{$sessionId}");
        if (!$sessionJson) {
            return response()->json(['status' => $dub->status, 'ready' => 0, 'total' => 0]);
        }

        $session = json_decode($sessionJson, true);

        return response()->json([
            'status' => $session['status'] ?? 'processing',
            'ready'  => $session['segments_ready'] ?? 0,
            'total'  => $session['total_segments'] ?? 0,
        ]);
    }

    public function updateVoiceMap(Request $request, InstantDub $dub)
    {
        $request->validate([
            'voices'           => 'required|array',
            'voices.*.speaker' => 'required|string|max:20',
            'voices.*.config'  => 'required|array',
        ]);

        foreach ($request->input('voices') as $item) {
            InstantDubVoiceMap::updateOrCreate(
                ['instant_dub_id' => $dub->id, 'speaker_tag' => $item['speaker']],
                ['voice_config' => $item['config']],
            );
        }

        // Mark all segments for this dub as needs_retts
        $dub->segments()->update(['needs_retts' => true, 'approved' => false]);

        if ($dub->aac_dir && is_dir($dub->aac_dir)) {
            foreach (glob($dub->aac_dir . '/*.{aac,mp3}', GLOB_BRACE) as $f) {
                if (!in_array(basename($f), ['lead.aac', 'tail.aac'])) {
                    @unlink($f);
                }
            }
        }

        $dub->update(['status' => 'needs_retts']);

        return response()->json(['ok' => true]);
    }

    public function destroy(InstantDub $dub)
    {
        if ($dub->aac_dir && is_dir($dub->aac_dir)) {
            $this->deleteDir(dirname($dub->aac_dir));
        }

        $dub->delete();

        return redirect()->route('admin.dubs.index')->with('success', 'Deleted.');
    }

    private function buildVoiceOptions(string $language, string $driver = 'edge'): array
    {
        if ($driver === 'mms') {
            return $this->buildMmsVoiceOptions();
        }

        $variants = VoiceVariants::forLanguage($language);
        $options  = [];

        foreach (['male', 'female', 'child'] as $group) {
            foreach ($variants[$group] ?? [] as $i => $v) {
                $label = ucfirst($group) . ' ' . ($i + 1);
                if (!empty($v['pitch']) && $v['pitch'] !== '+0Hz') {
                    $label .= ' (' . $v['pitch'] . ')';
                }
                $options[] = ['label' => $label, 'config' => $v];
            }
        }

        return $options;
    }

    private function buildMmsVoiceOptions(): array
    {
        $options = [];
        foreach (['male', 'female', 'child'] as $gender) {
            $dir   = storage_path("app/voice-pool/{$gender}");
            $files = is_dir($dir) ? glob("{$dir}/*.{wav,mp3,m4a}", GLOB_BRACE) : [];
            foreach ($files as $file) {
                $name    = pathinfo($file, PATHINFO_FILENAME);
                $options[] = [
                    'label'  => ucfirst($gender) . ' — ' . $name,
                    'config' => ['driver' => 'mms', 'gender' => $gender, 'pool_name' => $name],
                ];
            }
        }
        return $options;
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $f) {
            $path = "{$dir}/{$f}";
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
