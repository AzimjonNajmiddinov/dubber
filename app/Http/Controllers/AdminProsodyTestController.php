<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AdminProsodyTestController extends Controller
{
    public function index()
    {
        return view('admin.prosody-test');
    }

    public function transfer(Request $request)
    {
        $request->validate([
            'tts_audio' => 'required|file|mimes:wav,mp3,ogg,webm|max:20480',
            'reference' => 'required|file|mimes:wav,mp3,ogg,webm|max:20480',
            'f0_mode'   => 'in:contour,stats',
        ]);

        $serviceUrl = rtrim(config('services.prosody_transfer.url'), '/');
        if (!$serviceUrl) {
            return response()->json(['error' => 'PROSODY_TRANSFER_SERVICE_URL not configured'], 503);
        }

        $response = Http::timeout(60)->attach(
            'tts_audio', file_get_contents($request->file('tts_audio')->getRealPath()), 'tts.wav'
        )->attach(
            'reference', file_get_contents($request->file('reference')->getRealPath()), 'ref.wav'
        )->post($serviceUrl . '/transfer', [
            'f0_mode'         => $request->input('f0_mode', 'contour'),
            'energy_transfer' => $request->boolean('energy_transfer', true) ? 'true' : 'false',
        ]);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Service error: ' . $response->status(),
                'detail' => $response->json('detail') ?? $response->body(),
            ], 502);
        }

        return response($response->body(), 200, [
            'Content-Type'        => 'audio/wav',
            'Content-Disposition' => 'inline; filename="prosody_result.wav"',
        ]);
    }
}
