<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StreamDubController;
use App\Http\Controllers\SegmentPlayerController;
use App\Http\Controllers\LiveDubController;
use App\Http\Controllers\InstantDubController;
use App\Http\Controllers\PremiumDubController;

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

// Flow 1 (Instant Dub)
Route::prefix('instant-dub')->group(function () {
    $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    Route::get('/voices', [InstantDubController::class, 'voices'])->name('api.instant-dub.voices');
    Route::post('/start', [InstantDubController::class, 'start'])->middleware('throttle:20,1')->name('api.instant-dub.start');
    Route::get('/{sessionId}/poll', [InstantDubController::class, 'poll'])->where('sessionId', $uuid)->name('api.instant-dub.poll');
    Route::post('/{sessionId}/stop', [InstantDubController::class, 'stop'])->where('sessionId', $uuid)->name('api.instant-dub.stop');
    Route::get('/{sessionId}/events', [InstantDubController::class, 'events'])->where('sessionId', $uuid)->name('api.instant-dub.events');
    Route::get('/{sessionId}/master.m3u8', [InstantDubController::class, 'hlsMaster'])->where('sessionId', $uuid)->name('api.instant-dub.master');
    Route::get('/{sessionId}/dub-audio.m3u8', [InstantDubController::class, 'hlsAudioPlaylist'])->where('sessionId', $uuid)->name('api.instant-dub.dub-audio');
    Route::get('/{sessionId}/dub-segment/init.mp4', [InstantDubController::class, 'hlsInitSegment'])->where('sessionId', $uuid)->name('api.instant-dub.dub-init');
    Route::get('/{sessionId}/dub-segment/lead.ts', [InstantDubController::class, 'hlsLeadSegment'])->where('sessionId', $uuid)->name('api.instant-dub.dub-lead-ts');
    Route::get('/{sessionId}/dub-segment/source-bg-{index}.ts', [InstantDubController::class, 'hlsBgSourceSegment'])->where('sessionId', $uuid)->whereNumber('index')->name('api.instant-dub.dub-bg-source-ts');
    Route::get('/{sessionId}/dub-segment/source-bg-{index}-to-{offsetMs}.ts', [InstantDubController::class, 'hlsBgSourceSliceSegment'])->where('sessionId', $uuid)->whereNumber('index')->whereNumber('offsetMs')->name('api.instant-dub.dub-bg-source-slice-ts');
    Route::get('/{sessionId}/dub-segment/bg-{index}-from-{offsetMs}.ts', [InstantDubController::class, 'hlsBgSliceSegment'])->where('sessionId', $uuid)->whereNumber('index')->whereNumber('offsetMs')->name('api.instant-dub.dub-bg-slice-ts');
    Route::get('/{sessionId}/dub-segment/bg-{index}.ts', [InstantDubController::class, 'hlsBgSegment'])->where('sessionId', $uuid)->whereNumber('index')->name('api.instant-dub.dub-bg-ts');
    Route::get('/{sessionId}/dub-segment/tail.ts', [InstantDubController::class, 'hlsTailSegment'])->where('sessionId', $uuid)->name('api.instant-dub.dub-tail-ts');
    Route::get('/{sessionId}/dub-segment/gap-{index}.ts', [InstantDubController::class, 'hlsGapSegment'])->where('sessionId', $uuid)->whereNumber('index')->name('api.instant-dub.dub-gap-ts');
    Route::get('/{sessionId}/dub-segment/lead.aac', [InstantDubController::class, 'hlsLeadSegment'])->where('sessionId', $uuid)->name('api.instant-dub.dub-lead');
    Route::get('/{sessionId}/dub-segment/source-bg-{index}.aac', [InstantDubController::class, 'hlsBgSourceSegment'])->where('sessionId', $uuid)->whereNumber('index')->name('api.instant-dub.dub-bg-source');
    Route::get('/{sessionId}/dub-segment/source-bg-{index}-to-{offsetMs}.aac', [InstantDubController::class, 'hlsBgSourceSliceSegment'])->where('sessionId', $uuid)->whereNumber('index')->whereNumber('offsetMs')->name('api.instant-dub.dub-bg-source-slice');
    Route::get('/{sessionId}/dub-segment/bg-{index}-from-{offsetMs}.aac', [InstantDubController::class, 'hlsBgSliceSegment'])->where('sessionId', $uuid)->whereNumber('index')->whereNumber('offsetMs')->name('api.instant-dub.dub-bg-slice');
    Route::get('/{sessionId}/dub-segment/bg-{index}.aac', [InstantDubController::class, 'hlsBgSegment'])->where('sessionId', $uuid)->whereNumber('index')->name('api.instant-dub.dub-bg');
    Route::get('/{sessionId}/dub-segment/tail.aac', [InstantDubController::class, 'hlsTailSegment'])->where('sessionId', $uuid)->name('api.instant-dub.dub-tail');
    Route::get('/{sessionId}/dub-segment/gap-{index}.aac', [InstantDubController::class, 'hlsGapSegment'])->where('sessionId', $uuid)->whereNumber('index')->name('api.instant-dub.dub-gap');
    Route::get('/{sessionId}/dub-segment/{index}.aac', [InstantDubController::class, 'hlsAudioSegment'])->where('sessionId', $uuid)->whereNumber('index')->name('api.instant-dub.dub-segment');
    Route::get('/{sessionId}/dub-subtitles.m3u8', [InstantDubController::class, 'hlsSubtitlePlaylist'])->where('sessionId', $uuid)->name('api.instant-dub.dub-subtitles');
    Route::get('/{sessionId}/dub-subtitles.vtt', [InstantDubController::class, 'hlsSubtitleVtt'])->where('sessionId', $uuid)->name('api.instant-dub.dub-subtitles-vtt');
    Route::get('/{sessionId}/proxy/{path}', [InstantDubController::class, 'hlsProxy'])->where('sessionId', $uuid)->where('path', '.*')->name('api.instant-dub.proxy');
});
