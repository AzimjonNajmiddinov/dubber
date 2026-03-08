<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StreamDubController;
use App\Http\Controllers\SegmentPlayerController;
use App\Http\Controllers\LiveDubController;
use App\Http\Controllers\InstantDubController;

// URL-based video dubbing & streaming (no CSRF required)
Route::prefix('stream')->group(function () {
    Route::post('/dub', [StreamDubController::class, 'dubFromUrl'])->name('api.stream.dub');
    Route::get('/{video}/status', [StreamDubController::class, 'status'])->name('api.stream.status');
    Route::get('/{video}/info', [StreamDubController::class, 'info'])->name('api.stream.info');
    Route::get('/{video}/watch', [StreamDubController::class, 'stream'])->name('api.stream.watch');
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

// Instant dub API (SRT → TTS → play over video)
Route::prefix('instant-dub')->group(function () {
    Route::post('/start', [InstantDubController::class, 'start'])->name('api.instant-dub.start');
    Route::get('/{sessionId}/poll', [InstantDubController::class, 'poll'])->name('api.instant-dub.poll');
    Route::post('/{sessionId}/stop', [InstantDubController::class, 'stop'])->name('api.instant-dub.stop');
    Route::get('/{sessionId}/events', [InstantDubController::class, 'events'])->name('api.instant-dub.events');

    // HLS endpoints for PlayerKit integration
    Route::get('/{sessionId}/master.m3u8', [InstantDubController::class, 'hlsMaster'])->name('api.instant-dub.master');
    Route::get('/{sessionId}/dub-audio.m3u8', [InstantDubController::class, 'hlsAudioPlaylist'])->name('api.instant-dub.dub-audio');
    Route::get('/{sessionId}/dub-segment/{index}.aac', [InstantDubController::class, 'hlsAudioSegment'])->name('api.instant-dub.dub-segment');
    Route::get('/{sessionId}/dub-subtitles.m3u8', [InstantDubController::class, 'hlsSubtitlePlaylist'])->name('api.instant-dub.dub-subtitles');
    Route::get('/{sessionId}/dub-subtitles.vtt', [InstantDubController::class, 'hlsSubtitleVtt'])->name('api.instant-dub.dub-subtitles-vtt');
    Route::get('/{sessionId}/proxy/{path}', [InstantDubController::class, 'hlsProxy'])->where('path', '.*')->name('api.instant-dub.proxy');
});
