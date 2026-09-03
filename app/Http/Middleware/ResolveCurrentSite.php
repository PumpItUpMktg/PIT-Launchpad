<?php

namespace App\Http\Middleware;

use App\Models\Concerns\BelongsToSite;
use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleTenantScope;
use App\Operator\ActiveTenant;
use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the current Site for the request so {@see SiteScope} (and its siblings
 * {@see VisibleTenantScope} + {@see BelongsToSite}'s
 * auto-fill) resolve to a real tenant instead of being a no-op.
 *
 * Selection strategy: an explicit `X-Site-Id` request header wins (API/testing seam), otherwise the
 * operator's active working tenant ({@see ActiveTenant} — the `guided_site_id` session key). Registered
 * on the admin panel AFTER {@see EnsureTenantSelected}, so the gate's single-site auto-select has already
 * chosen a tenant before this reads it. A request with neither leaves CurrentSite null (the lobby /
 * cross-tenant context), where the scopes stay no-ops by design.
 */
class ResolveCurrentSite
{
    public function __construct(protected CurrentSite $currentSite, protected ActiveTenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $siteId = $request->header('X-Site-Id');
        if (! is_string($siteId) || $siteId === '') {
            $siteId = $this->tenant->id();
        }

        if (is_string($siteId) && $siteId !== '') {
            $this->currentSite->setId($siteId);
        }

        return $next($request);
    }
}
