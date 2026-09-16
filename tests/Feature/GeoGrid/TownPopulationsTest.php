<?php

use App\GeoGrid\CoverageMap;
use App\GeoGrid\GeoGridMetrics;
use App\GeoGrid\TownPopulations;
use App\Locations\CoverageWriter;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;

/**
 * A coverage scan over two towns — one ranked #1 (population 30k), one absent (10k) — and then the coverage
 * REBUILD that {@see CoverageWriter::write()} performs: every computed town row deleted and
 * re-inserted with a new id. The scan's points keep the ids that are now gone.
 */
function populationFixture(bool $stampGeoIds = true): array
{
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'is_grid_keyword' => true]);
    $mk = fn (string $name, string $geoId, int $pop, float $lat): CoverageArea => CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'geo_id' => $geoId, 'population' => $pop,
        'lat' => $lat, 'lng' => -74.83, 'source_location_ids' => [$loc->id],
    ]);
    $big = $mk('Hackettstown', '3404128590', 30000, 40.85);
    $small = $mk('Mansfield', '3404143440', 10000, 40.80);

    $scan = GeoGridScan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => $kw->id, 'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 2, 'spacing_miles' => 0, 'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now()]);
    foreach ([[$big, 1, 0], [$small, null, 1]] as [$area, $rank, $col]) {
        GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'row' => 0, 'col' => $col, 'lat' => $area->lat, 'lng' => -74.83, 'rank' => $rank, 'coverage_area_id' => $area->id, 'geo_id' => $stampGeoIds ? $area->geo_id : null, 'label' => $area->name, 'collected_at' => now()]);
    }

    return compact('site', 'loc', 'kw', 'scan', 'big', 'small');
}

function rebuildCoverage(array $f): void
{
    foreach ([$f['big'], $f['small']] as $area) {
        $attrs = $area->only(['site_id', 'name', 'state', 'geo_id', 'population', 'lat', 'lng', 'source_location_ids']);
        $area->delete();
        CoverageArea::factory()->create($attrs);
    }
    app(TownPopulations::class)->forget();
}

it('weighs a point by the town it measures, before and after a coverage rebuild replaced every town row', function () {
    $f = populationFixture();
    $point = $f['scan']->points()->where('label', 'Hackettstown')->sole();

    expect(app(TownPopulations::class)->of((string) $f['site']->id, $point))->toBe(30000);

    rebuildCoverage($f);

    // The stored row id is dead; the GEOID still finds the town, so the weight is unchanged.
    expect(app(TownPopulations::class)->of((string) $f['site']->id, $point->fresh()))->toBe(30000);
});

it('falls back to the town name for points written before the GEOID stamp, and weighs an uncovered town 0', function () {
    $f = populationFixture(stampGeoIds: false);
    rebuildCoverage($f);
    $point = $f['scan']->points()->where('label', 'Hackettstown')->sole();
    $orphan = new GeoGridPoint(['lat' => 1.0, 'lng' => 1.0, 'row' => 0, 'col' => 9, 'coverage_area_id' => 'gone', 'label' => 'Nowhere']);

    expect(app(TownPopulations::class)->of((string) $f['site']->id, $point))->toBe(30000)
        ->and(app(TownPopulations::class)->of((string) $f['site']->id, $orphan))->toBe(0);
});

it('keeps the population-weighted coverage numbers whole after a rebuild', function () {
    $f = populationFixture();
    $metrics = app(GeoGridMetrics::class);

    // 30k of 40k people are in a town we rank in, and it's top-3: found rate and SoLV are both 75%.
    $before = $metrics->recompute($f['scan']);
    expect((float) $before->pop_found_rate)->toBe(75.0)->and((float) $before->pop_solv)->toBe(75.0);

    rebuildCoverage($f);

    $after = $metrics->recompute($f['scan']->fresh());
    expect((float) $after->pop_found_rate)->toBe(75.0)   // a stale link would weigh every town 0 and blank these
        ->and((float) $after->pop_solv)->toBe(75.0);
});

it('keeps each town\'s population on the coverage map after a rebuild', function () {
    $f = populationFixture();
    rebuildCoverage($f);

    $board = app(CoverageMap::class)->for($f['loc']->fresh(), (string) $f['kw']->id);
    $populations = collect($board['current']['markers'])->pluck('population', 'label')->all();

    expect($populations)->toBe(['Hackettstown' => 30000, 'Mansfield' => 10000])
        ->and($board['current']['score'])->not->toBeNull();
});
