<?php

use App\Enums\UserRole;
use App\Livewire\TenantSwitcher;
use App\Models\Account;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\User;
use App\Operator\ActiveTenant;
use Livewire\Livewire;

function activeTenant(): ActiveTenant
{
    return app(ActiveTenant::class);
}

it('selects, reads, and clears the working tenant via the shared session key', function () {
    $site = Site::factory()->create();
    $t = activeTenant();

    expect($t->id())->toBeNull()->and($t->has())->toBeFalse();

    $t->set($site->id);
    expect($t->id())->toBe($site->id)
        ->and($t->has())->toBeTrue()
        ->and($t->site()?->id)->toBe($site->id)
        ->and(session(ActiveTenant::SESSION_KEY))->toBe($site->id); // the key Setup/Operate share

    $t->clear();
    expect($t->id())->toBeNull()->and($t->has())->toBeFalse();
});

it('a stale/deleted tenant id resolves to no active tenant (never a crash)', function () {
    activeTenant()->set('01JQZZZZZZZZZZZZZZZZZZZZZZ'); // no such site
    expect(activeTenant()->has())->toBeFalse()
        ->and(activeTenant()->banner()['has'])->toBeFalse();
});

it('the banner has no tenant when none is selected', function () {
    expect(activeTenant()->banner())->toBe(['has' => false, 'name' => '', 'logo_url' => null]);
});

it('the banner prefers the site logo, then the account logo, then name-only', function () {
    // Site's own uploaded logo wins.
    $account = Account::factory()->create(['logo_url' => 'https://cdn/acct.png']);
    $site = Site::factory()->create(['account_id' => $account->id, 'brand_name' => 'Sump Pump Gurus']);
    SiteBranding::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'logo_set' => ['url' => 'https://cdn/site.png'],
    ]);

    activeTenant()->set($site->id);
    expect(activeTenant()->banner())->toMatchArray([
        'has' => true, 'name' => 'Sump Pump Gurus', 'logo_url' => 'https://cdn/site.png',
    ]);

    // No site logo → the Account white-label logo.
    SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->update(['logo_set' => []]);
    expect(activeTenant()->banner()['logo_url'])->toBe('https://cdn/acct.png');

    // No logos anywhere → name only.
    $account->update(['logo_url' => null]);
    expect(activeTenant()->banner()['logo_url'])->toBeNull()
        ->and(activeTenant()->banner()['name'])->toBe('Sump Pump Gurus');
});

it('renders the topbar switcher with the tenant name, logo, and a way to switch', function () {
    $site = Site::factory()->create(['brand_name' => 'Basement Guard']);
    Site::factory()->create(); // a second accessible tenant → real switcher, not static
    SiteBranding::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'logo_set' => ['url' => 'https://cdn/bg.png'],
    ]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    activeTenant()->set($site->id);

    Livewire::test(TenantSwitcher::class)
        ->assertSee('Basement Guard')
        ->assertSee('Working on')
        ->assertSee('Go to Portfolio')
        ->assertSeeHtml('https://cdn/bg.png');
});
