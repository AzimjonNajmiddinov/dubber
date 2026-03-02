<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\RealtimeDubController;
use App\Http\Controllers\StreamDubController;
use App\Http\Controllers\SegmentPlayerController;
use App\Http\Controllers\LiveDubController;
use App\Http\Controllers\OnlineDubController;
use App\Http\Controllers\QueueMonitorController;

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

// Admin panel (password protected)
Route::middleware('admin.password')->group(function () {
    Route::get('/admin/queue', [QueueMonitorController::class, 'index'])->name('admin.queue');
    Route::post('/admin/queue/retry/{id}', [QueueMonitorController::class, 'retry'])->name('admin.queue.retry');
    Route::post('/admin/queue/retry-all', [QueueMonitorController::class, 'retryAll'])->name('admin.queue.retry-all');
    Route::delete('/admin/queue/delete/{id}', [QueueMonitorController::class, 'delete'])->name('admin.queue.delete');
    Route::post('/admin/queue/flush', [QueueMonitorController::class, 'flush'])->name('admin.queue.flush');
    Route::get('/admin/logs', fn () => redirect('/log-viewer'))->name('admin.logs');
});

Route::post('/admin/logout', function () {
    session()->forget('admin_authenticated');
    return redirect('/admin/queue');
})->name('admin.logout');
