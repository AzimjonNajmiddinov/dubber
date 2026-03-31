<?php

namespace App\Http\Controllers;

use App\Models\InstantDub;
use App\Models\InstantDubSegment;
use Illuminate\Http\Request;

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

        return view('admin.dubs.show', compact('dub'));
    }

    public function updateSegment(Request $request, InstantDub $dub, InstantDubSegment $segment)
    {
        abort_if($segment->instant_dub_id !== $dub->id, 404);

        $request->validate([
            'speaker'          => 'nullable|string|max:20',
            'translated_text'  => 'nullable|string',
        ]);

        $changed = false;

        if ($request->has('speaker') && $request->input('speaker') !== $segment->speaker) {
            $segment->speaker = $request->input('speaker');
            $changed = true;
        }

        if ($request->has('translated_text') && $request->input('translated_text') !== $segment->translated_text) {
            $segment->translated_text = $request->input('translated_text');
            $changed = true;
        }

        if ($changed) {
            $segment->needs_retts = true;

            // Delete cached AAC for this segment so it gets re-generated
            if ($segment->aac_path && file_exists($segment->aac_path)) {
                unlink($segment->aac_path);
            }
            $segment->aac_path = null;
            $segment->save();

            // Mark dub as needs_retts if it was complete
            if ($dub->status === 'complete') {
                $dub->update(['status' => 'needs_retts']);
            }
        }

        return response()->json(['ok' => true, 'changed' => $changed]);
    }

    public function destroy(InstantDub $dub)
    {
        // Delete AAC directory
        if ($dub->aac_dir && is_dir($dub->aac_dir)) {
            $this->deleteDir(dirname($dub->aac_dir));
        }

        $dub->delete();

        return redirect()->route('admin.dubs.index')->with('success', 'Deleted.');
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
