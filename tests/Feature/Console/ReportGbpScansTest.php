<?php

use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;

function gbpScanSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown office']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'battery backup sump pump']);

    return ['site' => $site, 'location' => $loc, 'keyword' => $kw];
}

function gbpScan(array $f, string $status, array $points): GeoGridScan
{
    $scan = GeoGridScan::create([
        'site_id' => $f['site']->id, 'location_id' => $f['location']->id, 'keyword_id' => $f['keyword']->id,
        'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 1, 'spacing_miles' => 0,
        'center_lat' => 40.31, 'center_lng' => -75.13, 'zoom' => 13, 'depth_cap' => 20,
        'status' => $status, 'scanned_at' => now(),
    ]);
    foreach ($points as $i => $p) {
        GeoGridPoint::create([
            'site_id' => $f['site']->id, 'scan_id' => $scan->id, 'row' => 0, 'col' => $i,
            'lat' => 40.31, 'lng' => -75.13, 'label' => 'Town '.$i,
            'rank' => $p['rank'] ?? null,
            'provider_task_id' => $p['task'] ?? null,
            'collected_at' => ($p['collected'] ?? false) ? now() : null,
        ]);
    }

    return $scan;
}

it('reports nothing to find when no coverage scan exists', function () {
    gbpScanSite();

    $this->artisan('launchpad:report-gbp-scans', ['--site' => 'SPG'])
        ->expectsOutputToContain('No coverage scans match')
        ->assertSuccessful();
});

/**
 * The distinction the card cannot show: a scan POSTED to the provider whose points were never read back
 * is the collector not running, and re-running the report only spends money again. Named outright.
 */
it('names a scan that was posted but never collected', function () {
    $f = gbpScanSite();
    gbpScan($f, 'pending', [['task' => 'abc'], ['task' => 'def']]);

    $this->artisan('launchpad:report-gbp-scans', ['--site' => 'SPG'])
        ->expectsOutputToContain('POSTED BUT NEVER COLLECTED')
        ->expectsOutputToContain('2 point(s) · 0 collected')
        ->assertSuccessful();
});

it('reports a healthy scan without calling it stalled', function () {
    $f = gbpScanSite();
    gbpScan($f, 'complete', [
        ['task' => 'abc', 'collected' => true, 'rank' => 2],
        ['task' => 'def', 'collected' => true, 'rank' => null],
    ]);

    $this->artisan('launchpad:report-gbp-scans', ['--site' => 'SPG'])
        ->expectsOutputToContain('2 point(s) · 2 collected · 1 ranked')
        ->doesntExpectOutputToContain('POSTED BUT NEVER COLLECTED')
        ->assertSuccessful();
});

/** A scan row with no provider task on any town is a different fault, and gets a different name. */
it('separates a scan that never posted anything from one that never collected', function () {
    $f = gbpScanSite();
    gbpScan($f, 'pending', [['task' => null], ['task' => null]]);

    $this->artisan('launchpad:report-gbp-scans', ['--site' => 'SPG'])
        ->expectsOutputToContain('NOTHING POSTED')
        ->doesntExpectOutputToContain('POSTED BUT NEVER COLLECTED')
        ->assertSuccessful();
});

it('filters to only the stalled scans on demand', function () {
    $f = gbpScanSite();
    gbpScan($f, 'complete', [['task' => 'ok', 'collected' => true, 'rank' => 1]]);

    $this->artisan('launchpad:report-gbp-scans', ['--site' => 'SPG', '--stalled' => true])
        ->expectsOutputToContain('No coverage scans match')
        ->assertSuccessful();
});

/**
 * Ranks are the evidence that a scan was read, not the collection stamp alone. Real scans exist with
 * ranks recorded and no `collected_at` — an older write path, or a run interrupted between writing the
 * rank and stamping the row. Calling those "never collected" sends an operator to re-run a report whose
 * answers are already sitting in the table, and to pay for it twice.
 */
it('does not call a scan stalled when it has ranks but no collection stamp', function () {
    $f = gbpScanSite();
    gbpScan($f, 'complete', [['task' => 'abc', 'rank' => 3], ['task' => 'def', 'rank' => 7]]);

    $this->artisan('launchpad:report-gbp-scans', ['--site' => 'SPG'])
        ->expectsOutputToContain('ranks recorded without a collection stamp')
        ->doesntExpectOutputToContain('POSTED BUT NEVER COLLECTED')
        ->assertSuccessful();
});

it('leaves a scan with ranks out of the stalled filter', function () {
    $f = gbpScanSite();
    gbpScan($f, 'complete', [['task' => 'abc', 'rank' => 3]]);

    $this->artisan('launchpad:report-gbp-scans', ['--site' => 'SPG', '--stalled' => true])
        ->expectsOutputToContain('No coverage scans match')
        ->assertSuccessful();
});

/** The brand repeats on every location name; the city is the only part that identifies the row. */
it('labels each scan by its location city rather than the brand-prefixed name', function () {
    $f = gbpScanSite();
    $f['location']->forceFill(['name' => 'Sump Pump Gurus | Downingtown', 'address_components' => [
        ['types' => ['locality'], 'long_name' => 'Downingtown', 'short_name' => 'Downingtown'],
        ['types' => ['administrative_area_level_1'], 'long_name' => 'Pennsylvania', 'short_name' => 'PA'],
    ]])->save();
    gbpScan($f, 'complete', [['task' => 'abc', 'collected' => true, 'rank' => 1]]);

    $this->artisan('launchpad:report-gbp-scans', ['--site' => 'SPG'])
        ->expectsOutputToContain('Downingtown, PA')
        ->assertSuccessful();
});

/**
 * The silent failure the grid cannot show. A scan can be complete, collected and full of ranks, and
 * still render an empty map: TownPointLinks joins each point to a CURRENT coverage area, and
 * CoverageWriter deletes and re-inserts every computed row on each rebuild. A point whose town no
 * longer resolves is dropped — so the operator sees grey over data that is perfectly intact, and the
 * obvious response (re-run the report) spends money to change nothing.
 */
it('names a complete scan whose points no longer land on the current map', function () {
    $f = gbpScanSite();
    // Collected, ranked — and measuring a town this site no longer covers.
    gbpScan($f, 'complete', [['task' => 'abc', 'collected' => true, 'rank' => 2]]);

    $this->artisan('launchpad:report-gbp-scans', ['--site' => 'SPG'])
        ->expectsOutputToContain('0 on the current map')
        ->expectsOutputToContain('NOT ON THE MAP')
        ->doesntExpectOutputToContain('POSTED BUT NEVER COLLECTED')
        ->assertSuccessful();
});

/** A point that still resolves by GEOID is reported as on the map, and raises no warning. */
it('counts points that still resolve to a covered town', function () {
    $f = gbpScanSite();
    CoverageArea::factory()->create([
        'site_id' => $f['site']->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'geo_id' => '3404128590',
        'population' => 9000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => [$f['location']->id],
    ]);
    $scan = gbpScan($f, 'complete', [['task' => 'abc', 'collected' => true, 'rank' => 2]]);
    $scan->points()->update(['geo_id' => '3404128590']);

    $this->artisan('launchpad:report-gbp-scans', ['--site' => 'SPG'])
        ->expectsOutputToContain('1 on the current map')
        ->doesntExpectOutputToContain('NOT ON THE MAP')
        ->assertSuccessful();
});
