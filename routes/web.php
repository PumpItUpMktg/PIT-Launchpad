<?php

use App\Http\Controllers\GoogleConnectController;
use App\Http\Controllers\JobCapture\CaptureController;
use App\Http\Controllers\JobCapture\CapturePageController;
use App\Http\Controllers\Reviews\ReviewSubmissionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Legacy per-family Live boards (retired at the card consolidation, PR #769) → their replacement Pages
// boards. Kept as redirects, not live pages (nav-cutover rule 10): an old bookmark lands on the right board
// instead of 404-ing, and a retired route can't quietly accumulate stale references or stay URL-reachable.
Route::redirect('/admin/live-core-pages', '/admin/operate/pages/core');
Route::redirect('/admin/live-services', '/admin/operate/pages/services');
Route::redirect('/admin/live-locations', '/admin/operate/pages/locations');

// Review Capture (§6) — the public, no-auth review submission surface. Reached only by a single-use signed
// token that carries the tenant (bound from the token, not a session). Rate limited — this is the only new
// per-route throttle in the app; anchor is the client IP + token path.
Route::middleware('throttle:30,1')->group(function (): void {
    Route::get('reviews/{token}/thanks', [ReviewSubmissionController::class, 'thanks'])->name('reviews.thanks');
    Route::get('reviews/{token}', [ReviewSubmissionController::class, 'show'])->name('reviews.show');
    Route::post('reviews/{token}', [ReviewSubmissionController::class, 'submit'])->name('reviews.submit');
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

// §5 Job Capture PWA shell — the install-to-home-screen tech app, its manifest, and its
// service worker. Public + static: the app authenticates client-side with a device token
// and talks to the JSON API below. Declared before the /capture/api group.
Route::get('capture', [CapturePageController::class, 'app'])->name('capture.app');
Route::get('capture/manifest.webmanifest', [CapturePageController::class, 'manifest'])->name('capture.manifest');
Route::get('capture/sw.js', [CapturePageController::class, 'serviceWorker'])->name('capture.sw');

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
