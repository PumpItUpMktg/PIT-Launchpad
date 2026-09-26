<?php

use App\Enums\JobTypeSource;
use App\Enums\ServiceSiloRole;
use App\JobCapture\Types\JobTypeVocabulary;
use App\Models\Job;
use App\Models\JobType;
use App\Models\Service;
use App\Models\Site;

it('mirrors the site service catalog into the job-type vocabulary, idempotently', function () {
    $site = Site::factory()->create();
    $pump = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Replacement', 'silo_role' => ServiceSiloRole::Pillar]);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'French Drain', 'silo_role' => ServiceSiloRole::Supporting]);
    Service::factory()->create(['name' => 'Other Tenant Service']); // another site — never mirrored here

    $vocabulary = app(JobTypeVocabulary::class);

    expect($vocabulary->sync($site->id))->toBe(2)
        ->and($vocabulary->sync($site->id))->toBe(0); // idempotent

    $rows = JobType::withoutGlobalScopes()->where('site_id', $site->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('slug', 'sump-pump-replacement')->service_id)->toBe($pump->id)
        ->and($rows->firstWhere('slug', 'sump-pump-replacement')->source)->toBe(JobTypeSource::Service)
        ->and(array_column($vocabulary->options($site->id), 'label'))->toBe(['French Drain', 'Sump Pump Replacement']);
});

it('adopts a native row with the same slug, follows a rename, and drops a removed service', function () {
    $site = Site::factory()->create();
    $native = JobType::factory()->create(['site_id' => $site->id, 'label' => 'French Drain', 'slug' => 'french-drain', 'source' => JobTypeSource::Native]);
    $hand = JobType::factory()->create(['site_id' => $site->id, 'label' => 'Hand-added', 'slug' => 'hand-added', 'source' => JobTypeSource::Native]);
    $drain = Service::factory()->create(['site_id' => $site->id, 'name' => 'French Drain']);
    $gone = Service::factory()->create(['site_id' => $site->id, 'name' => 'Crawl Space']);

    $vocabulary = app(JobTypeVocabulary::class);
    $vocabulary->sync($site->id);

    expect(JobType::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(3) // native adopted, not duplicated
        ->and($native->refresh()->service_id)->toBe($drain->id)
        ->and($native->source)->toBe(JobTypeSource::Service);

    $drain->update(['name' => 'French Drain Installation']);
    $gone->delete();
    $vocabulary->sync($site->id);

    $labels = JobType::withoutGlobalScopes()->where('site_id', $site->id)->orderBy('label')->pluck('label')->all();
    expect($labels)->toBe(['French Drain Installation', 'Hand-added'])   // renamed row kept, removed service gone, hand-added untouched
        ->and($hand->refresh()->source)->toBe(JobTypeSource::Native);
});

it('resolves labels to vocabulary rows by slug and keeps free-typed ones as bare snapshots', function () {
    $site = Site::factory()->create();
    $pump = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Replacement']);
    $vocabulary = app(JobTypeVocabulary::class);
    $vocabulary->sync($site->id);

    $resolved = $vocabulary->resolve($site->id, [
        'sump pump replacement',                                   // case-insensitive label → linked, canonical label
        ['label' => 'Sump Pump Replacement', 'slug' => 'sump-pump-replacement'], // duplicate by slug → dropped
        ['label' => 'Gutter Cleanup'],                             // free-typed → bare snapshot
        'One', 'Two',                                              // beyond the cap of 3
    ]);

    expect($resolved)->toHaveCount(3)
        ->and($resolved[0])->toBe(['label' => 'Sump Pump Replacement', 'slug' => 'sump-pump-replacement', 'job_type_id' => JobType::withoutGlobalScopes()->where('service_id', $pump->id)->value('id')])
        ->and($resolved[1])->toBe(['label' => 'Gutter Cleanup', 'slug' => 'gutter-cleanup', 'job_type_id' => null])
        ->and($resolved[2]['label'])->toBe('One');
});

it('replaces a job\'s applied services on apply', function () {
    $site = Site::factory()->create();
    Service::factory()->create(['site_id' => $site->id, 'name' => 'French Drain']);
    $job = Job::factory()->create(['site_id' => $site->id]);
    $job->jobTypes()->create(['label' => 'Old Type', 'slug' => 'old-type']);

    app(JobTypeVocabulary::class)->apply($job, ['French Drain', 'Gutter Cleanup']);

    expect($job->jobTypes()->orderBy('label')->pluck('label')->all())->toBe(['French Drain', 'Gutter Cleanup'])
        ->and($job->jobTypes()->where('slug', 'french-drain')->value('job_type_id'))->not->toBeNull();
});

it('syncs every site (or one) from the console', function () {
    $a = Site::factory()->create(['brand_name' => 'Alpha Plumbing']);
    $b = Site::factory()->create(['brand_name' => 'Beta Drains']);
    Service::factory()->create(['site_id' => $a->id, 'name' => 'Sump Pump']);
    Service::factory()->create(['site_id' => $b->id, 'name' => 'French Drain']);

    $this->artisan('launchpad:sync-job-types', ['--site' => 'Beta Drains'])
        ->expectsOutputToContain('Beta Drains')
        ->assertSuccessful();
    expect(JobType::withoutGlobalScopes()->where('site_id', $b->id)->count())->toBe(1)
        ->and(JobType::withoutGlobalScopes()->where('site_id', $a->id)->count())->toBe(0);

    $this->artisan('launchpad:sync-job-types')->assertSuccessful();
    expect(JobType::withoutGlobalScopes()->where('site_id', $a->id)->count())->toBe(1);
});
