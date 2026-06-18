<?php

use App\Enums\MunicipalityType;
use App\Enums\UserRole;
use App\Filament\Pages\LocationsSetup;
use App\Integrations\Census\County;
use App\Integrations\Census\Geocoder;
use App\Integrations\Census\MockCensusGeocoder;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\Municipality;
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

/**
 * County-based coverage: the gazetteer resolves every located point to Essex County, NJ
 * (34013) and that county has three subdivisions (the coverage unit). `byName` still
 * searches the flat fixture list (for the directed "add a town").
 */
function countyGazetteer(): MockMunicipalityGazetteer
{
    return new MockMunicipalityGazetteer(
        municipalities: CoverageFixture::municipalities(),
        counties: [new County('34013', 'Essex', '34', '013')],
        subdivisions: [
            '34:013' => [
                new Municipality('3401305580', 'Belleville Twp', MunicipalityType::CountySubdivision, 'NJ', 40.79, -74.15),
                new Municipality('3401351210', 'Montclair Twp', MunicipalityType::CountySubdivision, 'NJ', 40.82, -74.21),
                new Municipality('3401321840', 'Essex Fells Boro', MunicipalityType::CountySubdivision, 'NJ', 40.82, -74.28),
            ],
        ],
        polygons: [
            '34013' => [[
                ['lat' => 40.75, 'lng' => -74.30],
                ['lat' => 40.85, 'lng' => -74.20],
                ['lat' => 40.70, 'lng' => -74.10],
            ]],
        ],
    );
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    app()->instance(MunicipalityGazetteer::class, countyGazetteer());
    // located point sits inside Essex County so the home county auto-resolves + computes
    app()->instance(Geocoder::class, new MockCensusGeocoder(CoverageFixture::A_LAT, CoverageFixture::A_LNG));
    app()->instance(PlacesProvider::class, new MockPlacesProvider);
});

function locSpoke(Site $site, string $name): Location
{
    return Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

it('default-selects the home county when a base is located in the background on open', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'address' => '1 Main St', 'lat' => null, 'lng' => null, 'county_geoids' => null]);

    Livewire::test(LocationsSetup::class)->set('siteId', $site->id);

    $loc->refresh();
    expect((float) $loc->lat)->toBe(CoverageFixture::A_LAT)
        ->and($loc->geocode_failed)->toBeFalse()
        ->and($loc->home_county_geoid)->toBe('34013')
        ->and($loc->county_geoids)->toBe(['34013']);
});

it('adds a manual location, locates it, default-selects the county, and computes coverage', function () {
    $site = Site::factory()->create();

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->call('startAdd')
        ->set('addName', 'Montclair')
        ->set('addAddress', '1 Bloomfield Ave, Montclair NJ')
        ->call('addManual')
        ->assertSet('computed', true);

    $loc = locSpoke($site, 'Montclair');
    expect($loc)->not->toBeNull()
        ->and((float) $loc->lat)->toBe(CoverageFixture::A_LAT)
        ->and($loc->county_geoids)->toBe(['34013'])
        ->and(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', 'county')->count())->toBe(3);
});

it('adds from a Google listing, keeping the point from the place details (no re-geocode)', function () {
    $site = Site::factory()->create();

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->call('addFromPlace', MockPlacesProvider::PLACE_ID);

    $loc = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('place_id', MockPlacesProvider::PLACE_ID)->first();
    expect($loc)->not->toBeNull()
        ->and((float) $loc->lat)->toBe(30.267153)   // straight from PlaceDetails, not overwritten by a geocode
        ->and($loc->geocode_failed)->toBeFalse()
        ->and($loc->home_county_geoid)->toBe('34013'); // county still resolved for the listing point
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

    // the override sets the spot + resolves the county
    $component
        ->set("manualLat.{$loc->id}", '40.73')
        ->set("manualLng.{$loc->id}", '-74.17')
        ->call('saveManualPoint', $loc->id);

    $loc->refresh();
    expect((float) $loc->lat)->toBe(40.73)
        ->and($loc->geocode_failed)->toBeFalse()
        ->and($loc->home_county_geoid)->toBe('34013');
});

it('toggles a county a location serves, persists it, and recomputes', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'lat' => 40.80, 'lng' => -74.20, 'home_county_geoid' => '34013', 'county_geoids' => []]);

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        // the county chip is an actual wire:click toggle in the DOM, not a dead label
        ->assertSeeHtml('wire:click="toggleCounty(\''.$loc->id.'\', \'34013\')"')
        ->call('toggleCounty', $loc->id, '34013')
        ->assertDispatched('locations-updated');

    expect(Location::withoutGlobalScope(SiteScope::class)->where('id', $loc->id)->value('county_geoids'))->toBe(['34013']);
});

it('adds a directed town to a location (priority page candidate) and marks it on the map', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG]);

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

it('shows the empty-state for a site with no locations', function () {
    $site = Site::factory()->create();

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->assertSee('No locations yet');
});

it('feeds the shared map with color-matched, located bases only (pins, no radius)', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair', 'lat' => 40.81, 'lng' => -74.22]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'lat' => 40.13, 'lng' => -75.41]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Unlocated', 'lat' => null, 'lng' => null]);

    $page = Livewire::test(LocationsSetup::class)->set('siteId', $site->id)->instance();
    $mapData = $page->mapData;
    $colors = $page->colors;

    expect($mapData)->toHaveCount(2) // the un-located base is excluded
        ->and(collect($mapData)->pluck('name'))->toContain('Montclair', 'Trooper')
        ->and(collect($mapData)->pluck('color')->unique())->toHaveCount(2) // distinct, matched colors
        ->and(collect($mapData)->first())->not->toHaveKey('radius') // coverage is county-based, not radial
        ->and($colors)->toHaveCount(3);
});

it('outlines the served counties on the map (polygons, GEOID-deduped across bases)', function () {
    $site = Site::factory()->create();
    // two located bases both serving Essex County → the polygon is drawn once
    Location::factory()->create(['site_id' => $site->id, 'name' => 'A', 'lat' => 40.80, 'lng' => -74.20, 'home_county_geoid' => '34013', 'county_geoids' => ['34013']]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'B', 'lat' => 40.82, 'lng' => -74.25, 'home_county_geoid' => '34013', 'county_geoids' => ['34013']]);

    $page = Livewire::test(LocationsSetup::class)->set('siteId', $site->id)->instance();
    $polys = $page->countyPolygons;

    expect($polys)->toHaveCount(1) // GEOID-deduped across the two bases
        ->and($polys[0]['geo_id'])->toBe('34013')
        ->and($polys[0]['rings'][0])->not->toBeEmpty();
});

it('draws no county polygons when no base has a selected county', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'lat' => 40.80, 'lng' => -74.20, 'home_county_geoid' => '34013', 'county_geoids' => []]);

    $page = Livewire::test(LocationsSetup::class)->set('siteId', $site->id)->instance();

    expect($page->countyPolygons)->toBe([]);
});

it('summary reflects overlap once coverage is computed', function () {
    $site = Site::factory()->create();
    // two located bases that both serve Essex County → they share every subdivision
    Location::factory()->create(['site_id' => $site->id, 'name' => 'A', 'lat' => 40.80, 'lng' => -74.20, 'home_county_geoid' => '34013', 'county_geoids' => ['34013']]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'B', 'lat' => 40.82, 'lng' => -74.25, 'home_county_geoid' => '34013', 'county_geoids' => ['34013']]);

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->call('compute')
        ->assertSet('computed', true)
        ->assertSee('overlapping'); // all 3 subdivisions shared across A + B
});
