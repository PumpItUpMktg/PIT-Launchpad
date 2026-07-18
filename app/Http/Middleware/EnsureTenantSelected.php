<?php

namespace App\Http\Middleware;

use App\Filament\Resources\SiteResource;
use App\Operator\ActiveTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hard tenant gate for the operator panel: an authenticated operator with NO active tenant is
 * sent to the Portfolio to pick one. Every page works on exactly one tenant; you switch by
 * returning to the Portfolio (the topbar "Switch tenant" link) and choosing another card.
 *
 * Always reachable without a tenant (or the gate would trap you): the Portfolio itself and its
 * create-site page, plus logout. Everything else redirects until a tenant is chosen. Runs in the
 * panel's authMiddleware, so the login screen is never gated.
 */
class EnsureTenantSelected
{
    public function __construct(private readonly ActiveTenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Only gate top-level page loads — never form posts, Livewire updates, or downloads.
        if ($this->tenant->has() || ! $request->isMethod('GET') || $request->ajax()) {
            return $next($request);
        }

        $route = (string) ($request->route()?->getName() ?? '');
        if ($this->allowlisted($route)) {
            return $next($request);
        }

        return redirect(SiteResource::getUrl('index'));
    }

    /** The Portfolio picker + create-site + logout stay reachable with no tenant selected. */
    private function allowlisted(string $routeName): bool
    {
        foreach (['resources.sites.index', 'resources.sites.create', '.auth.logout'] as $needle) {
            if (str_contains($routeName, $needle)) {
                return true;
            }
        }

        return false;
    }
}
