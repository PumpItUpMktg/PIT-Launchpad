<?php

namespace App\Http\Middleware;

use App\Filament\Resources\SiteResource;
use App\Models\User;
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
        if (! $request->isMethod('GET') || $request->ajax()) {
            return $next($request);
        }

        $user = $request->user();

        // Gating layer 2: a URL-guessed tenant this operator can't see is REFUSED here (not just
        // hidden) — a non-member ?site never renders.
        $requested = $request->query('site');
        if (is_string($requested) && $requested !== '' && $user instanceof User && ! $user->canSeeSite($requested)) {
            return redirect(SiteResource::getUrl('index'));
        }

        if ($this->tenant->has()) {
            return $next($request);
        }

        // A single-site operator auto-selects their one tenant instead of being sent to the picker.
        if ($user instanceof User) {
            $permitted = $user->permittedSiteIds();
            if (is_array($permitted) && count($permitted) === 1) {
                $this->tenant->set($permitted[0]);

                return $next($request);
            }
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
