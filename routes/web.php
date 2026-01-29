<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\RealtimeDubController;
use App\Http\Controllers\StreamDubController;

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
