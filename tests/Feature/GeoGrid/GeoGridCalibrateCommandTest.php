<?php

use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Site;
use Illuminate\Support\Str;

it('runs the calibration command against a scan + Local Falcon CSV and prints a verdict', function () {
    $site = Site::factory()->create();
    $scan = GeoGridScan::create([
        'site_id' => $site->id, 'location_id' => (string) Str::ulid(), 'keyword_id' => (string) Str::ulid(),
        'provider' => 'dataforseo', 'grid_size' => 2, 'spacing_miles' => 1.5, 'center_lat' => 40.70, 'center_lng' => -74.00,
        'zoom' => 13, 'depth_cap' => 20, 'atrp' => 3.0, 'arp' => 3.0, 'solv' => 100, 'found_rate' => 100, 'status' => 'complete', 'scanned_at' => now(),
    ]);
    $coords = [[0, 0], [0, 1], [1, 0], [1, 1]];
    foreach ($coords as $i => [$r, $c]) {
        GeoGridPoint::create([
            'site_id' => $site->id, 'scan_id' => $scan->id, 'row' => $r, 'col' => $c,
            'lat' => 40.70 + $r * 0.02, 'lng' => -74.00 + $c * 0.02, 'rank' => 3,
        ]);
    }

    // Coordinates must match the scan grid: col offset is -74.00 + col*0.02 → col1 = -73.98.
    $path = sys_get_temp_dir().'/lf_cmd_'.uniqid().'.csv';
    file_put_contents($path, "lat,lng,rank\n40.70,-74.00,3\n40.70,-73.98,3\n40.72,-74.00,3\n40.72,-73.98,3\n");

    $this->artisan('launchpad:geo-grid-calibrate', ['scan' => $scan->id, '--local' => $path])
        ->expectsOutputToContain('VERDICT: ACCEPT')
        ->assertExitCode(0);

    @unlink($path);
});

it('fails cleanly for an unknown scan id', function () {
    $this->artisan('launchpad:geo-grid-calibrate', ['scan' => (string) Str::ulid(), '--local' => '/tmp/x.csv'])
        ->expectsOutputToContain('No geo-grid scan with that id')
        ->assertExitCode(1);
});
