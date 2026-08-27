<?php

use App\GeoGrid\GeoGridCalibration;
use App\GeoGrid\LocalFalconGrid;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * A 3×3 scan whose 9 points carry distinct lat/lng (spaced ~0.02°) and the given ranks (row-major).
 * spacing_miles 1.5 → match tolerance ≈ 0.013°, so a Local Falcon point at the same coord aligns cleanly.
 */
function calibScan(Site $site, array $ranks, array $agg = []): GeoGridScan
{
    $scan = GeoGridScan::create(array_merge([
        'site_id' => $site->id, 'location_id' => (string) Str::ulid(),
        'keyword_id' => (string) Str::ulid(), 'provider' => 'dataforseo',
        'grid_size' => 3, 'spacing_miles' => 1.5, 'center_lat' => 40.70, 'center_lng' => -74.00,
        'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now(),
    ], $agg));
    foreach ($ranks as $i => $rank) {
        $r = intdiv($i, 3);
        $c = $i % 3;
        GeoGridPoint::create([
            'site_id' => $site->id, 'scan_id' => $scan->id, 'row' => $r, 'col' => $c,
            'lat' => 40.70 + $r * 0.02, 'lng' => -74.00 + $c * 0.02, 'rank' => $rank,
        ]);
    }

    return $scan;
}

/** Local Falcon points at the same coordinates as calibScan's grid, with the given ranks (row-major). */
function lfPoints(array $ranks): array
{
    $out = [];
    foreach ($ranks as $i => $rank) {
        $out[] = ['lat' => 40.70 + intdiv($i, 3) * 0.02, 'lng' => -74.00 + ($i % 3) * 0.02, 'rank' => $rank];
    }

    return $out;
}

it('accepts when points and coverage agree', function () {
    $site = Site::factory()->create();
    $scan = calibScan($site, [1, 2, 3, 4, 5, 6, 7, 8, null], ['atrp' => 6.5, 'arp' => 4.5, 'solv' => 33.33, 'found_rate' => 88.89]);

    $result = app(GeoGridCalibration::class)->compare($scan, lfPoints([1, 2, 3, 4, 5, 6, 7, 8, null]));

    expect($result['median_abs_diff'])->toBe(0.0)
        ->and($result['found_agreement'])->toBe(1.0)
        ->and($result['passes']['point_level'])->toBeTrue()
        ->and($result['passes']['coverage'])->toBeTrue()
        ->and($result['verdict'])->toBe('accept')
        ->and($result['matched'])->toBe(9);
});

it('flags TUNE when point-level ranks disagree beyond the threshold', function () {
    $site = Site::factory()->create();
    $scan = calibScan($site, [1, 2, 3, 4, 5, 6, 7, 8, 9]);

    // Every point off by 3 → median abs diff 3 > 1, but coverage still 100%.
    $result = app(GeoGridCalibration::class)->compare($scan, lfPoints([4, 5, 6, 7, 8, 9, 10, 11, 12]));

    expect($result['median_abs_diff'])->toBe(3.0)
        ->and($result['found_agreement'])->toBe(1.0)
        ->and($result['passes']['point_level'])->toBeFalse()
        ->and($result['passes']['coverage'])->toBeTrue()
        ->and($result['verdict'])->toBe('tune')
        ->and($result['diagnosis'])->toContain('geometry/zoom');
});

it('flags TUNE when found/not-found coverage diverges', function () {
    $site = Site::factory()->create();
    // We rank everywhere; Local Falcon finds nobody at 5 of 9 cells → agreement 4/9 ≈ 44%.
    $scan = calibScan($site, [1, 1, 1, 1, 1, 1, 1, 1, 1]);

    $result = app(GeoGridCalibration::class)->compare($scan, lfPoints([1, 1, 1, 1, null, null, null, null, null]));

    expect($result['found_agreement'])->toBeLessThan(0.9)
        ->and($result['passes']['coverage'])->toBeFalse()
        ->and($result['verdict'])->toBe('tune')
        ->and($result['diagnosis'])->toContain('coverage');
});

it('recomputes Local Falcon aggregates with our formula so a formula-only divergence is visible', function () {
    $site = Site::factory()->create();
    // Points identical → point-level agrees; but our stored ATRP is deliberately "wrong" (5) vs the
    // recomputed Local Falcon ATRP from the same ranks — proving the aggregate row isolates a formula bug.
    $scan = calibScan($site, [1, 2, 3, 4, 5, 6, 7, 8, null], ['atrp' => 5.0]);

    $result = app(GeoGridCalibration::class)->compare($scan, lfPoints([1, 2, 3, 4, 5, 6, 7, 8, null]));

    // Local Falcon ATRP via GeoGridMetrics: (1+2+..+8 + 21)/9 = (36+21)/9 = 6.33.
    expect($result['aggregates']['ours']['atrp'])->toBe(5.0)
        ->and($result['aggregates']['local_falcon']['atrp'])->toBe(6.33)
        ->and($result['verdict'])->toBe('accept');   // points agree; the aggregate mismatch is the formula note
});

it('parses a Local Falcon CSV with column aliases and not-found tokens', function () {
    $path = sys_get_temp_dir().'/lf_'.uniqid().'.csv';
    file_put_contents($path, "latitude,longitude,ranking\n40.70,-74.00,1\n40.72,-74.00,20+\n40.74,-74.00,\n40.70,-74.02,X\n40.72,-74.02,7\n");

    $points = LocalFalconGrid::fromCsv($path, 20);
    @unlink($path);

    expect($points)->toHaveCount(5)
        ->and($points[0]['rank'])->toBe(1)
        ->and($points[1]['rank'])->toBeNull()   // "20+"
        ->and($points[2]['rank'])->toBeNull()   // blank
        ->and($points[3]['rank'])->toBeNull()   // "X"
        ->and($points[4]['rank'])->toBe(7)
        ->and($points[0]['lat'])->toBe(40.70);
});

it('rejects a CSV missing a required column', function () {
    $path = sys_get_temp_dir().'/lf_bad_'.uniqid().'.csv';
    file_put_contents($path, "lat,rank\n40.70,1\n");

    expect(fn () => LocalFalconGrid::fromCsv($path, 20))->toThrow(RuntimeException::class);
    @unlink($path);
});
