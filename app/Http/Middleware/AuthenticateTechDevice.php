<?php

namespace App\Http\Middleware;

use App\JobCapture\Auth\DeviceAuthenticator;
use App\Models\TechDevice;
use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a capture-PWA request by its device token (§5). Reads the token from the Authorization
 * bearer header (or `X-Device-Token`), resolves it to an active {@see TechDevice}, and — since
 * the token carries the tenant — binds the current site so every downstream query is tenant-scoped. The
 * resolved device is stashed on the request (`tech_device`) for the capture controllers. A missing or
 * revoked token gets a 401.
 */
class AuthenticateTechDevice
{
    public function __construct(
        private readonly DeviceAuthenticator $authenticator,
        private readonly CurrentSite $currentSite,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->header('X-Device-Token');

        $device = is_string($token) ? $this->authenticator->resolveToken($token) : null;
        if ($device === null) {
            return response()->json(['message' => 'Unauthenticated device.'], Response::HTTP_UNAUTHORIZED);
        }

        $this->currentSite->setId($device->site_id);
        $request->attributes->set('tech_device', $device);

        return $next($request);
    }
}
