<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDubController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminProsodyTestController;
use App\Http\Controllers\AdminVoicePoolController;
use App\Http\Controllers\PremiumDubController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\StreamDubController;
use App\Http\Controllers\SegmentPlayerController;
use App\Http\Controllers\LiveDubController;
use App\Http\Controllers\OnlineDubController;

Route::get('/', fn() => abort(404));
Route::get('/extension', fn() => view('extension'))->name('extension');
Route::get('/privacy', fn() => view('privacy'))->name('privacy');
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
// ?embedded=1 mode: allow framing from YouTube Chrome extension
Route::get('/instant-dub', function () {
    $response = response(view('instant-dub'));
    if (request()->has('embedded')) {
        $response->headers->set('X-Frame-Options', 'ALLOWALL');
        $response->headers->set('Content-Security-Policy', "frame-ancestors *");
    }
    return $response;
})->name('instant-dub');


// Admin panel
Route::get('/admin/login', [AdminController::class, 'loginForm'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'login'])->name('admin.login.post');
Route::post('/admin/logout', [AdminController::class, 'logout'])->name('admin.logout');

Route::middleware('admin.auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', fn() => redirect()->route('admin.dubs.index'));

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

    Route::get('/voice-pool', [AdminVoicePoolController::class, 'index'])->name('voice-pool.index');
    Route::post('/voice-pool', [AdminVoicePoolController::class, 'add'])->name('voice-pool.add');
    Route::post('/voice-pool/upload', [AdminVoicePoolController::class, 'upload'])->name('voice-pool.upload');
    Route::post('/voice-pool/test', [AdminVoicePoolController::class, 'test'])->name('voice-pool.test');
    Route::post('/voice-pool/{gender}/{name}/speed', [AdminVoicePoolController::class, 'saveSpeed'])->name('voice-pool.speed');
    Route::post('/voice-pool/{gender}/{name}/ref-text', [AdminVoicePoolController::class, 'saveRefText'])->name('voice-pool.ref-text');
    Route::get('/voice-pool/{gender}/{name}/play', [AdminVoicePoolController::class, 'play'])->name('voice-pool.play');
    Route::delete('/voice-pool/{gender}/{name}', [AdminVoicePoolController::class, 'delete'])->name('voice-pool.delete');

    // Prosody transfer test
    Route::get('/prosody-test', [AdminProsodyTestController::class, 'index'])->name('prosody-test.index');
    Route::post('/prosody-test', [AdminProsodyTestController::class, 'transfer'])->name('prosody-test.transfer');

    // Premium dub (admin only)
    Route::get('/premium-dub', fn() => view('premium-dub'))->name('premium-dub');

    // Premium dub API (session-auth, admin only)
    Route::prefix('api/premium-dub')->group(function () {
        Route::post('/start', [PremiumDubController::class, 'start'])->name('api.premium-dub.start');
        Route::post('/start-upload', [PremiumDubController::class, 'startUpload'])->name('api.premium-dub.start-upload');
        Route::get('/{dubId}/status', [PremiumDubController::class, 'status'])->name('api.premium-dub.status');
        Route::get('/{dubId}/download', [PremiumDubController::class, 'download'])->name('api.premium-dub.download');
    });
});

