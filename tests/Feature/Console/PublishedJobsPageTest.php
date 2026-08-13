<?php

use App\Enums\JobStatus;
use App\Filament\Console\Pages\PublishedJobs;
use App\Jobs\PublishJob;
use App\Models\Job;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create())); // Operator by default

it('renders the page (compiles the blade)', function () {
    $site = Site::factory()->create();
    Job::factory()->published()->create([
        'site_id' => $site->id, 'post_title' => 'New pump install in Newark', 'client_name_display' => 'Jane H.',
    ]);

    Livewire::test(PublishedJobs::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('New pump install in Newark');
});

it('lists published jobs as cards and separates the not-yet-live pipeline', function () {
    $site = Site::factory()->create();
    $live = Job::factory()->published()->create(['site_id' => $site->id, 'wp_post_id' => 42]);
    $approved = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Approved]);
    $failed = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::PublishFailed, 'last_publish_error' => 'WP down']);
    // Review-stage jobs belong to the review queue, not here.
    Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Review]);

    $page = new PublishedJobs;
    $page->siteId = $site->id;

    $published = $page->getPublishedJobsProperty();
    $pipeline = $page->getPipelineJobsProperty();

    expect($published)->toHaveCount(1)
        ->and($published[0]['id'])->toBe($live->id)
        ->and($published[0]['wp_post_id'])->toBe(42)
        ->and(collect($pipeline)->pluck('id')->all())->toEqualCanonicalizing([$approved->id, $failed->id])
        ->and(collect($pipeline)->firstWhere('id', $failed->id)['error'])->toBe('WP down');
});

it('retries the WordPress push for a stuck pipeline job', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $job = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::PublishFailed]);

    $page = new PublishedJobs;
    $page->siteId = $site->id;
    $page->retryPublish($job->id);

    Queue::assertPushed(PublishJob::class, fn (PublishJob $j): bool => $j->jobId === $job->id);
});

it('does not retry a job that is already live', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $job = Job::factory()->published()->create(['site_id' => $site->id]);

    $page = new PublishedJobs;
    $page->siteId = $site->id;
    $page->retryPublish($job->id);

    Queue::assertNothingPushed();
});
