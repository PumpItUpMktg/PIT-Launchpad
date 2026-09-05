<?php

use App\Enums\UserRole;
use App\Filament\Pages\GeoActivityConsole;
use App\Filament\Pages\JobsBoard;
use App\Filament\Pages\MarketsBoard;
use App\Filament\Pages\Operate\TenantDashboard;
use App\Filament\Resources\VoiceProfileResource;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;

/**
 * Full-route panel smoke tests. The gap these close: every other test in the suite is a component-level
 * Livewire::test, so the panel ROUTE + middleware + auth + the visibility scope were never exercised
 * end to end — which is exactly how the VisibleSiteScope recursion (a page that simply doesn't load)
 * shipped green. At least one authenticated full HTTP GET per nav group, asserting the page renders.
 */
beforeEach(fn () => Filament::setCurrentPanel('admin'));

function smokeOperator(Site $site): User
{
    $u = User::factory()->create(['role' => UserRole::Operator]);
    Membership::create(['user_id' => $u->id, 'account_id' => $site->account_id, 'site_id' => $site->id, 'role' => 'operator']);

    return $u;
}

dataset('adminNavGroupSurfaces', [
    'Build · Dashboard' => [TenantDashboard::class],
    'Build · Jobs' => [JobsBoard::class],
    'Territory · Markets' => [MarketsBoard::class],
    'Results · AI visibility' => [GeoActivityConsole::class],
    'System · Voice' => [VoiceProfileResource::class],
]);

it('renders a live surface in every nav group over a full authenticated route', function (string $class) {
    $site = Site::factory()->create(['status' => 'active']);
    $this->actingAs(smokeOperator($site));
    app(ActiveTenant::class)->set($site->id);

    $this->get($class::getUrl())->assertOk();
})->with('adminNavGroupSurfaces');

it('a full /admin route renders for an operator with an account-wide membership (recursion regression)', function () {
    // The end-to-end form of the OperatorGatingTest regression: a real HTTP GET, which is what actually
    // OOM'd on the dev server. Before the fix this never returns — it exhausts memory in the scope.
    $account = Account::factory()->create();
    $site = Site::factory()->create(['account_id' => $account->id, 'status' => 'active']);
    $op = User::factory()->create(['role' => UserRole::Operator]);
    Membership::create(['user_id' => $op->id, 'account_id' => $account->id, 'site_id' => null, 'role' => 'operator']);

    $this->actingAs($op);
    app(ActiveTenant::class)->set($site->id);

    $this->get(MarketsBoard::getUrl())->assertOk();
});

it('the fix covers every panel: a Site query terminates under an account-wide member of any role', function (UserRole $role) {
    // The recursion lived in User::permittedSiteIds() — role- and panel-agnostic — reached via
    // VisibleSiteScope, which is global on the Site model and therefore fires in /admin, /console and
    // /portal alike. Proving a Site query terminates under each role that backs a panel (operator →
    // /admin, site admin → /console, client → /portal) covers all three without the panel-boot ceremony.
    $account = Account::factory()->create();
    Site::factory()->count(2)->create(['account_id' => $account->id]);

    $u = User::factory()->create(['role' => $role]);
    Membership::create(['user_id' => $u->id, 'account_id' => $account->id, 'site_id' => null, 'role' => 'operator']);
    $this->actingAs($u);

    expect(Site::query()->count())->toBe(2); // resolves + terminates, no recursion
})->with([
    'operator (/admin)' => [UserRole::Operator],
    'site admin (/console)' => [UserRole::SiteAdmin],
    'client (/portal)' => [UserRole::Client],
]);
