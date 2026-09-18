<?php

use App\Enums\JobStatus;
use App\Models\CoverageArea;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\Location;
use App\Models\Site;

it('measures how many page towns can actually carry job evidence', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.3, 'lng' => -75.1]);

    $covered = collect(['4201781048' => 'Warrington', '4201754656' => 'Newtown', '4201709992' => 'Buckingham'])
        ->map(fn (string $name, string $geoId): CoverageArea => CoverageArea::factory()->create([
            'site_id' => $site->id, 'name' => $name, 'state' => 'PA', 'geo_id' => $geoId,
            'population' => 20000, 'page_selected' => true, 'source_location_ids' => [$loc->id],
        ]));

    $warrington = JobCity::factory()->create(['place_geoid' => '4201781048', 'name' => 'Warrington', 'state' => 'PA']);
    $elsewhere = JobCity::factory()->create(['place_geoid' => '3401369270', 'name' => 'South Orange', 'state' => 'NJ']);

    Job::factory()->count(2)->create(['site_id' => $site->id, 'job_city_id' => $warrington->id, 'status' => JobStatus::Published]);
    Job::factory()->create(['site_id' => $site->id, 'job_city_id' => $warrington->id, 'status' => JobStatus::Review]);
    Job::factory()->create(['site_id' => $site->id, 'job_city_id' => $elsewhere->id, 'status' => JobStatus::Published]);

    $this->artisan('launchpad:report-town-jobs', ['site' => 'SPG'])
        // Four jobs carry a city across two towns; only Warrington is a covered page town with one published.
        ->expectsOutputToContain('4 job(s) carry a city, across 2 distinct town(s)')
        ->expectsOutputToContain('1 of 3 page-selected towns (33%)')
        // A third of the towns is a minority, and the verdict says so rather than leaving it to be read
        // off the number.
        ->expectsOutputToContain('worth showing only where it exists, never as a fixed slot')
        // A job captured outside the coverage area is a gap or a bad GEOID — named, not swallowed.
        ->expectsOutputToContain('South Orange')
        ->assertExitCode(0);

    expect($covered)->toHaveCount(3);
});
