<?php

use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateDashboard;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Http\Middleware\EnsureTenantSelected;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

/** A GET request whose resolved route carries $routeName (what the gate keys on). */
function gateRequest(string $routeName, string $method = 'GET'): Request
{
    $request = Request::create('/admin/x', $method);
    $route = (new RoutingRoute([$method], '/admin/x', []))->name($routeName);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

function runGate(Request $request): Response
{
    return app(EnsureTenantSelected::class)->handle($request, fn (): Response => new Response('passed'));
}

it('redirects an operator with no active tenant to the Portfolio', function () {
    $res = runGate(gateRequest('filament.admin.pages.operate-dashboard'));

    expect($res->getStatusCode())->toBe(302)
        ->and($res->headers->get('Location'))->toContain('/sites'); // the Portfolio (SiteResource index)
});

it('lets the Portfolio, create-site, and logout through without a tenant (no trap)', function () {
    foreach ([
        'filament.admin.resources.sites.index',
        'filament.admin.resources.sites.create',
        'filament.admin.auth.logout',
    ] as $allowed) {
        expect(runGate(gateRequest($allowed))->getContent())->toBe('passed');
    }
});

it('lets every page through once a tenant is selected', function () {
    app(ActiveTenant::class)->set(Site::factory()->create()->id);

    expect(runGate(gateRequest('filament.admin.pages.operate-dashboard'))->getContent())->toBe('passed');
});

it('never gates a non-GET request (form posts / livewire updates pass)', function () {
    expect(runGate(gateRequest('filament.admin.pages.operate-dashboard', 'POST'))->getContent())->toBe('passed');
});

it('Portfolio "Work on this" selects the tenant and enters its Dashboard', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);

    Livewire::test(ListSites::class)
        ->assertTableActionExists('selectTenant')
        ->callTableAction('selectTenant', $site)
        ->assertRedirect(OperateDashboard::getUrl());

    expect(app(ActiveTenant::class)->id())->toBe($site->id);
});
