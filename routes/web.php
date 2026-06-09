<?php

use App\Http\Controllers\GoogleConnectController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// §2-step-2 — per-tenant Google (GSC + GA4) OAuth connect backend. The callback
// path must match GOOGLE_REDIRECT_URI. A polished connect button / property
// picker is a thin §7 follow-up; these routes make the full flow exercisable.
Route::get('/connections/google/{site}/authorize', [GoogleConnectController::class, 'authorize'])
    ->name('google.authorize');
Route::get('/oauth/google/callback', [GoogleConnectController::class, 'callback'])
    ->name('google.callback');
