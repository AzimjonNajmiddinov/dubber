<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StreamDubController;
use App\Http\Controllers\RealtimeDubController;

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
