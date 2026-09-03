<?php

use App\Enums\UserRole;
use App\Filament\Resources\SiteResource;
use App\Http\Middleware\EnsureTenantSelected;
use App\Http\Middleware\ResolveCurrentSite;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/*
 * Acceptance 4 — ResolveCurrentSite is registered and SiteScope is active in /admin.
 * The hinge: ActiveTenant (the guided_site_id session) now drives CurrentSite, and the middleware
 * binds it on every panel request, so SiteScope (which reads CurrentSite::id()) stops being a no-op.
 */

afterEach(fn () => CurrentSite::clear());

function runResolve(Request $request): ?string
{
    $captured = null;
    (new ResolveCurrentSite(app(CurrentSite::class), app(ActiveTenant::class)))
        ->handle($request, function () use (&$captured) {
            $captured = CurrentSite::id(); // what the scope would see downstream

            return response('ok');
        });

    return $captured;
}

it('binds CurrentSite from the operator active tenant when no header is present', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]); // active tenant, set WITHOUT ActiveTenant::set() to isolate the read
    CurrentSite::clear();

    expect(runResolve(Request::create('/admin/x', 'GET')))->toBe($site->id);
});

it('lets an explicit X-Site-Id header win over the active tenant (the API/testing seam)', function () {
    $active = Site::factory()->create();
    $header = Site::factory()->create();
    session(['guided_site_id' => $active->id]);
    CurrentSite::clear();

    $request = Request::create('/admin/x', 'GET');
    $request->headers->set('X-Site-Id', $header->id);

    expect(runResolve($request))->toBe($header->id);
});

it('leaves CurrentSite null when there is no header and no active tenant (lobby / cross-tenant context)', function () {
    CurrentSite::clear();

    expect(runResolve(Request::create('/admin/x', 'GET')))->toBeNull();
});

it('ActiveTenant::set() drives CurrentSite for the same request, and clear() unbinds it', function () {
    $site = Site::factory()->create();
    $tenant = app(ActiveTenant::class);

    $tenant->set($site->id);
    expect(CurrentSite::id())->toBe($site->id); // no next-request round-trip needed

    $tenant->clear();
    expect(CurrentSite::id())->toBeNull();
});

it('is registered on the admin panel, after the tenant gate', function () {
    $auth = Filament::getPanel('admin')->getAuthMiddleware();

    expect($auth)->toContain(ResolveCurrentSite::class)
        ->and($auth)->toContain(EnsureTenantSelected::class);

    // Order matters: the gate's single-site auto-select must run BEFORE we read the active tenant.
    $gate = array_search(EnsureTenantSelected::class, array_values($auth), true);
    $resolve = array_search(ResolveCurrentSite::class, array_values($auth), true);
    expect($resolve)->toBeGreaterThan($gate);
});

it('SiteScope is live on a real /admin request — CurrentSite reflects the active tenant', function () {
    $site = Site::factory()->create();
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    session(['guided_site_id' => $site->id]);

    $this->actingAs($operator)->get(SiteResource::getUrl('index'))->assertOk();

    // The request ran the panel middleware stack in-process; ResolveCurrentSite bound the tenant and
    // nothing cleared it, so the scope's source is populated (it was null before this change).
    expect(CurrentSite::id())->toBe($site->id);
});
