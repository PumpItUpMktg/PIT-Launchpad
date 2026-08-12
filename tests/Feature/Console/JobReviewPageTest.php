<?php

use App\Enums\JobStatus;
use App\Filament\Console\Pages\JobReview;
use App\Jobs\PublishJob;
use App\Models\Job;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create())); // Operator by default

it('renders the review page (compiles the blade)', function () {
    $site = Site::factory()->create();
    Job::factory()->create([
        'site_id' => $site->id, 'status' => JobStatus::Review, 'enhanced_description' => 'A write-up.',
        'client_name_display' => 'Jane H.', 'photos' => [['r2_key' => 'k1', 'alt' => 'A pump']],
    ]);

    Livewire::test(JobReview::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('Jane H.');
});

it('lists the site\'s review + captured jobs, review first', function () {
    $site = Site::factory()->create();
    $review = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Review, 'enhanced_description' => 'x']);
    Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Captured]);
    Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Published, 'enhanced_description' => 'x']);

    $page = new JobReview;
    $page->siteId = $site->id;
    $jobs = $page->getReviewJobsProperty();

    expect($jobs)->toHaveCount(2)
        ->and($jobs[0]['id'])->toBe($review->id)
        ->and($jobs[0]['has_draft'])->toBeTrue();
});

it('approve enqueues the publish and flips the job', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $job = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Review, 'enhanced_description' => 'A write-up.']);

    $page = new JobReview;
    $page->siteId = $site->id;
    $page->approve($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Approved);
    Queue::assertPushed(PublishJob::class);
});

it('will not approve an un-enhanced job', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $job = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Review, 'enhanced_description' => null]);

    $page = new JobReview;
    $page->siteId = $site->id;
    $page->approve($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Review);
    Queue::assertNotPushed(PublishJob::class);
});

it('saves edits to the source seed, not the raw', function () {
    $site = Site::factory()->create();
    $job = Job::factory()->create([
        'site_id' => $site->id, 'status' => JobStatus::Review, 'enhanced_description' => 'x',
        'raw_description' => 'raw', 'source_description' => 'seed',
    ]);

    $page = new JobReview;
    $page->siteId = $site->id;
    $page->startEdit($job->id);
    $page->editSource = 'edited seed';
    $page->editTitle = 'New Title';
    $page->saveEdits();

    $job->refresh();
    expect($job->source_description)->toBe('edited seed')
        ->and($job->raw_description)->toBe('raw')
        ->and($job->post_title)->toBe('New Title')
        ->and($page->editingId)->toBeNull();
});

it('rejects with a reason', function () {
    $site = Site::factory()->create();
    $job = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Review, 'enhanced_description' => 'x']);

    $page = new JobReview;
    $page->siteId = $site->id;
    $page->startReject($job->id);
    $page->rejectReason = 'Blurry photos';
    $page->confirmReject();

    expect($job->refresh()->status)->toBe(JobStatus::Rejected)
        ->and($job->reject_reason)->toBe('Blurry photos');
});
