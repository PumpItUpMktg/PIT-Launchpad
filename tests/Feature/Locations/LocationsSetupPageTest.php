<?php

use App\Enums\UserRole;
use App\Filament\Pages\LocationsSetup;
use App\Integrations\Census\Geocoder;
use App\Integrations\Census\MockCensusGeocoder;
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

it('geocodes a base location from its address and stores the point', function () {
    app()->instance(Geocoder::class, new MockCensusGeocoder(40.7357, -74.1724));
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'address' => '123 Main St', 'lat' => null, 'lng' => null]);

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->call('geocode', $loc->id);

    $loc->refresh();
    expect((float) $loc->lat)->toBe(40.7357)
        ->and((float) $loc->lng)->toBe(-74.1724);
});

it('warns and stores nothing when the address cannot be matched', function () {
    app()->instance(Geocoder::class, new MockCensusGeocoder(unmatchable: ['nowhere']));
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'address' => null, 'lat' => null, 'lng' => null]);

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->set("addresses.{$loc->id}", 'nowhere')
        ->call('geocode', $loc->id);

    expect(Location::withoutGlobalScope(SiteScope::class)->where('id', $loc->id)->value('lat'))->toBeNull();
});

it('stores a manual lat/lng fallback', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'lat' => null, 'lng' => null]);

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->set("manualLat.{$loc->id}", '40.73')
        ->set("manualLng.{$loc->id}", '-74.17')
        ->call('saveManualPoint', $loc->id);

    $loc->refresh();
    expect((float) $loc->lat)->toBe(40.73)->and((float) $loc->lng)->toBe(-74.17);
});

it('adds a base location and geocodes it from the address', function () {
    app()->instance(Geocoder::class, new MockCensusGeocoder(40.5, -74.4));
    $site = Site::factory()->create(); // no locations

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->set('newName', 'Branch')
        ->set('newAddress', '50 Market St, Trenton NJ')
        ->call('addLocation');

    $loc = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Branch')->first();
    expect($loc)->not->toBeNull()
        ->and((float) $loc->lat)->toBe(40.5)
        ->and((float) $loc->lng)->toBe(-74.4);
});
