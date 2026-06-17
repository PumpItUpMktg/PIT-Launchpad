<?php

use App\Enums\UserRole;
use App\Filament\Pages\LocationsSetup;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\CoverageFixture;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(CoverageFixture::municipalities()));
});

function locationsSetupSite(?int $radius = null): Site
{
    $site = Site::factory()->create();
    Location::factory()->create([
        'site_id' => $site->id, 'name' => 'HQ',
        'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG,
        'coverage_radius' => $radius,
    ]);

    return $site;
}

it('prefills the radius (default 25) when a site is selected', function () {
    $site = locationsSetupSite(); // no saved radius
    $loc = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->assertSet("radii.{$loc->id}", 25)
        ->assertSee('HQ');
});

it('persists the chosen radius and computes + saves the coverage union', function () {
    $site = locationsSetupSite();
    $loc = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->set("radii.{$loc->id}", 25)
        ->call('compute')
        ->assertSet('computed', true)
        ->assertSee('Maplewood')          // a real town from the fixture union
        ->assertSee('Coverage union');

    // radius persisted on the Location, coverage persisted as the CoverageArea set
    expect(Location::withoutGlobalScope(SiteScope::class)->where('id', $loc->id)->value('coverage_radius'))->toBe(25)
        ->and(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(3);
});

it('resumes a previously saved radius', function () {
    $site = locationsSetupSite(radius: 15);
    $loc = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->assertSet("radii.{$loc->id}", 15);
});

it('shows the no-locations state for a site without base locations', function () {
    $site = Site::factory()->create();

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->assertSee('No base locations');
});
