<?php

use App\Http\Middleware\AuthenticateTechDevice;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // §5 capture PWA: authenticate a request by its tech device token.
        $middleware->alias([
            'tech.device' => AuthenticateTechDevice::class,
        ]);

        // The capture API is a token-authenticated, cookieless PWA surface — CSRF does not apply.
        $middleware->validateCsrfTokens(except: [
            'capture/api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The capture PWA talks JSON too: a validation failure there must come back as a 422 the phone can
        // read, not a redirect to an HTML page (fetch follows it, sees 200, and the phone would count a
        // rejected job as uploaded and drop it from its queue).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'capture/api/*'),
        );
    })->create();
