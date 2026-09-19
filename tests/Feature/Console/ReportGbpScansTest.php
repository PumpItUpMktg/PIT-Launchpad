<?php

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
