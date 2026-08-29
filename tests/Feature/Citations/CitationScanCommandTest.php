<?php

use App\Jobs\RunCitationScan;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
});

test('scanning one location queues a scan for it', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id]);

    $this->artisan('launchpad:citation-scan', ['--location' => $location->id])->assertSuccessful();

    Queue::assertPushed(RunCitationScan::class, fn (RunCitationScan $job): bool => $job->locationId === $location->id);
});

test('scanning a site queues only its NAP-profiled locations', function (): void {
    $profiled = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $profiled->id]);
    Location::factory()->for($this->site)->create(); // no NAP profile → skipped

    $this->artisan('launchpad:citation-scan', ['--site' => $this->site->id])->assertSuccessful();

    Queue::assertPushed(RunCitationScan::class, 1);
});

test('the command errors without a target selector', function (): void {
    $this->artisan('launchpad:citation-scan')->assertFailed();

    Queue::assertNothingPushed();
});
