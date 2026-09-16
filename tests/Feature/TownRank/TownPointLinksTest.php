<?php

use App\Locations\CoverageWriter;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\TownPointLinks;
use App\TownRank\TownRankPoints;
use App\TownRank\TownRankReport;
use App\TownRank\TownRankScanner;
use Illuminate\Support\Facades\Http;

/**
 * A site with two towns, a completed town-search scan that ranked both — and then a coverage REBUILD, which
 * is what {@see CoverageWriter::write()} does: every computed CoverageArea row is deleted and
 * re-inserted with a fresh id. The scan's points keep the old ids.
 */
function rebuiltCoverageSite(bool $stampGeoIds = true): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);
    $mk = fn (string $name, string $geoId, float $lat, float $lng, int $pop): CoverageArea => CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'geo_id' => $geoId, 'population' => $pop,
        'lat' => $lat, 'lng' => $lng, 'source_location_ids' => [$loc->id],
    ]);
    $oldHack = $mk('Hackettstown', '3404128590', 40.85, -74.83, 10000);
    $oldMans = $mk('Mansfield', '3404143440', 40.80, -74.85, 7000);

    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 2, 'found_count' => 2, 'scanned_at' => now()->subDays(2)]);
    foreach ([[$oldHack, 2], [$oldMans, 7]] as [$area, $rank]) {
        TownRankPoint::create([
            'site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $area->id,
            'geo_id' => $stampGeoIds ? $area->geo_id : null,
            'label' => $area->name, 'state' => 'NJ', 'lat' => $area->lat, 'lng' => $area->lng,
            'query' => 'q', 'rank' => $rank, 'top_results' => [['position' => 1, 'url' => 'https://rival.com', 'domain' => 'rival.com']], 'collected_at' => now()->subDays(2),
        ]);
    }

    // The rebuild: same towns, same GEOIDs, brand-new row ids.
    CoverageArea::withoutGlobalScopes()->whereIn('id', [$oldHack->id, $oldMans->id])->delete();
    $newHack = $mk('Hackettstown', '3404128590', 40.85, -74.83, 10000);
    $newMans = $mk('Mansfield', '3404143440', 40.80, -74.85, 7000);
    app(TownRankPoints::class)->forget();

    return compact('site', 'keyword', 'scan', 'newHack', 'newMans');
}

it('keeps a scan\'s ranks on the board after a coverage rebuild gave every town a new row id', function () {
    $f = rebuiltCoverageSite();

    $report = app(TownRankReport::class)->forKeyword($f['site'], $f['keyword']);

    $byId = collect($report['rows'])->keyBy('coverage_area_id');
    expect($byId[(string) $f['newHack']->id]['town_rank'])->toBe(2)
        ->and($byId[(string) $f['newHack']->id]['town_state'])->toBe('top3')
        ->and($byId[(string) $f['newMans']->id]['town_rank'])->toBe(7)
        ->and($report['summary']['town_query']['top3'])->toBe(1)
        ->and($report['summary']['town_query']['page1'])->toBe(1)
        ->and($report['summary']['town_query']['not_found'])->toBe(0);   // the grey board, gone
});

it('links by town name + state when the points predate the GEOID stamp', function () {
    $f = rebuiltCoverageSite(stampGeoIds: false);

    $report = app(TownRankReport::class)->forKeyword($f['site'], $f['keyword']);

    $byId = collect($report['rows'])->keyBy('coverage_area_id');
    expect($byId[(string) $f['newHack']->id]['town_rank'])->toBe(2)
        ->and($byId[(string) $f['newMans']->id]['town_rank'])->toBe(7)
        ->and($report['summary']['town_query']['not_found'])->toBe(0);
});

it('prefers the GEOID over a stale row id, and never guesses through a label two towns share', function () {
    $towns = [
        ['coverage_area_id' => 'town-a', 'geo_id' => '3404128590', 'label' => 'Washington', 'state' => 'NJ'],
        ['coverage_area_id' => 'town-b', 'geo_id' => '3404143440', 'label' => 'Washington', 'state' => 'NJ'],
        ['coverage_area_id' => 'town-c', 'geo_id' => '3404199999', 'label' => 'Hackettstown', 'state' => 'NJ'],
    ];
    [$byGeoId, $byId, $byName] = TownPointLinks::indexes($towns);
    $point = fn (array $attrs): TownRankPoint => new TownRankPoint(['lat' => 40.0, 'lng' => -74.0, 'query' => 'q', ...$attrs]);

    // GEOID wins even when the stored row id still resolves to a different town.
    expect(TownPointLinks::resolve($point(['geo_id' => '3404143440', 'coverage_area_id' => 'town-a', 'label' => 'Washington', 'state' => 'NJ']), $byGeoId, $byId, $byName))->toBe('town-b')
        // No GEOID, live row id: that town.
        ->and(TownPointLinks::resolve($point(['coverage_area_id' => 'town-c', 'label' => 'Hackettstown', 'state' => 'NJ']), $byGeoId, $byId, $byName))->toBe('town-c')
        // No GEOID, dead row id, unique label: the name match.
        ->and(TownPointLinks::resolve($point(['coverage_area_id' => 'gone', 'label' => 'Hackettstown', 'state' => 'NJ']), $byGeoId, $byId, $byName))->toBe('town-c')
        // No GEOID, dead row id, a label two towns share: unresolved, never a guess.
        ->and(TownPointLinks::resolve($point(['coverage_area_id' => 'gone', 'label' => 'Washington', 'state' => 'NJ']), $byGeoId, $byId, $byName))->toBeNull()
        // A town that is no longer covered at all.
        ->and(TownPointLinks::resolve($point(['geo_id' => '9999999999', 'coverage_area_id' => 'gone', 'label' => 'Elsewhere', 'state' => 'PA']), $byGeoId, $byId, $byName))->toBeNull();
});

it('stamps the GEOID on a point when the scan is posted', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'geo_id' => '3404128590', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => [$loc->id]]);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);
    Http::fake([
        '*/serp/google/organic/task_post' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'otask-0', 'status_code' => 20000]]]),
    ]);

    $scan = app(TownRankScanner::class)->post($site, $keyword, 'town_query');

    expect($scan->points()->first()->geo_id)->toBe('3404128590');
});
