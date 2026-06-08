<?php

namespace App\Http\Middleware;

use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin, swappable resolver that binds the current Site for the request.
 *
 * The selection strategy (subdomain, header, operator switch) is finalised in a
 * later section; for now this reads an explicit X-Site-Id header so the binding
 * exists end-to-end and can be exercised. It is intentionally not registered
 * globally yet.
 */
class ResolveCurrentSite
{
    public function __construct(protected CurrentSite $currentSite) {}

    public function handle(Request $request, Closure $next): Response
    {
        $siteId = $request->header('X-Site-Id');

        if (is_string($siteId) && $siteId !== '') {
            $this->currentSite->setId($siteId);
        }

        return $next($request);
    }
}
