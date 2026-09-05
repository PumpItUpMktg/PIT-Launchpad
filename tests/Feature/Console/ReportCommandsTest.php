<?php

use App\Models\Location;
use App\Models\Site;

it('report-county-mismatch and report-town-assignment run read-only and state live-only', function () {
    Site::factory()->create();

    $this->artisan('launchpad:report-county-mismatch')->assertSuccessful()->expectsOutputToContain('LIVE-ONLY');
    $this->artisan('launchpad:report-town-assignment')->assertSuccessful()->expectsOutputToContain('LIVE-ONLY');
});

it('report-duplicate-locations is report-only by default and removes only with --execute', function () {
    $site = Site::factory()->create();
    $real = Location::factory()->create(['site_id' => $site->id, 'name' => 'Roslyn', 'address' => '1 A St', 'county_geoids' => ['42091'], 'is_storefront' => true]);
    $stub = Location::factory()->create(['site_id' => $site->id, 'name' => 'Roslyn', 'address' => '1 A St', 'county_geoids' => [], 'is_storefront' => false]);

    // Default: report-only, nothing removed.
    $this->artisan('launchpad:report-duplicate-locations')->assertSuccessful()->expectsOutputToContain('Report-only');
    expect(Location::find($stub->id))->not->toBeNull();

    // --execute removes the stub, never the real row.
    $this->artisan('launchpad:report-duplicate-locations --execute')->assertSuccessful();
    expect(Location::find($stub->id))->toBeNull()
        ->and(Location::find($real->id))->not->toBeNull();
});
