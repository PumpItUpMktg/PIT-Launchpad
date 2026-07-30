<?php

use App\Http\Controllers\GoogleConnectController;
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
