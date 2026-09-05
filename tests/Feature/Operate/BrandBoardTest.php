<?php

use App\Enums\UserRole;
use App\Filament\Pages\BrandBoard;
use App\Guided\StepGate;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Brand\BrandProfile;
use App\Styling\StyleVariation;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

afterEach(fn () => CurrentSite::clear());

function brandOperator(): User
{
    return User::factory()->create(['role' => UserRole::Operator]);
}

it('is operator-only', function () {
    expect(BrandBoard::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(BrandBoard::canAccess())->toBeFalse();

    $this->actingAs(brandOperator());
    expect(BrandBoard::canAccess())->toBeTrue();
});

it('reads the locked tenant only — never another site', function () {
    $this->actingAs(brandOperator());
    $a = Site::factory()->create(['brand_name' => 'Locked Brand A']);
    Site::factory()->create(['brand_name' => 'FOREIGN BRAND B']);
    app(ActiveTenant::class)->set($a->id);

    $board = app(BrandProfile::class)->for($a->id);

    expect($board['brand_name'])->toBe('Locked Brand A');
});

it('choosing a variation records the override on the locked site', function () {
    $this->actingAs(brandOperator());
    $site = Site::factory()->create();
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(BrandBoard::class)->call('chooseStyle', 'warm');

    expect($site->fresh()->style_variation)->toBe(StyleVariation::Warm)
        ->and($site->fresh()->use_logo_colors)->toBeFalse();
});

it('gates the push on a WordPress connection, then applies and stamps brand_pushed', function () {
    $this->actingAs(brandOperator());
    $site = Site::factory()->create();
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(BrandBoard::class)->call('chooseStyle', 'warm');

    // No WP connection → guarded no-op.
    Livewire::test(BrandBoard::class)->call('pushBrand')->assertNotified();
    expect(app(StepGate::class)->state($site->fresh())->brand_pushed)->toBeFalse();

    // Connected → the activator pushes the chosen variation and the flag stamps.
    Connection::factory()->create(['site_id' => $site->id, 'provider' => 'wp_app_password']);
    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyleVariation')->once()->with('warm', Mockery::type('array'))->andReturn(['updated' => true, 'variation' => 'warm']);
    $client->shouldIgnoreMissing();
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    Livewire::test(BrandBoard::class)->call('pushBrand');

    expect(app(StepGate::class)->state($site->fresh())->brand_pushed)->toBeTrue();
});

it('renders the tenant-locked board with the brand and no per-page site picker', function () {
    $this->actingAs(brandOperator());
    $site = Site::factory()->create(['brand_name' => 'Galveston Plumbing']);
    SiteBranding::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id,
        'logo_set' => ['url' => 'https://cdn.example/logo.png', 'primary' => '#123B6B', 'accent' => '#1D6FD6'],
    ]);
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(BrandBoard::class)->assertOk()->html();

    expect($html)->toContain('Galveston Plumbing')
        ->and($html)->not->toContain('<select'); // tenant comes from the lock, never a page picker
});
