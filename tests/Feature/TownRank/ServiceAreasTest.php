<?php

use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\JobCounty;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\ServiceAreas;

/**
 * Two service areas on one site: Warren County NJ (Hackettstown + Mansfield) and Northampton County PA
 * (Bethlehem). One keyword with a town-search Town Rank scan across all three towns, and a GBP coverage
 * scan for the Warren location only.
 *
 * @return array{site: Site, warren: Location, northampton: Location, keyword: Keyword, hack: CoverageArea, mans: CoverageArea, beth: CoverageArea}
 */
function serviceAreaSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.com']);
    JobCounty::factory()->create(['county_geoid' => '34041', 'name' => 'Warren', 'state' => 'NJ']);
    JobCounty::factory()->create(['county_geoid' => '42095', 'name' => 'Northampton', 'state' => 'PA']);
    $warren = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);
    $northampton = Location::factory()->create(['site_id' => $site->id, 'name' => 'Bethlehem office', 'lat' => 40.62, 'lng' => -75.37, 'home_county_geoid' => '42095', 'county_geoids' => []]);
    $hack = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'geo_id' => '3404128590', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => []]);
    $mans = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Mansfield', 'state' => 'NJ', 'geo_id' => '3404143440', 'population' => 7000, 'lat' => 40.80, 'lng' => -74.85, 'source_location_ids' => []]);
    $beth = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Bethlehem', 'state' => 'PA', 'geo_id' => '4209506088', 'population' => 75000, 'lat' => 40.62, 'lng' => -75.37, 'source_location_ids' => []]);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);

    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 3, 'found_count' => 2, 'scanned_at' => now()]);
    foreach ([[$hack, 2], [$mans, null], [$beth, 8]] as [$area, $rank]) {
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $area->id, 'label' => $area->name, 'state' => $area->state, 'lat' => $area->lat, 'lng' => $area->lng, 'query' => 'q', 'rank' => $rank, 'collected_at' => now()]);
    }

    $gg = GeoGridScan::create(['site_id' => $site->id, 'location_id' => $warren->id, 'keyword_id' => $keyword->id, 'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 2, 'spacing_miles' => 0, 'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now()]);
    GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $gg->id, 'row' => 0, 'col' => 0, 'lat' => 40.85, 'lng' => -74.83, 'rank' => 1, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown', 'collected_at' => now()]);
    GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $gg->id, 'row' => 0, 'col' => 1, 'lat' => 40.80, 'lng' => -74.85, 'rank' => null, 'coverage_area_id' => $mans->id, 'label' => 'Mansfield', 'collected_at' => now()]);

    return compact('site', 'warren', 'northampton', 'keyword', 'hack', 'mans', 'beth');
}

it('lists one service area per location with its counties labelled, its town count, and the tracked keyword count', function () {
    $f = serviceAreaSite();

    $areas = app(ServiceAreas::class)->areas($f['site']);

    expect($areas)->toHaveCount(2)
        ->and(array_column($areas, 'name'))->toBe(['Bethlehem office', 'Hackettstown office'])
        ->and($areas[1]['counties'])->toBe([['geoid' => '34041', 'label' => 'Warren County, NJ']])
        ->and($areas[1]['towns'])->toBe(2)
        ->and($areas[0]['counties'][0]['label'])->toBe('Northampton County, PA')
        ->and($areas[0]['towns'])->toBe(1)
        ->and($areas[0]['keywords'])->toBe(1);
});

it('builds an area page: one card per keyword with the website map sliced to the area, the GBP map, and the metrics slot', function () {
    $f = serviceAreaSite();

    $area = app(ServiceAreas::class)->area($f['site'], $f['warren']->id);

    expect($area)->not->toBeNull()
        ->and($area['location']['name'])->toBe('Hackettstown office')
        ->and($area['towns'])->toBe(2)
        ->and($area['cards'])->toHaveCount(1);

    $card = $area['cards'][0];
    expect($card['query'])->toBe('sump pump service');

    // Website: only the two Warren towns, not Bethlehem; Hackettstown #2 (top-3), Mansfield not found.
    $web = $card['web'];
    expect($web['mode'])->toBe('town_query')
        ->and($web['status'])->toBe('complete')
        ->and(collect($web['markers'])->pluck('rank', 'id')->all())->toBe([(string) $f['hack']->id => 2, (string) $f['mans']->id => null])
        ->and($web['summary']['top3'])->toBe(1)
        ->and($web['summary']['not_found'])->toBe(1);

    // GBP: the coverage scan's two towns, Hackettstown in the pack, Mansfield absent.
    $gbp = $card['gbp'];
    expect($gbp['status'])->toBe('complete')
        ->and(collect($gbp['markers'])->pluck('rank', 'id')->all())->toBe([(string) $f['hack']->id => 1, (string) $f['mans']->id => null])
        ->and($gbp['summary'])->toMatchArray(['top3' => 1, 'absent' => 1]);

    // Both maps share the area's bounding box: the same town lands on the same spot.
    $webHack = collect($web['markers'])->firstWhere('id', (string) $f['hack']->id);
    $gbpHack = collect($gbp['markers'])->firstWhere('id', (string) $f['hack']->id);
    expect([$webHack['x'], $webHack['y']])->toBe([$gbpHack['x'], $gbpHack['y']]);

    // Metrics: provisional shares now, the score itself explicitly undefined.
    $metrics = collect($card['metrics'])->keyBy('key');
    expect($metrics['web_page1_share']['value'])->toBe('50%')
        ->and($metrics['web_top3_share']['value'])->toBe('50%')
        ->and($metrics['gbp_top3_share']['value'])->toBe('50%')
        ->and($metrics['score']['value'])->toBeNull();
});

it('leaves the GBP map null where no coverage scan exists for that location, and the website map null without a Town Rank scan', function () {
    $f = serviceAreaSite();
    Keyword::factory()->create(['site_id' => $f['site']->id, 'query' => 'mold remediation', 'track_town_rank' => true]);   // tracked, never scanned

    $area = app(ServiceAreas::class)->area($f['site'], $f['northampton']->id);
    $byQuery = collect($area['cards'])->keyBy('query');

    expect($area['towns'])->toBe(1)
        ->and($byQuery['sump pump service']['web']['summary']['page1'])->toBe(1)   // Bethlehem #8
        ->and($byQuery['sump pump service']['gbp'])->toBeNull()                    // the coverage scan was Warren's
        ->and($byQuery['mold remediation']['web'])->toBeNull()
        ->and($byQuery['mold remediation']['gbp'])->toBeNull()
        ->and(collect($byQuery['mold remediation']['metrics'])->pluck('value')->all())->toBe([null, null, null, null]);

    expect(app(ServiceAreas::class)->area($f['site'], 'not-a-location'))->toBeNull();
});
