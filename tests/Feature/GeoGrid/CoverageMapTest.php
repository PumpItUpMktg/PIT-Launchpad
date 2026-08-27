<?php

use App\GeoGrid\CoverageMap;
use App\GeoGrid\GeoGridMetrics;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;

/** A coverage-mode scan for (loc × kw): towns = list of [coverage_area_id, name, lat, lng, pop, rank]. */
function coverageScan(Site $site, Location $loc, Keyword $kw, array $towns, string $scannedAt): GeoGridScan
{
    $scan = GeoGridScan::create([
        'site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => $kw->id, 'provider' => 'dataforseo',
        'mode' => 'coverage', 'grid_size' => count($towns), 'spacing_miles' => 0, 'center_lat' => 40.8, 'center_lng' => -74.2,
        'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => $scannedAt,
    ]);
    foreach (array_values($towns) as $i => $t) {
        GeoGridPoint::create([
            'site_id' => $site->id, 'scan_id' => $scan->id, 'row' => 0, 'col' => $i,
            'coverage_area_id' => $t['id'], 'label' => $t['name'], 'lat' => $t['lat'], 'lng' => $t['lng'], 'rank' => $t['rank'],
        ]);
    }
    app(GeoGridMetrics::class)->recompute($scan);

    return $scan;
}

it('assembles a service\'s current town map, score, and history filmstrip', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump installation']);

    $big = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Big', 'population' => 90000, 'lat' => 40.85, 'lng' => -74.25, 'source_location_ids' => [$loc->id]]);
    $small = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Small', 'population' => 10000, 'lat' => 40.75, 'lng' => -74.15, 'source_location_ids' => [$loc->id]]);
    $towns = fn (int $bigRank, ?int $smallRank) => [
        ['id' => $big->id, 'name' => 'Big', 'lat' => 40.85, 'lng' => -74.25, 'pop' => 90000, 'rank' => $bigRank],
        ['id' => $small->id, 'name' => 'Small', 'lat' => 40.75, 'lng' => -74.15, 'pop' => 10000, 'rank' => $smallRank],
    ];

    // Big pop is 90% of the weight; score = 90 × credit(bigRank). #5 → 0.8 → 72; #1 → 1.0 → 90.
    coverageScan($site, $loc, $kw, $towns(5, null), '2026-07-01 10:00:00');   // earlier → 72.0
    coverageScan($site, $loc, $kw, $towns(1, null), '2026-08-01 10:00:00');   // latest  → 90.0

    $cov = app(CoverageMap::class)->for($loc->fresh());

    expect($cov['services'])->toHaveCount(1)
        ->and($cov['services'][0]['query'])->toBe('sump pump installation')
        ->and($cov['services'][0]['score'])->toBe(90.0)                 // latest scan's score
        ->and($cov['current']['score'])->toBe(90.0)
        ->and($cov['current']['delta'])->toBe(18.0)                     // 90 − 72 (improved)
        ->and($cov['current']['town_count'])->toBe(2)
        ->and($cov['current']['found_count'])->toBe(1)
        ->and($cov['history'])->toHaveCount(1)
        ->and($cov['history'][0]['score'])->toBe(72.0);

    // Markers are normalised into the SVG box and coloured by rank.
    $markers = collect($cov['current']['markers']);
    expect($markers)->toHaveCount(2);
    foreach ($markers as $m) {
        expect($m['x'])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(100.0)
            ->and($m['y'])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(100.0);
    }
    $bigMarker = $markers->firstWhere('label', 'Big');
    expect($bigMarker['rank'])->toBe(1)->and($bigMarker['color'])->toBe('#15803d');   // #1 → green
});

it('lets a keyword be selected and is tenant-isolated', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id]);
    $kwA = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'kw a']);
    $kwB = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'kw b']);
    $town = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'T', 'population' => 5000, 'lat' => 40.8, 'lng' => -74.2, 'source_location_ids' => [$loc->id]]);
    $t = [['id' => $town->id, 'name' => 'T', 'lat' => 40.8, 'lng' => -74.2, 'pop' => 5000, 'rank' => 2]];

    coverageScan($site, $loc, $kwA, $t, '2026-08-01 10:00:00');
    coverageScan($site, $loc, $kwB, $t, '2026-08-02 10:00:00');

    expect(app(CoverageMap::class)->for($loc->fresh())['services'])->toHaveCount(2);
    expect(app(CoverageMap::class)->for($loc->fresh(), $kwA->id)['keyword_id'])->toBe($kwA->id);

    // A fresh location on another site sees nothing.
    $other = Location::factory()->create(['site_id' => Site::factory()->create()->id]);
    expect(app(CoverageMap::class)->for($other)['services'])->toBe([]);
});
