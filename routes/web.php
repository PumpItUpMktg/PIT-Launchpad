<?php

use App\Http\Controllers\GoogleConnectController;
use App\Http\Controllers\JobCapture\CaptureController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Platform-wide Google (GSC + GA4) OAuth connect backend — the "one email" the
// operator connects ONCE and every client adds as a user on their property. Not
// per-tenant: the shared grant lives on the GoogleAccount singleton; each site
// picks WHICH property to read in the property picker. The callback path must
// match GOOGLE_REDIRECT_URI.
Route::get('/connections/google/authorize', [GoogleConnectController::class, 'authorize'])
    ->name('google.authorize');
Route::get('/oauth/google/callback', [GoogleConnectController::class, 'callback'])
    ->name('google.callback');

// §5 Job Capture PWA API. Auth endpoints are open (a magic-link device id + one-time
// code); the job endpoints run behind the device-token middleware, which binds the
// tenant from the token. Photos post base64-encoded from the PWA's offline queue.
Route::prefix('capture/api')->group(function (): void {
    Route::post('auth/request-code', [CaptureController::class, 'requestCode']);
    Route::post('auth/redeem', [CaptureController::class, 'redeem']);

    Route::middleware('tech.device')->group(function (): void {
        Route::get('jobs', [CaptureController::class, 'index']);
        Route::post('jobs', [CaptureController::class, 'store']);
    });
});
