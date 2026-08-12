<?php

use App\Enums\JobSource;
use App\Enums\JobStatus;
use App\Enums\SizeTier;
use App\Models\Content;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\JobCounty;
use App\Models\Site;
use App\PageBuilder\Entities\EntityResolver;
use App\PageBuilder\Validation\ValidationContext;
use App\Support\CurrentSite;

/** The capture record lives in `job_captures`, never the queue's `jobs` table. */
test('the Job model maps to the job_captures table', function () {
    expect((new Job)->getTable())->toBe('job_captures');
});

test('a Job is tenant-scoped and auto-fills site_id from the current site', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    Job::factory()->create(['site_id' => $siteA->id]);
    Job::factory()->create(['site_id' => $siteB->id]);

    CurrentSite::set($siteA->id);
    expect(Job::count())->toBe(1);

    // No site_id passed — the trait fills it from the resolved tenant.
    $created = Job::create(['raw_description' => 'Cleared a flooded basement.']);
    expect($created->site_id)->toBe($siteA->id);

    CurrentSite::clear();

    // Operator / cross-tenant context sees every tenant's rows.
    expect(Job::withoutGlobalScopes()->count())->toBe(3);
});

test('Job casts source, status, coordinates and photos', function () {
    $job = Job::factory()->create([
        'source' => JobSource::Joby,
        'status' => JobStatus::Review,
        'photos' => [['r2_key' => 'sites/x/jobs/y/1.webp', 'hash' => 'abc', 'alt' => 'A sump pump']],
    ])->refresh();

    expect($job->source)->toBe(JobSource::Joby)
        ->and($job->status)->toBe(JobStatus::Review)
        ->and($job->photos)->toBe([['r2_key' => 'sites/x/jobs/y/1.webp', 'hash' => 'abc', 'alt' => 'A sump pump']]);
});

test('applied job types are snapshot rows keyed on the job', function () {
    $job = Job::factory()->create();

    $job->jobTypes()->create(['label' => 'Sump Pump Repair', 'slug' => 'sump-pump-repair']);
    $job->jobTypes()->create(['label' => 'Drain Cleaning', 'slug' => 'drain-cleaning']);

    expect($job->jobTypes()->count())->toBe(2)
        ->and($job->jobTypes->pluck('label')->all())->toContain('Sump Pump Repair', 'Drain Cleaning');
});

test('a city belongs to its county and both carry a size tier', function () {
    $county = JobCounty::factory()->create();
    $city = JobCity::factory()->create(['job_county_id' => $county->id]);

    expect($city->county->is($county))->toBeTrue()
        ->and($city->size_tier)->toBe(SizeTier::Medium)
        ->and($county->size_tier)->toBe(SizeTier::Large);
});

test('EntityResolver jobcapture.radius counts only PUBLISHED jobs for the site', function () {
    $site = Site::factory()->create();
    Job::factory()->published()->count(2)->create(['site_id' => $site->id]);
    Job::factory()->create(['site_id' => $site->id]);                  // captured — not published
    Job::factory()->published()->create();                            // a different tenant — excluded

    $context = new ValidationContext(Content::factory()->create(['site_id' => $site->id]));

    expect(app(EntityResolver::class)->count('jobcapture.radius', $context))->toBe(2);
});
