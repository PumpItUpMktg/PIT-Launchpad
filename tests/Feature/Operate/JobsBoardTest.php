<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Filament\Pages\JobsBoard;
use App\Jobs\EnhanceJob;
use App\Jobs\PublishJob;
use App\Jobs\UnpublishJob;
use App\Models\Job;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Jobs\JobPortfolio;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

function reviewJob(Site $site): Job
{
    // A reviewed job carrying a write-up (hasDraft) — approvable.
    return Job::factory()->create([
        'site_id' => $site->id,
        'status' => JobStatus::Review,
        'enhanced_description' => 'A thorough replacement of a failed 50-gallon water heater.',
        'post_title' => 'Water heater replacement',
    ]);
}

it('is operator-only', function () {
    expect(JobsBoard::canAccess())->toBeTrue(); // operator (beforeEach)

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(JobsBoard::canAccess())->toBeFalse();
});

it('summarizes and scopes jobs to the locked tenant', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();

    Job::factory()->count(3)->create(['site_id' => $a->id, 'status' => JobStatus::Review, 'enhanced_description' => 'x']);
    Job::factory()->count(2)->create(['site_id' => $a->id, 'status' => JobStatus::Published, 'wp_post_id' => 5]);
    Job::factory()->create(['site_id' => $a->id, 'status' => JobStatus::PublishFailed]);
    // Another tenant's jobs must not leak in.
    Job::factory()->count(4)->create(['site_id' => $b->id, 'status' => JobStatus::Review]);

    $board = app(JobPortfolio::class)->for($a->id);

    expect($board['summary']['review_backlog'])->toBe(3)
        ->and($board['summary']['published'])->toBe(2)
        ->and($board['summary']['failed'])->toBe(1)
        ->and($board['queue'])->toHaveCount(3)      // Review + Captured + Enhancing (here: 3 Review)
        ->and($board['published'])->toHaveCount(2);
});

it('orders the queue Review-first', function () {
    $site = Site::factory()->create();
    Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Captured]);
    $review = reviewJob($site);

    $queue = app(JobPortfolio::class)->for($site->id)['queue'];

    expect($queue[0]['id'])->toBe($review->id)   // Review jumps ahead of Captured
        ->and($queue[0]['has_draft'])->toBeTrue();
});

it('approves a reviewed job into the publish pipeline', function () {
    Bus::fake();
    $site = Site::factory()->create();
    $job = reviewJob($site);
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(JobsBoard::class)->call('approve', $job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Approved);
    Bus::assertDispatched(PublishJob::class);
});

it('does not approve a job with no write-up', function () {
    Bus::fake();
    $site = Site::factory()->create();
    $job = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Review, 'enhanced_description' => null]);
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(JobsBoard::class)->call('approve', $job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Review); // unchanged
    Bus::assertNotDispatched(PublishJob::class);
});

it('rejects, re-enhances, retries and takes down through the board', function () {
    Bus::fake();
    $site = Site::factory()->create();
    app(ActiveTenant::class)->set($site->id);

    $review = reviewJob($site);
    Livewire::test(JobsBoard::class)
        ->call('startReject', $review->id)
        ->set('rejectReason', 'Blurry photos')
        ->call('confirmReject');
    expect($review->refresh()->status)->toBe(JobStatus::Rejected)
        ->and($review->reject_reason)->toBe('Blurry photos');

    $enh = reviewJob($site);
    Livewire::test(JobsBoard::class)->call('reEnhance', $enh->id);
    Bus::assertDispatched(EnhanceJob::class);

    $failed = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::PublishFailed]);
    Livewire::test(JobsBoard::class)->call('retryPublish', $failed->id);
    Bus::assertDispatched(PublishJob::class);

    $live = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Published, 'wp_post_id' => 12]);
    Livewire::test(JobsBoard::class)->call('takeDown', $live->id);
    expect($live->refresh()->status)->toBe(JobStatus::Approved);
    Bus::assertDispatched(UnpublishJob::class);
});

it('never acts on a job outside the locked tenant', function () {
    Bus::fake();
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $foreign = reviewJob($b);
    app(ActiveTenant::class)->set($a->id);

    Livewire::test(JobsBoard::class)->call('approve', $foreign->id);

    expect($foreign->refresh()->status)->toBe(JobStatus::Review); // untouched
    Bus::assertNotDispatched(PublishJob::class);
});

it('renders tenant-locked with jobs and no per-page site picker', function () {
    $site = Site::factory()->create();
    Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Review, 'enhanced_description' => 'x', 'post_title' => 'Sump pump swap in Trenton']);
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(JobsBoard::class)->assertOk()->html();

    expect($html)->toContain('Sump pump swap in Trenton')
        ->and($html)->toContain('Review queue')
        ->and($html)->not->toContain('<select'); // tenant comes from the lock
});
