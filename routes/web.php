<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDubController;
use App\Http\Controllers\AdminUserController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\StreamDubController;
use App\Http\Controllers\SegmentPlayerController;
use App\Http\Controllers\LiveDubController;
use App\Http\Controllers\OnlineDubController;

Route::get('/', [VideoController::class, 'index'])->name('videos.index');
Route::post('/upload', [VideoController::class, 'upload'])->name('videos.upload');

Route::get('/videos', [VideoController::class, 'list'])->name('videos.list');
Route::get('/videos/{video}', [VideoController::class, 'show'])->name('videos.show');

Route::get('/videos/{video}/status', [VideoController::class, 'status'])->name('videos.status');
Route::get('/videos/{video}/segments', [VideoController::class, 'segments'])->name('videos.segments');
Route::get('/videos/{video}/speakers', [VideoController::class, 'speakers'])->name('videos.speakers');

// Speaker voice management
Route::get('/api/voices', [VideoController::class, 'voices'])->name('api.voices');
Route::put('/videos/{video}/speakers/{speaker}', [VideoController::class, 'updateSpeaker'])->name('videos.speakers.update');
Route::post('/videos/{video}/regenerate', [VideoController::class, 'regenerateDubbing'])->name('videos.regenerate');

// Optional: download via controller (recommended)
Route::get('/videos/{video}/download', [VideoController::class, 'download'])->name('videos.download');
Route::get('/videos/{video}/download-lipsynced', [VideoController::class, 'downloadLipsynced'])->name('videos.download.lipsynced');

// Player page for testing (uses web middleware for session/views)
Route::get('/player/{video}', [StreamDubController::class, 'player'])->name('stream.player');

// Segment-based progressive player
Route::get('/player/{video}/segments', [SegmentPlayerController::class, 'player'])->name('player.segments');

// Live streaming dubbing
Route::get('/stream', [LiveDubController::class, 'index'])->name('stream.live');

// Online video dubber
Route::get('/dub', [OnlineDubController::class, 'index'])->name('dub.index');
Route::post('/dub', [OnlineDubController::class, 'submit'])->name('dub.submit');
Route::post('/dub/chunk', [OnlineDubController::class, 'uploadChunk'])->name('dub.chunk');
Route::post('/dub/complete', [OnlineDubController::class, 'uploadComplete'])->name('dub.complete');
Route::get('/dub/{video}', [OnlineDubController::class, 'progress'])->name('dub.progress');

// Instant dub (SRT → TTS over video)
Route::get('/instant-dub', fn() => view('instant-dub'))->name('instant-dub');

// Admin panel
Route::get('/admin/login', [AdminController::class, 'loginForm'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'login'])->name('admin.login.post');
Route::post('/admin/logout', [AdminController::class, 'logout'])->name('admin.logout');

Route::middleware('admin.auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dubs', [AdminDubController::class, 'index'])->name('dubs.index');
    Route::get('/dubs/{dub}', [AdminDubController::class, 'show'])->name('dubs.show');
    Route::patch('/dubs/{dub}/segments/{segment}', [AdminDubController::class, 'updateSegment'])->name('dubs.segment.update');
    Route::get('/dubs/{dub}/segments/{segment}/audio', [AdminDubController::class, 'audioSegment'])->name('dubs.segment.audio');
    Route::post('/dubs/{dub}/retts', [AdminDubController::class, 'rettsDub'])->name('dubs.retts');
    Route::get('/dubs/{dub}/retts-status', [AdminDubController::class, 'rettsStatus'])->name('dubs.retts.status');
    Route::patch('/dubs/{dub}/voice-map', [AdminDubController::class, 'updateVoiceMap'])->name('dubs.voice-map');
    Route::delete('/dubs/{dub}', [AdminDubController::class, 'destroy'])->name('dubs.destroy');

    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
});

