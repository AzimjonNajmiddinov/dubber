<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StreamDubController;
use App\Http\Controllers\RealtimeDubController;
use App\Http\Controllers\SegmentPlayerController;
use App\Http\Controllers\LiveDubController;

// URL-based video dubbing & streaming (no CSRF required)
Route::prefix('stream')->group(function () {
    Route::post('/dub', [StreamDubController::class, 'dubFromUrl'])->name('api.stream.dub');
    Route::get('/{video}/status', [StreamDubController::class, 'status'])->name('api.stream.status');
    Route::get('/{video}/info', [StreamDubController::class, 'info'])->name('api.stream.info');
    Route::get('/{video}/watch', [StreamDubController::class, 'stream'])->name('api.stream.watch');
});

// Real-time dubbing API (for browser extension)
Route::prefix('realtime')->group(function () {
    Route::post('/session', [RealtimeDubController::class, 'initSession'])->name('api.realtime.session');
    Route::post('/session/{sessionId}/chunk', [RealtimeDubController::class, 'processChunk'])->name('api.realtime.chunk');
    Route::post('/session/{sessionId}/clone-voice', [RealtimeDubController::class, 'cloneVoice'])->name('api.realtime.clone');
    Route::get('/voices', [RealtimeDubController::class, 'getVoices'])->name('api.realtime.voices');
});

// Segment player API
Route::prefix('player')->group(function () {
    Route::get('/{video}/manifest', [SegmentPlayerController::class, 'manifest'])->name('api.player.manifest');
    Route::get('/{video}/segment/{segment}', [SegmentPlayerController::class, 'streamSegment'])->name('api.player.segment');
    Route::post('/{video}/prefetch', [SegmentPlayerController::class, 'prefetch'])->name('api.player.prefetch');
    Route::get('/{video}/segment/{segment}/status', [SegmentPlayerController::class, 'segmentStatus'])->name('api.player.segment.status');

    // HLS streaming endpoints
    Route::get('/{video}/hls/playlist.m3u8', [SegmentPlayerController::class, 'hlsPlaylist'])->name('api.player.hls.playlist');
    Route::get('/{video}/hls/segment/{segment}.ts', [SegmentPlayerController::class, 'hlsSegment'])->name('api.player.hls.segment');

    // Download endpoints
    Route::get('/{video}/download-status', [SegmentPlayerController::class, 'downloadStatus'])->name('api.player.download.status');
    Route::get('/{video}/chunk/{index}/download', [SegmentPlayerController::class, 'downloadChunk'])->name('api.player.chunk.download');
    Route::get('/{video}/download', [SegmentPlayerController::class, 'downloadFull'])->name('api.player.download');
});

// Live streaming dubbing API
Route::prefix('live')->group(function () {
    Route::post('/start', [LiveDubController::class, 'start'])->name('api.live.start');
    Route::get('/{video}/status', [LiveDubController::class, 'status'])->name('api.live.status');
});
