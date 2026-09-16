<?php

use App\Enums\UserRole;
use App\Filament\Pages\LocationGeoGrid;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\JobCounty;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

/** A coverage-mode scan over the location's served towns — one Maps search per town centre. */
function seedTownScan(Site $site, Location $location, Keyword $keyword, array $ranks): GeoGridScan
{
    $scan = GeoGridScan::create([
        'site_id' => $site->id, 'location_id' => $location->id, 'keyword_id' => $keyword->id,
        'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => count($ranks), 'spacing_miles' => 0,
        'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20,
        'arp' => 3, 'atrp' => 6, 'solv' => 33.33, 'found_rate' => 88, 'status' => 'complete', 'scanned_at' => now(),
    ]);
    $col = 0;
    foreach ($ranks as $name => $rank) {
        $town = CoverageArea::withoutGlobalScopes()->where('site_id', $site->id)->where('name', $name)->sole();
        GeoGridPoint::create([
            'site_id' => $site->id, 'scan_id' => $scan->id, 'row' => 0, 'col' => $col++,
            'lat' => $town->lat, 'lng' => $town->lng, 'rank' => $rank,
            'coverage_area_id' => $town->id, 'geo_id' => $town->geo_id, 'label' => $town->name, 'collected_at' => now(),
            'competitors' => [['name' => 'Rival Plumbing', 'place_id' => 'ChIJ_rival', 'rank' => 1]],
        ]);
    }

    return $scan;
}

/** A GBP location serving two Warren County towns, with the county + one town boundary known. */
function gridPageLocation(Site $site): Location
{
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(polygons: [
        '34041' => [[['lat' => 40.95, 'lng' => -74.95], ['lat' => 40.95, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.95]]],
        '3404128590' => [[['lat' => 40.87, 'lng' => -74.85], ['lat' => 40.87, 'lng' => -74.81], ['lat' => 40.83, 'lng' => -74.81], ['lat' => 40.83, 'lng' => -74.85]]],
    ]));
    JobCounty::factory()->create(['county_geoid' => '34041', 'name' => 'Warren', 'state' => 'NJ']);
    $location = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Downtown', 'gbp_url' => 'https://maps.google.com/?cid=123',
        'place_id' => 'ChIJ_acme', 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => [],
    ]);
    foreach ([['Hackettstown', '3404128590', 40.85], ['Mansfield', '3404143440', 40.80]] as [$name, $geoId, $lat]) {
        CoverageArea::factory()->create([
            'site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'geo_id' => $geoId, 'population' => 9000,
            'lat' => $lat, 'lng' => -74.83, 'source_location_ids' => [$location->id],
        ]);
    }

    return $location;
}

it('is operator-only', function () {
    expect(LocationGeoGrid::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(LocationGeoGrid::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(LocationGeoGrid::canAccess())->toBeTrue();
});

it('renders a card per grid keyword for the selected location', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create(['brand_name' => 'Acme Plumbing']);
    $location = gridPageLocation($site);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'emergency plumber']);
    seedTownScan($site, $location, $kw, ['Hackettstown' => 2, 'Mansfield' => null]);

    Livewire::test(LocationGeoGrid::class)
        ->set('siteId', $site->id)
        ->set('locationId', $location->id)
        ->assertOk()
        ->assertSee('emergency plumber')
        ->assertSee('Downtown')
        ->assertSee('Warren County, NJ')                  // the county the towns sit in, labelled
        ->assertSeeHtml('class="lgg-county"')             // its outline under the towns
        ->assertSeeHtml('class="lgg-town"')               // Hackettstown's own boundary, coloured
        ->assertSeeHtml('class="lgg-dot"')                // Mansfield has no boundary, so it keeps a dot
        ->assertSeeHtml('class="lgg-rank"')               // and the pack position is written into the town
        ->assertSee('>2<', escape: false);
});

it('shows an empty state for a GBP location with no scans', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create();
    $location = gridPageLocation($site);

    Livewire::test(LocationGeoGrid::class)
        ->set('siteId', $site->id)
        ->set('locationId', $location->id)
        ->assertOk()
        ->assertSee('No map-pack scans for this location yet');
});

it('aggregates a card\'s competitors for the expanded panel', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $card = ['towns' => [
        ['competitors' => [['name' => 'Rival A', 'rank' => 1]]],
        ['competitors' => [['name' => 'Rival A', 'rank' => 2], ['name' => 'Rival B', 'rank' => 5]]],
    ]];

    $top = (new LocationGeoGrid)->topCompetitors($card);

    expect($top[0])->toMatchArray(['name' => 'Rival A', 'points' => 2, 'best' => 1])
        ->and($top[1])->toMatchArray(['name' => 'Rival B', 'points' => 1, 'best' => 5]);
});
