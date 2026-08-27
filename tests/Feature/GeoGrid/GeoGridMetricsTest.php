<?php

use App\GeoGrid\GeoGridMetrics;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A scan with the given ranks (null = not found), depth_cap 20. */
function scanWithRanks(Site $site, array $ranks): GeoGridScan
{
    $scan = GeoGridScan::create([
        'site_id' => $site->id, 'location_id' => (string) Str::ulid(),
        'keyword_id' => (string) Str::ulid(), 'provider' => 'dataforseo',
        'grid_size' => 3, 'spacing_miles' => 1.5, 'center_lat' => 40.7, 'center_lng' => -74.0,
        'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now(),
    ]);
    foreach ($ranks as $i => $rank) {
        GeoGridPoint::create([
            'site_id' => $site->id, 'scan_id' => $scan->id,
            'row' => intdiv($i, 3), 'col' => $i % 3, 'lat' => 40.7, 'lng' => -74.0, 'rank' => $rank,
        ]);
    }

    return $scan;
}

it('computes found_rate, ARP, ATRP (non-found = depth+1) and SoLV', function () {
    // ranks 1, 2, 5, not-found → total 4, found 3.
    $m = app(GeoGridMetrics::class)->compute(collect([
        (object) ['rank' => 1], (object) ['rank' => 2], (object) ['rank' => 5], (object) ['rank' => null],
    ]), 20);

    expect($m['found_rate'])->toBe(75.0)                 // 3/4
        ->and($m['arp'])->toBe(2.67)                     // (1+2+5)/3
        ->and($m['atrp'])->toBe(7.25)                    // (1+2+5+21)/4  — non-found counted as 21
        ->and($m['solv'])->toBe(50.0);                   // ranks 1,2 in top 3 → 2/4
});

it('recomputes a scan from stored points and trends ATRP into metric_snapshots', function () {
    $site = Site::factory()->create();
    $scan = scanWithRanks($site, [1, 2, 5, null, null, 3, 4, null, 1]);   // 6 found of 9

    app(GeoGridMetrics::class)->recompute($scan);

    $scan->refresh();
    expect((float) $scan->found_rate)->toBe(66.67)       // 6/9
        ->and($scan->atrp)->not->toBeNull()
        ->and($scan->solv)->not->toBeNull();

    $snap = DB::table('metric_snapshots')
        ->where('site_id', $site->id)->where('metric_key', 'geo_grid_atrp')
        ->where('dimension_type', 'location')->first();
    expect($snap)->not->toBeNull()
        ->and($snap->dimension_value)->toBe($scan->location_id.':'.$scan->keyword_id)
        ->and($snap->period_grain)->toBe('month')
        ->and((float) $snap->value_numeric)->toBe((float) $scan->atrp);
});

it('recompute command derives every scan for a site', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    scanWithRanks($site, [1, 1, 1]);
    scanWithRanks($site, [null, null, null]);   // never found → found_rate 0

    $this->artisan('launchpad:geo-grid-recompute', ['site' => $site->id])
        ->expectsOutputToContain('Recomputed 2 scan(s)')
        ->assertExitCode(0);

    expect(GeoGridScan::where('site_id', $site->id)->whereNotNull('atrp')->count())->toBe(2);
});
