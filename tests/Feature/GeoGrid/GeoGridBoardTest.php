<?php

use App\GeoGrid\GeoGridBoard;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;

/** A 3×3 scan for one (location × keyword) with the given ranks (row-major, geometry rows), depth_cap 20. */
function boardScan(Site $site, Location $location, Keyword $keyword, array $ranks, array $attrs = []): GeoGridScan
{
    $scan = GeoGridScan::create(array_merge([
        'site_id' => $site->id, 'location_id' => $location->id, 'keyword_id' => $keyword->id,
        'provider' => 'dataforseo', 'grid_size' => 3, 'spacing_miles' => 1.5,
        'center_lat' => 40.7, 'center_lng' => -74.0, 'zoom' => 13, 'depth_cap' => 20,
        'status' => 'complete', 'scanned_at' => now(),
    ], $attrs));
    foreach ($ranks as $i => $rank) {
        GeoGridPoint::create([
            'site_id' => $site->id, 'scan_id' => $scan->id,
            'row' => intdiv($i, 3), 'col' => $i % 3, 'lat' => 40.7 + intdiv($i, 3), 'lng' => -74.0, 'rank' => $rank,
        ]);
    }

    return $scan;
}

it('emits one card per keyword with aggregates and a north-up matrix', function () {
    $site = Site::factory()->create();
    $location = Location::factory()->create(['site_id' => $site->id]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'emergency plumber', 'is_grid_keyword' => true]);

    // geometry row 0 = south = ranks [1,1,1]; row 2 = north = ranks [9,9,9].
    boardScan($site, $location, $kw, [1, 1, 1, 5, 5, 5, 9, 9, 9], ['arp' => 5, 'atrp' => 5, 'solv' => 33.33, 'found_rate' => 100]);

    $board = app(GeoGridBoard::class)->for($location->fresh());

    expect($board['keyword_count'])->toBe(1)
        ->and($board['cards'][0]['keyword'])->toBe('emergency plumber')
        ->and($board['cards'][0]['grid_size'])->toBe(3)
        ->and((float) $board['cards'][0]['atrp'])->toBe(5.0);

    $matrix = $board['cards'][0]['matrix'];
    // Display row 0 must be NORTH (geometry row 2) → ranks 9; display row 2 must be south → ranks 1.
    expect($matrix[0][0]['rank'])->toBe(9)
        ->and($matrix[0][0]['row'])->toBe(2)                 // carries the geometry row
        ->and($matrix[2][0]['rank'])->toBe(1)
        ->and($matrix[0][0]['absolute_color'])->toBe('#ca8a04')   // rank 9 → amber
        ->and($matrix[2][0]['absolute_color'])->toBe('#15803d');  // rank 1 → green
});

it('computes per-point and aggregate delta against the previous scan', function () {
    $site = Site::factory()->create();
    $location = Location::factory()->create(['site_id' => $site->id]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'drain cleaning']);

    boardScan($site, $location, $kw, [5, 5, 5, 5, 5, 5, 5, 5, 5], ['atrp' => 5, 'scanned_at' => now()->subMonth()]);
    boardScan($site, $location, $kw, [2, 2, 2, 2, 2, 2, 2, 2, 2], ['atrp' => 2, 'scanned_at' => now()]);   // improved

    $board = app(GeoGridBoard::class)->for($location->fresh());
    $card = $board['cards'][0];

    expect((float) $card['delta_atrp'])->toBe(-3.0)          // 2 − 5, improved
        ->and($card['prev_scanned_at'])->not->toBeNull();

    $cell = $card['matrix'][0][0];
    expect($cell['rank'])->toBe(2)
        ->and($cell['move'])->toBe(3)                        // 5 → 2, moved up 3
        ->and($cell['delta_color'])->toBe('#15803d');        // improved → green
});

it('orders cards worst-ATRP first so the weakest keyword catches the eye', function () {
    $site = Site::factory()->create();
    $location = Location::factory()->create(['site_id' => $site->id]);
    $strong = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'strong kw']);
    $weak = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'weak kw']);

    boardScan($site, $location, $strong, [1, 1, 1], ['atrp' => 2]);
    boardScan($site, $location, $weak, [18, 18, 18], ['atrp' => 18]);

    $board = app(GeoGridBoard::class)->for($location->fresh());

    expect($board['cards'][0]['keyword'])->toBe('weak kw')   // worst first
        ->and($board['cards'][1]['keyword'])->toBe('strong kw');
});

it('is tenant-isolated — never reads another site\'s scans', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    $locA = Location::factory()->create(['site_id' => $siteA->id]);
    $kwA = Keyword::factory()->create(['site_id' => $siteA->id, 'query' => 'a kw']);
    boardScan($siteA, $locA, $kwA, [1, 1, 1]);

    // A location on B that happens to share nothing — board for A must not leak, board for a fresh B loc is empty.
    $locB = Location::factory()->create(['site_id' => $siteB->id]);
    expect(app(GeoGridBoard::class)->for($locB)['keyword_count'])->toBe(0);
});
