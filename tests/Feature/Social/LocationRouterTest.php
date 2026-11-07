<?php

use App\Models\CoverageArea;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\Location;
use App\Models\Site;
use App\Social\LocationRouter;

it('routes a job to the one location when the site has one', function () {
    $site = Site::factory()->create();
    $only = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.9, 'lng' => -74.8]);
    $job = Job::factory()->create(['site_id' => $site->id, 'lat_true' => 40.1, 'lng_true' => -74.1]);

    expect(app(LocationRouter::class)->forJob($job)?->id)->toBe($only->id);
});

it('routes by the coverage area that covers the job\'s town, else the location that lists the town, else the nearest', function () {
    $site = Site::factory()->create();
    $north = Location::factory()->create(['site_id' => $site->id, 'name' => 'North', 'lat' => 41.0, 'lng' => -74.8]);
    $south = Location::factory()->create(['site_id' => $site->id, 'name' => 'South', 'lat' => 40.2, 'lng' => -74.8, 'served_towns' => [['name' => 'Freehold', 'state' => 'NJ']]]);
    $router = app(LocationRouter::class);

    // Covered town → the coverage area's source location, even though the job's coordinates sit nearer the other office.
    $city = JobCity::factory()->create(['name' => 'Hackettstown', 'place_geoid' => '3404128590']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3404128590', 'source_location_ids' => [$north->id]]);
    $covered = Job::factory()->create(['site_id' => $site->id, 'job_city_id' => $city->id, 'lat_true' => 40.3, 'lng_true' => -74.8]);
    expect($router->forJob($covered)?->id)->toBe($north->id);

    // No coverage row, but a location lists the town among its served towns.
    $listed = Job::factory()->create(['site_id' => $site->id, 'job_city_id' => JobCity::factory()->create(['name' => 'Freehold'])->id, 'lat_true' => 40.95, 'lng_true' => -74.8]);
    expect($router->forJob($listed)?->id)->toBe($south->id);

    // Nothing known about the town → nearest office by distance.
    $nearest = Job::factory()->create(['site_id' => $site->id, 'job_city_id' => null, 'lat_true' => 40.25, 'lng_true' => -74.8]);
    expect($router->forJob($nearest)?->id)->toBe($south->id);
});

it('returns null when the site has no locations', function () {
    $job = Job::factory()->create();

    expect(app(LocationRouter::class)->forJob($job))->toBeNull();
});
