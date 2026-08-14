<?php

use App\Enums\JobStatus;
use App\JobCapture\Review\JobReviewActions;
use App\JobCapture\Review\JobReviewQueue;
use App\Jobs\EnhanceJob;
use App\Jobs\PublishJob;
use App\Models\Job;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Queue;

test('the queue lists this site\'s review + captured jobs, review first, and counts only the review backlog', function () {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    $review = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Review, 'enhanced_description' => 'x']);
    Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Captured]);
    Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Approved]);   // decided — not queued
    Job::factory()->create(['status' => JobStatus::Review, 'enhanced_description' => 'y']); // another tenant

    $queue = app(JobReviewQueue::class);
    $ids = $queue->jobs()->pluck('id');

    expect($ids)->toHaveCount(2)
        ->and($ids->first())->toBe($review->id)   // review sorts before captured
        ->and($queue->count())->toBe(1);          // review-only backlog

    CurrentSite::clear();
});

test('a job stranded at enhancing (failed/timed-out model call) still appears in the queue', function () {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    // The exact stranded case: enhancement flipped it to Enhancing, then the model call errored.
    $stranded = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Enhancing]);

    $ids = app(JobReviewQueue::class)->jobs()->pluck('id');

    expect($ids)->toContain($stranded->id)   // visible + re-enhanceable, not lost
        ->and(app(JobReviewQueue::class)->count())->toBe(0); // but not counted as review backlog

    CurrentSite::clear();
});

test('approve flips a reviewed, drafted job to approved, clears the reject reason, and enqueues the publish', function () {
    Queue::fake();
    $job = Job::factory()->create([
        'status' => JobStatus::Review, 'enhanced_description' => 'A grounded write-up.', 'reject_reason' => 'old',
    ]);

    expect(app(JobReviewActions::class)->approve($job))->toBeTrue()
        ->and($job->refresh()->status)->toBe(JobStatus::Approved)
        ->and($job->reject_reason)->toBeNull();

    Queue::assertPushed(PublishJob::class, fn (PublishJob $j): bool => $j->jobId === $job->id);
});

test('approve is blocked for an un-enhanced job (no empty post can be approved)', function () {
    $job = Job::factory()->create(['status' => JobStatus::Review, 'enhanced_description' => null]);

    expect(app(JobReviewActions::class)->approve($job))->toBeFalse()
        ->and($job->refresh()->status)->toBe(JobStatus::Review);
});

test('reject records the reason', function () {
    $job = Job::factory()->create(['status' => JobStatus::Review, 'enhanced_description' => 'x']);

    app(JobReviewActions::class)->reject($job, 'Blurry photos — reshoot.');

    expect($job->refresh()->status)->toBe(JobStatus::Rejected)
        ->and($job->reject_reason)->toBe('Blurry photos — reshoot.');
});

test('re-enhance dispatches the enhancement job', function () {
    Queue::fake();
    $job = Job::factory()->create(['status' => JobStatus::Review, 'enhanced_description' => 'x']);

    app(JobReviewActions::class)->reEnhance($job);

    Queue::assertPushed(EnhanceJob::class, fn (EnhanceJob $j): bool => $j->jobId === $job->id);
});

test('saveEdits writes the source seed (not raw), title, primary photo, and alts — no AI call', function () {
    $job = Job::factory()->create([
        'status' => JobStatus::Review,
        'raw_description' => 'raw', 'source_description' => 'seed', 'enhanced_description' => 'enh',
        'photos' => [['r2_key' => 'k1'], ['r2_key' => 'k2']],
    ]);

    app(JobReviewActions::class)->saveEdits($job, [
        'source_description' => 'edited seed',
        'post_title' => 'New Title',
        'primary_photo_index' => 1,
        'alts' => ['front pipe', 'cleared pit'],
    ]);
    $job->refresh();

    expect($job->source_description)->toBe('edited seed')
        ->and($job->raw_description)->toBe('raw')            // immutable — never edited
        ->and($job->post_title)->toBe('New Title')
        ->and($job->primary_photo_index)->toBe(1)
        ->and($job->photos[0]['alt'])->toBe('front pipe')
        ->and($job->photos[1]['alt'])->toBe('cleared pit');
});
