<?php

use App\GeoGrid\GeoGridBoard;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\JobCounty;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;

/**
 * A GBP location serving Warren County NJ with three towns. The gazetteer knows the county's outline and
 * Hackettstown's own shape; the other two towns have no boundary and so keep their dot.
 *
 * @return array{site: Site, location: Location, towns: array<string, CoverageArea>}
 */
function boardArea(): array
{
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(polygons: [
        '34041' => [[['lat' => 40.95, 'lng' => -74.95], ['lat' => 40.95, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.95]]],
        '3404128590' => [[['lat' => 40.87, 'lng' => -74.85], ['lat' => 40.87, 'lng' => -74.81], ['lat' => 40.83, 'lng' => -74.81], ['lat' => 40.83, 'lng' => -74.85]]],
    ]));
    $site = Site::factory()->create();
    JobCounty::factory()->create(['county_geoid' => '34041', 'name' => 'Warren', 'state' => 'NJ']);
    $location = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);
    $towns = [];
    foreach ([['Hackettstown', '3404128590', 10000, 40.85], ['Mansfield', '3404143440', 7000, 40.80], ['Washington', '3404177000', 5000, 40.76]] as [$name, $geoId, $pop, $lat]) {
        $towns[$name] = CoverageArea::factory()->create([
            'site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'geo_id' => $geoId, 'population' => $pop,
            'lat' => $lat, 'lng' => -74.83, 'source_location_ids' => [$location->id],
        ]);
    }

    return ['site' => $site, 'location' => $location, 'towns' => $towns];
}

/** A coverage-mode scan: one Maps search per town, ranks keyed by town name (null = absent from the pack). */
function boardScan(array $area, Keyword $keyword, array $ranksByTown, array $attrs = []): GeoGridScan
{
    $scan = GeoGridScan::create(array_merge([
        'site_id' => $area['site']->id, 'location_id' => $area['location']->id, 'keyword_id' => $keyword->id,
        'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => count($ranksByTown), 'spacing_miles' => 0,
        'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20,
        'status' => 'complete', 'scanned_at' => now(),
    ], $attrs));
    $col = 0;
    foreach ($ranksByTown as $name => $rank) {
        $town = $area['towns'][$name];
        GeoGridPoint::create([
            'site_id' => $area['site']->id, 'scan_id' => $scan->id, 'row' => 0, 'col' => $col++,
            'lat' => $town->lat, 'lng' => $town->lng, 'rank' => $rank, 'coverage_area_id' => $town->id,
            'geo_id' => $town->geo_id, 'label' => $town->name, 'collected_at' => now(),
        ]);
    }

    return $scan;
}

it('emits one card per keyword: the map-pack rank per town, on the area\'s own county and town outlines', function () {
    $area = boardArea();
    $kw = Keyword::factory()->create(['site_id' => $area['site']->id, 'query' => 'emergency plumber']);
    boardScan($area, $kw, ['Hackettstown' => 1, 'Mansfield' => 9, 'Washington' => null], ['arp' => 5, 'atrp' => 5, 'solv' => 33.33, 'found_rate' => 66.7]);

    $board = app(GeoGridBoard::class)->for($area['location']->fresh());

    // The drawing frame is the area's, shared by every card: the county outline and each town's boundary.
    expect($board['keyword_count'])->toBe(1)
        ->and($board['towns'])->toBe(3)
        ->and($board['counties'][0]['label'])->toBe('Warren County, NJ')
        ->and($board['outlines'][0]['paths'])->not->toBeEmpty()
        ->and(array_keys($board['town_paths']))->toBe([(string) $area['towns']['Hackettstown']->id]);   // only the town with a boundary

    $card = $board['cards'][0];
    expect($card['keyword'])->toBe('emergency plumber')
        ->and((float) $card['atrp'])->toBe(5.0)
        ->and($card['summary'])->toMatchArray(['top3' => 1, 'top10' => 1, 'absent' => 1, 'beyond' => 0]);

    // Best position first, each town carrying its rank, its colour and the spot the number is drawn at.
    $towns = collect($card['towns']);
    expect($towns->pluck('label')->all())->toBe(['Hackettstown', 'Mansfield', 'Washington'])
        ->and($towns->pluck('rank')->all())->toBe([1, 9, null])
        ->and($towns->firstWhere('label', 'Hackettstown')['color'])->toBe('#15803d')   // rank 1 → green
        ->and($towns->firstWhere('label', 'Mansfield')['color'])->toBe('#ca8a04')      // rank 9 → amber
        ->and($towns->firstWhere('label', 'Hackettstown'))->toHaveKeys(['x', 'y', 'population', 'competitors']);
});

it('colours each town by its movement against the previous scan', function () {
    $area = boardArea();
    $kw = Keyword::factory()->create(['site_id' => $area['site']->id, 'query' => 'drain cleaning']);
    boardScan($area, $kw, ['Hackettstown' => 5, 'Mansfield' => 5, 'Washington' => 5], ['atrp' => 5, 'scanned_at' => now()->subMonth()]);
    boardScan($area, $kw, ['Hackettstown' => 2, 'Mansfield' => 5, 'Washington' => 5], ['atrp' => 4, 'scanned_at' => now()]);

    $card = app(GeoGridBoard::class)->for($area['location']->fresh())['cards'][0];
    $hack = collect($card['towns'])->firstWhere('label', 'Hackettstown');

    expect((float) $card['delta_atrp'])->toBe(-1.0)
        ->and($card['prev_scanned_at'])->not->toBeNull()
        ->and($hack['rank'])->toBe(2)
        ->and($hack['prev_rank'])->toBe(5)
        ->and($hack['move'])->toBe(3)                     // 5 → 2, moved up 3
        ->and($hack['delta_color'])->toBe('#15803d');     // improved → green
});

it('orders cards worst-ATRP first so the weakest keyword catches the eye', function () {
    $area = boardArea();
    $strong = Keyword::factory()->create(['site_id' => $area['site']->id, 'query' => 'strong kw']);
    $weak = Keyword::factory()->create(['site_id' => $area['site']->id, 'query' => 'weak kw']);
    boardScan($area, $strong, ['Hackettstown' => 1], ['atrp' => 2]);
    boardScan($area, $weak, ['Hackettstown' => 18], ['atrp' => 18]);

    $board = app(GeoGridBoard::class)->for($area['location']->fresh());

    expect($board['cards'][0]['keyword'])->toBe('weak kw')
        ->and($board['cards'][1]['keyword'])->toBe('strong kw');
});

it('counts a town still collecting as pending, never as absent', function () {
    $area = boardArea();
    $kw = Keyword::factory()->create(['site_id' => $area['site']->id, 'query' => 'sump pump service']);
    $scan = boardScan($area, $kw, ['Hackettstown' => 1, 'Mansfield' => null], ['status' => 'pending']);
    $scan->points()->where('label', 'Mansfield')->update(['collected_at' => null]);

    $card = app(GeoGridBoard::class)->for($area['location']->fresh())['cards'][0];

    expect($card['summary'])->toMatchArray(['top3' => 1, 'pending' => 1, 'absent' => 0])
        ->and(collect($card['towns'])->firstWhere('label', 'Mansfield')['pending'])->toBeTrue();
});

it('ignores the retired lattice scans entirely', function () {
    $area = boardArea();
    $kw = Keyword::factory()->create(['site_id' => $area['site']->id, 'query' => 'lattice kw']);
    boardScan($area, $kw, ['Hackettstown' => 1], ['mode' => 'grid']);

    expect(app(GeoGridBoard::class)->for($area['location']->fresh())['keyword_count'])->toBe(0);
});

it('is tenant-isolated — never reads another site\'s scans', function () {
    $area = boardArea();
    $kw = Keyword::factory()->create(['site_id' => $area['site']->id, 'query' => 'a kw']);
    boardScan($area, $kw, ['Hackettstown' => 1]);

    $other = Location::factory()->create(['site_id' => Site::factory()->create()->id, 'home_county_geoid' => '34041', 'county_geoids' => []]);
    expect(app(GeoGridBoard::class)->for($other)['keyword_count'])->toBe(0);
});
