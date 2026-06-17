<?php

use App\Enums\UserRole;
use App\Filament\Pages\LocationsSetup;
use App\Integrations\Census\Geocoder;
use App\Integrations\Census\MockCensusGeocoder;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Integrations\Places\MockPlacesProvider;
use App\Integrations\Places\PlacesProvider;
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
    // located point sits inside the fixture coverage so Compute yields a union
    app()->instance(Geocoder::class, new MockCensusGeocoder(CoverageFixture::A_LAT, CoverageFixture::A_LNG));
    app()->instance(PlacesProvider::class, new MockPlacesProvider);
});

function locSpoke(Site $site, string $name): Location
{
    return Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

it('prefills how-far-you-serve (default 25) when a site is selected', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'coverage_radius' => null, 'lat' => null, 'lng' => null, 'address' => null]);

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->assertSet("radii.{$loc->id}", 25);
});

it('auto-locates an existing un-pointed base in the background on open', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'address' => '1 Main St', 'lat' => null, 'lng' => null]);

    Livewire::test(LocationsSetup::class)->set('siteId', $site->id);

    $loc->refresh();
    expect((float) $loc->lat)->toBe(CoverageFixture::A_LAT)
        ->and($loc->geocode_failed)->toBeFalse();
});

it('adds a manual location, locates it in the background, and computes coverage', function () {
    $site = Site::factory()->create();

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->call('startAdd')
        ->set('addName', 'Montclair')
        ->set('addAddress', '1 Bloomfield Ave, Montclair NJ')
        ->set('addRadius', 25)
        ->call('addManual')
        ->assertSet('computed', true);

    $loc = locSpoke($site, 'Montclair');
    expect($loc)->not->toBeNull()
        ->and((float) $loc->lat)->toBe(CoverageFixture::A_LAT)
        ->and($loc->coverage_radius)->toBe(25)
        ->and(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBeGreaterThan(0);
});

it('adds from a Google listing, pulling the point from the place details', function () {
    $site = Site::factory()->create();

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->call('addFromPlace', MockPlacesProvider::PLACE_ID);

    $loc = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('place_id', MockPlacesProvider::PLACE_ID)->first();
    expect($loc)->not->toBeNull()
        ->and((float) $loc->lat)->toBe(30.267153)   // straight from PlaceDetails, no geocode
        ->and($loc->geocode_failed)->toBeFalse();
});

it('flags a failed geocode and accepts a manual override', function () {
    app()->instance(Geocoder::class, new MockCensusGeocoder(unmatchable: ['nowhere at all']));
    $site = Site::factory()->create();

    $component = Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->call('startAdd')
        ->set('addSource', 'manual')
        ->set('addName', 'Bad')
        ->set('addAddress', 'nowhere at all')
        ->call('addManual');

    $loc = locSpoke($site, 'Bad');
    expect($loc->geocode_failed)->toBeTrue()
        ->and($loc->lat)->toBeNull();

    // the override sets the spot
    $component
        ->set("manualLat.{$loc->id}", '40.73')
        ->set("manualLng.{$loc->id}", '-74.17')
        ->call('saveManualPoint', $loc->id);

    $loc->refresh();
    expect((float) $loc->lat)->toBe(40.73)
        ->and($loc->geocode_failed)->toBeFalse();
});

it('adds a directed town to a location (priority page candidate) and marks it on the map', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG, 'coverage_radius' => 25]);

    $page = Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->set("townQuery.{$loc->id}", 'maplewood')
        ->call('searchTowns', $loc->id)
        ->call('addTown', $loc->id, '3445000'); // Maplewood from the fixture

    expect(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', 'manual')->where('geo_id', '3445000')->exists())->toBeTrue()
        ->and(collect($page->instance()->manualMarkers)->pluck('name'))->toContain('Maplewood');

    $page->call('removeTown', '3445000');
    expect(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', 'manual')->count())->toBe(0);
});

it('warns loudly when geocoding will fall back to Census (no Google key)', function () {
    config()->set('services.google.maps_api_key', '');

    Livewire::test(LocationsSetup::class)->assertSee('Google Geocoding isn’t enabled');

    config()->set('services.google.maps_api_key', 'a-key');
    Livewire::test(LocationsSetup::class)->assertDontSee('Google Geocoding isn’t enabled');
});

it('retries a previously-failed geocode and resolves it via the now-Google geocoder', function () {
    // a base that failed under the old Census-only geocoder (geocode_failed = true)
    app()->instance(Geocoder::class, new MockCensusGeocoder(40.1265, -75.4188)); // now resolves (Google stand-in)
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'address' => '2753 W Main St, Trooper PA 19403', 'lat' => null, 'lng' => null, 'geocode_failed' => true]);

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id) // auto-geocode-on-open SKIPS a failed base — must not silently re-locate
        ->assertSeeHtml('wire:click="retryGeocode(\''.$loc->id.'\')"')
        ->call('retryGeocode', $loc->id);

    $loc->refresh();
    expect((float) $loc->lat)->toBe(40.1265)
        ->and($loc->geocode_failed)->toBeFalse();
});

it('instruments Update — reports located count + radii + towns (proves coverage ran)', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG, 'coverage_radius' => 25]);

    $page = Livewire::test(LocationsSetup::class)->set('siteId', $site->id)->call('compute');

    expect($page->instance()->updateDiag)
        ->toContain('1 located')
        ->toContain('radii [25]')
        ->toContain('towns');
});

it('shows the empty-state for a site with no locations', function () {
    $site = Site::factory()->create();

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->assertSee('No locations yet');
});

it('feeds the shared map with color-matched, located bases only', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair', 'lat' => 40.81, 'lng' => -74.22, 'coverage_radius' => 15]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'lat' => 40.13, 'lng' => -75.41, 'coverage_radius' => 25]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Unlocated', 'lat' => null, 'lng' => null]);

    $page = Livewire::test(LocationsSetup::class)->set('siteId', $site->id)->instance();
    $mapData = $page->mapData;
    $colors = $page->colors;

    expect($mapData)->toHaveCount(2) // the un-located base is excluded
        ->and(collect($mapData)->pluck('name'))->toContain('Montclair', 'Trooper')
        ->and(collect($mapData)->pluck('color')->unique())->toHaveCount(2) // distinct, matched colors
        ->and($colors)->toHaveCount(3);
});

it('renders the radius control as a real wired button and the click sets + recomputes', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'lat' => 40.7, 'lng' => -74.5, 'coverage_radius' => 25]);

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        // the control is an actual wire:click button in the DOM (not a dead text span) — test the click target, not just the handler
        ->assertSeeHtml('wire:click="setRadius(\''.$loc->id.'\', 15)"')
        ->call('setRadius', $loc->id, 15)
        ->assertSet("radii.{$loc->id}", 15)
        ->assertDispatched('locations-updated'); // recompute → the map circle gets the resize signal

    expect(Location::withoutGlobalScope(SiteScope::class)->where('id', $loc->id)->value('coverage_radius'))->toBe(15);
});

it('summary reflects overlap once coverage is computed', function () {
    $site = Site::factory()->create();
    // two located bases that share a municipality in the fixture (A + B) → 1 overlapping
    Location::factory()->create(['site_id' => $site->id, 'name' => 'A', 'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG, 'coverage_radius' => 25]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'B', 'lat' => CoverageFixture::B_LAT, 'lng' => CoverageFixture::B_LNG, 'coverage_radius' => 25]);

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->call('compute')
        ->assertSet('computed', true)
        ->assertSee('overlapping'); // Clinton Twp shared across A + B
});
