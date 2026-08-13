<?php

use App\JobCapture\Review\JobPhotoAttacher;
use App\Models\Job;
use App\Models\Site;
use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake(TenantStorage::DISK));

it('appends photos to an existing job under the per-job prefix', function () {
    $site = Site::factory()->create();
    $job = Job::factory()->create(['site_id' => $site->id, 'photos' => null]);

    $added = app(JobPhotoAttacher::class)->attach($job, [
        ['bytes' => 'AAA', 'filename' => 'a.jpg'],
        ['bytes' => 'BBB', 'filename' => 'b.jpg'],
    ]);

    $job->refresh();
    expect($added)->toBe(2)
        ->and($job->photos)->toHaveCount(2)
        ->and($job->photos[0]['r2_key'])->toContain($job->id);
    Storage::disk(TenantStorage::DISK)->assertExists($job->photos[1]['r2_key']);
});

it('keeps existing photos and caps the total at the per-job max', function () {
    $site = Site::factory()->create();
    $job = Job::factory()->create([
        'site_id' => $site->id,
        'photos' => [['r2_key' => 'existing/1.jpg', 'hash' => 'h']],
    ]);

    $added = app(JobPhotoAttacher::class)->attach($job, [
        ['bytes' => 'AAA'], ['bytes' => 'BBB'], ['bytes' => 'CCC'], // 3 more, only 2 fit (max 3 total)
    ]);

    expect($added)->toBe(2)
        ->and($job->refresh()->photos)->toHaveCount(Job::MAX_PHOTOS)
        ->and($job->photos[0]['r2_key'])->toBe('existing/1.jpg'); // original kept, at the front
});

it('adds nothing when the job is already full', function () {
    $site = Site::factory()->create();
    $job = Job::factory()->create([
        'site_id' => $site->id,
        'photos' => [['r2_key' => '1.jpg'], ['r2_key' => '2.jpg'], ['r2_key' => '3.jpg']],
    ]);

    expect(app(JobPhotoAttacher::class)->attach($job, [['bytes' => 'AAA']]))->toBe(0)
        ->and($job->refresh()->photos)->toHaveCount(3);
});
