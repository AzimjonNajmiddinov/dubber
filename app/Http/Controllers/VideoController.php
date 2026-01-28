<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractAudioJob;
use App\Jobs\GenerateTtsSegmentsJob;
use App\Jobs\MixDubbedAudioJob;
use App\Jobs\ReplaceVideoAudioJob;
use App\Models\Speaker;
use App\Models\Video;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VideoController extends Controller
{
    public function index(): Factory|View
    {
        // List newest first
        $videos = Video::query()->orderByDesc('id')->limit(50)->get();

        return view('videos.index', compact('videos'));
    }

    public function upload(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimes:mp4,mkv,avi|max:204800', // 200MB
            'target_language' => 'required|string',
        ]);

        $path = $request->file('video')->store('videos/originals', 'local');

        if (!Storage::disk('local')->exists($path)) {
            abort(500, 'File was not saved to disk');
        }

        $video = Video::create([
            'original_path' => $path,
            'target_language' => $request->target_language,
            'status' => 'uploaded',
        ]);

        ExtractAudioJob::dispatch($video->id);

        return redirect()->route('videos.index')->with('success', 'Video yuklandi, dubbing boshlandi');
    }

    public function show(Video $video): Factory|View
    {
        return view('videos.show', compact('video'));
    }

    public function status(Video $video): JsonResponse
    {
        [$progress, $label, $canDownload] = $this->statusMeta($video);

        return response()->json([
            'status' => $video->status,
            'label' => $label,
            'progress' => $progress,
            'can_download' => $canDownload,
            'download_url' => $canDownload ? route('videos.download', $video) : null,
            'download_lipsynced_url' => $video->lipsynced_path ? route('videos.download.lipsynced', $video) : null,
            'dubbed_path' => $video->dubbed_path,
            'lipsynced_path' => $video->lipsynced_path,
        ]);
    }

    public function download(Video $video): StreamedResponse
    {
        if (!$video->dubbed_path) {
            abort(404, 'Dubbed file not ready');
        }

        if (!in_array($video->status, ['dubbed_complete', 'lipsync_processing', 'lipsync_done', 'done'], true)) {
            abort(403, 'Dubbed file not ready');
        }

        if (!Storage::disk('local')->exists($video->dubbed_path)) {
            abort(404, 'Dubbed file missing on disk');
        }

        $downloadName = "dubbed_video_{$video->id}.mp4";

        return Storage::disk('local')->download($video->dubbed_path, $downloadName);
    }

    public function downloadLipsynced(Video $video): StreamedResponse
    {
        if (!$video->lipsynced_path) {
            abort(404, 'Lipsynced file not ready');
        }

        if ($video->status !== 'lipsync_done') {
            abort(403, 'Lipsynced file not ready');
        }

        if (!Storage::disk('local')->exists($video->lipsynced_path)) {
            abort(404, 'Lipsynced file missing on disk');
        }

        $downloadName = "lipsynced_video_{$video->id}.mp4";

        return Storage::disk('local')->download($video->lipsynced_path, $downloadName);
    }

    /**
     * Get available TTS voices grouped by language and gender.
     */
    public function voices(): JsonResponse
    {
        // Curated list of best voices for dubbing
        $voices = [
            'uz' => [
                'male' => [
                    ['id' => 'uz-UZ-SardorNeural', 'name' => 'Sardor (Uzbek Male)', 'gender' => 'male'],
                ],
                'female' => [
                    ['id' => 'uz-UZ-MadinaNeural', 'name' => 'Madina (Uzbek Female)', 'gender' => 'female'],
                ],
            ],
            'ru' => [
                'male' => [
                    ['id' => 'ru-RU-DmitryNeural', 'name' => 'Dmitry (Russian Male)', 'gender' => 'male'],
                ],
                'female' => [
                    ['id' => 'ru-RU-SvetlanaNeural', 'name' => 'Svetlana (Russian Female)', 'gender' => 'female'],
                ],
            ],
            'en' => [
                'male' => [
                    ['id' => 'en-US-GuyNeural', 'name' => 'Guy (US Male)', 'gender' => 'male'],
                    ['id' => 'en-US-ChristopherNeural', 'name' => 'Christopher (US Male)', 'gender' => 'male'],
                    ['id' => 'en-GB-RyanNeural', 'name' => 'Ryan (UK Male)', 'gender' => 'male'],
                    ['id' => 'en-AU-WilliamMultilingualNeural', 'name' => 'William (AU Male)', 'gender' => 'male'],
                ],
                'female' => [
                    ['id' => 'en-US-JennyNeural', 'name' => 'Jenny (US Female)', 'gender' => 'female'],
                    ['id' => 'en-US-AriaNeural', 'name' => 'Aria (US Female)', 'gender' => 'female'],
                    ['id' => 'en-GB-SoniaNeural', 'name' => 'Sonia (UK Female)', 'gender' => 'female'],
                    ['id' => 'en-AU-NatashaNeural', 'name' => 'Natasha (AU Female)', 'gender' => 'female'],
                ],
            ],
        ];

        return response()->json($voices);
    }

    /**
     * Get speakers for a video with full details.
     */
    public function speakers(Video $video): JsonResponse
    {
        return response()->json(
            $video->speakers->map(fn ($s) => [
                'id' => $s->id,
                'external_key' => $s->external_key,
                'label' => $s->label,
                'gender' => $s->gender,
                'gender_confidence' => $s->gender_confidence,
                'age_group' => $s->age_group,
                'emotion' => $s->emotion,
                'tts_voice' => $s->tts_voice,
                'tts_rate' => $s->tts_rate,
                'tts_pitch' => $s->tts_pitch,
                'tts_gain_db' => $s->tts_gain_db,
            ])
        );
    }

    /**
     * Update a speaker's TTS settings.
     */
    public function updateSpeaker(Request $request, Video $video, Speaker $speaker): JsonResponse
    {
        // Ensure speaker belongs to video
        if ($speaker->video_id !== $video->id) {
            abort(403, 'Speaker does not belong to this video');
        }

        $validated = $request->validate([
            'label' => 'sometimes|string|max:100',
            'tts_voice' => 'sometimes|string|max:100',
            'tts_rate' => 'sometimes|string|max:20',
            'tts_pitch' => 'sometimes|string|max:20',
            'tts_gain_db' => 'sometimes|numeric|min:-10|max:10',
            'gender' => 'sometimes|in:male,female,unknown',
            'emotion' => 'sometimes|string|max:50',
        ]);

        $speaker->update($validated);

        return response()->json([
            'success' => true,
            'speaker' => [
                'id' => $speaker->id,
                'external_key' => $speaker->external_key,
                'label' => $speaker->label,
                'gender' => $speaker->gender,
                'tts_voice' => $speaker->tts_voice,
                'tts_rate' => $speaker->tts_rate,
                'tts_pitch' => $speaker->tts_pitch,
                'tts_gain_db' => $speaker->tts_gain_db,
                'emotion' => $speaker->emotion,
            ],
        ]);
    }

    /**
     * Regenerate dubbing with updated speaker settings.
     */
    public function regenerateDubbing(Video $video): JsonResponse
    {
        // Only allow regeneration if video has been through TTS at least once
        if (!in_array($video->status, ['tts_generated', 'mixed', 'dubbed_complete', 'lipsync_processing', 'lipsync_done', 'done'])) {
            return response()->json([
                'success' => false,
                'message' => 'Video must complete initial dubbing before regeneration',
            ], 400);
        }

        // Reset status and dispatch TTS job
        $video->update(['status' => 'translated']);

        GenerateTtsSegmentsJob::dispatch($video->id);

        return response()->json([
            'success' => true,
            'message' => 'Dubbing regeneration started',
        ]);
    }

    public function segments(Video $video): JsonResponse
    {
        return response()->json(
            $video->segments()
                ->with('speaker')
                ->orderBy('start_time')
                ->get()
                ->map(fn ($seg) => [
                    'start' => $seg->start_time,
                    'end' => $seg->end_time,
                    'text' => $seg->text,
                    'translated_text' => $seg->translated_text,
                    'speaker' => [
                        'key' => $seg->speaker->external_key ?? 'unknown',
                        'gender' => $seg->speaker->gender ?? 'unknown',
                        'voice' => $seg->speaker->tts_voice ?? 'default',
                    ]
                ])
        );
    }

    private function statusMeta(Video $video): array
    {
        // Harmonize statuses from your pipeline
        $status = (string) $video->status;

        return match ($status) {
            'uploaded' => [5, 'Uploaded', false],
            'audio_extracted' => [15, 'Audio extracted', false],
            'stems_separated' => [25, 'Stems separated', false],
            'transcribed' => [35, 'Transcribed', false],
            'translated' => [50, 'Translated', false],
            'tts_generated' => [60, 'TTS generated', false],
            'mixed' => [70, 'Audio mixed', false],
            'dubbed_complete' => [80, 'Dubbed complete', (bool) $video->dubbed_path],
            'lipsync_processing' => [90, 'Lipsync processing', (bool) $video->dubbed_path],
            'lipsync_done' => [100, 'Lipsync complete', true],
            'done' => [100, 'Complete', true],
            default => [0, $status ?: 'unknown', false],
        };
    }
}
