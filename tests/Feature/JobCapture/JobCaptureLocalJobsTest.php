<?php

use App\Enums\JobStatus;
use App\Local\Proof\JobCaptureLocalJobs;
use App\Local\Proof\LocalJob;
use App\Local\Proof\LocalJobProvider;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\JobTypeAssignment;
use App\Models\Location;
use App\Models\Site;

function publishedJobIn(Site $site, JobCity $city, array $attrs = []): Job
{
    return Job::factory()->published()->create(array_merge([
        'site_id' => $site->id,
        'job_city_id' => $city->id,
    ], $attrs));
}

it('is bound to the DB-backed provider', function () {
    expect(app(LocalJobProvider::class))->toBeInstanceOf(JobCaptureLocalJobs::class);
});

it('surfaces published jobs in a served town, mapped to public-safe cards', function () {
    $site = Site::factory()->create();
    $location = Location::factory()->create([
        'site_id' => $site->id,
        'served_towns' => [['name' => 'Haverford', 'state' => 'PA']],
        'lat' => null, 'lng' => null,   // radius off — isolate the town-membership path
    ]);
    $haverford = JobCity::factory()->create(['name' => 'Haverford', 'state' => 'PA']);

    $job = publishedJobIn($site, $haverford, [
        'post_title' => 'Sump pump replacement in Haverford',
        'meta_description' => 'Replaced a failed sump pump and tested the discharge line.',
        'photos' => [['r2_key' => 'tenants/x/jobs/a.jpg', 'alt' => 'new pump']],
        'primary_photo_index' => 0,
        'performed_at' => now()->subDays(3),
    ]);
    JobTypeAssignment::create(['job_capture_id' => $job->id, 'label' => 'Sump Pump Repair', 'slug' => 'sump-pump-repair']);

    $jobs = app(JobCaptureLocalJobs::class)->for($location->fresh());

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0])->toBeInstanceOf(LocalJob::class)
        ->and($jobs[0]->title)->toBe('Sump pump replacement in Haverford')
        ->and($jobs[0]->description)->toContain('Replaced a failed sump pump')
        ->and($jobs[0]->town)->toBe('Haverford')
        ->and($jobs[0]->service)->toBe('Sump Pump Repair')
        ->and($jobs[0]->photos[0])->toContain('a.jpg')
        ->and($jobs[0]->date)->not->toBeNull();
});

it('excludes non-published jobs and jobs outside the served towns', function () {
    $site = Site::factory()->create();
    $location = Location::factory()->create([
        'site_id' => $site->id,
        'served_towns' => [['name' => 'Haverford', 'state' => 'PA']],
        'lat' => null, 'lng' => null,
    ]);
    $haverford = JobCity::factory()->create(['name' => 'Haverford', 'state' => 'PA']);
    $faraway = JobCity::factory()->create(['name' => 'Faraway', 'state' => 'PA']);

    publishedJobIn($site, $haverford, ['post_title' => 'In town']);
    publishedJobIn($site, $faraway, ['post_title' => 'Out of area']);          // not a served town
    Job::factory()->create([                                        // captured, not published
        'site_id' => $site->id, 'job_city_id' => $haverford->id, 'status' => JobStatus::Captured, 'post_title' => 'Not live yet',
    ]);

    $jobs = app(JobCaptureLocalJobs::class)->for($location->fresh());

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->title)->toBe('In town');
});

it('matches jobs within the coverage radius when town membership does not', function () {
    $site = Site::factory()->create();
    $location = Location::factory()->create([
        'site_id' => $site->id,
        'served_towns' => [],
        'lat' => 40.00, 'lng' => -75.00, 'coverage_radius' => 25,
    ]);
    $near = JobCity::factory()->create(['name' => 'Near']);
    $far = JobCity::factory()->create(['name' => 'Far']);

    publishedJobIn($site, $near, ['post_title' => 'Nearby job', 'lat_jittered' => 40.05, 'lng_jittered' => -75.02]);
    publishedJobIn($site, $far, ['post_title' => 'Distant job', 'lat_jittered' => 42.00, 'lng_jittered' => -78.00]);

    $jobs = app(JobCaptureLocalJobs::class)->for($location->fresh());

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->title)->toBe('Nearby job');
});

it('returns nothing when the site has no published jobs', function () {
    $site = Site::factory()->create();
    $location = Location::factory()->create(['site_id' => $site->id, 'served_towns' => [['name' => 'Haverford', 'state' => 'PA']]]);
    $haverford = JobCity::factory()->create(['name' => 'Haverford', 'state' => 'PA']);
    Job::factory()->create(['site_id' => $site->id, 'job_city_id' => $haverford->id, 'status' => JobStatus::Captured]);

    expect(app(JobCaptureLocalJobs::class)->for($location->fresh()))->toBe([]);
});
