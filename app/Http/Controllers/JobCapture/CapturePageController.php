<?php

namespace App\Http\Controllers\JobCapture;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Serves the capture PWA shell (§5) — the tech-facing install-to-home-screen app at `/capture`, its web
 * app manifest, and its service worker. All three are public: the shell is a static single-page app that
 * authenticates client-side with a device token (stored on the device) and talks to the
 * {@see CaptureController} JSON API. No session, no server-rendered tenant data — the tenant is carried by
 * the token on every API call.
 */
class CapturePageController extends Controller
{
    /** The PWA shell — login, job list, and capture screens in one offline-capable page. */
    public function app(): View
    {
        return view('capture.app');
    }

    /** The web app manifest (add-to-home-screen: full-screen, own icon, launches at /capture). */
    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => 'Job Capture',
            'short_name' => 'Capture',
            'description' => 'Document a completed job in under a minute.',
            'start_url' => '/capture',
            'scope' => '/capture',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#0f172a',
            'theme_color' => '#0f172a',
            'icons' => [
                [
                    'src' => '/capture-icon.svg',
                    'sizes' => 'any',
                    'type' => 'image/svg+xml',
                    'purpose' => 'any maskable',
                ],
            ],
        ])->header('Content-Type', 'application/manifest+json');
    }

    /** The service worker — precache the shell for offline launch; the offline UPLOAD queue is IndexedDB. */
    public function serviceWorker(): Response
    {
        return response(view('capture.sw')->render(), 200, [
            'Content-Type' => 'application/javascript',
            // Allow the worker (served from /capture/sw.js) to control the whole /capture scope.
            'Service-Worker-Allowed' => '/capture',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
