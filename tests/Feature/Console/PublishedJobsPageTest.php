<?php

use App\Enums\JobStatus;
use App\Filament\Console\Pages\PublishedJobs;
use App\Integrations\SearchConsole\SitemapSubmitter;
use App\Jobs\PublishJob;
use App\Jobs\SubmitSitemap;
use App\Jobs\UnpublishJob;
use App\Models\Job;
use App\Models\JobCounty;
use App\Models\Location;
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

it('submits the sitemap OFF the request path: preflight is HTTP-free, the submit is queued', function () {
    Queue::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);

    // The synchronous preflight (connected + sitemapUrl) is HTTP-free; the outbound submit itself never
    // runs in the request — it is dispatched to the queue.
    $submitter = Mockery::mock(SitemapSubmitter::class);
    $submitter->shouldReceive('connected')->andReturnTrue();
    $submitter->shouldReceive('sitemapUrl')->andReturn('https://spg.example/sitemap.xml');
    $submitter->shouldNotReceive('submit'); // never called inline
    app()->instance(SitemapSubmitter::class, $submitter);

    $page = new PublishedJobs;
    $page->siteId = $site->id;
    $page->submitSitemap();

    Queue::assertPushed(SubmitSitemap::class, fn (SubmitSitemap $j): bool => $j->siteId === (string) $site->id);
});

it('does not queue a sitemap submit when Search Console is not connected — warns instead', function () {
    Queue::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);

    $submitter = Mockery::mock(SitemapSubmitter::class);
    $submitter->shouldReceive('connected')->andReturnFalse();
    $submitter->shouldNotReceive('submit');
    app()->instance(SitemapSubmitter::class, $submitter);

    $page = new PublishedJobs;
    $page->siteId = $site->id;
    $page->submitSitemap();

    Queue::assertNotPushed(SubmitSitemap::class);
});

it('attaches live tracking (index/gsc/traffic) to published cards, but not to pipeline cards', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    Job::factory()->published()->create(['site_id' => $site->id]);
    Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Approved]); // pipeline (not live)

    $page = new PublishedJobs;
    $page->siteId = $site->id;

    $published = $page->getPublishedJobsProperty();
    $pipeline = $page->getPipelineJobsProperty();

    // Not connected in tests → honest pending blocks (never a fabricated zero), but the block is present.
    expect($published[0]['metrics'])->not->toBeNull()
        ->and($published[0]['metrics']['index']['pending'])->not->toBeNull()
        ->and($published[0]['metrics'])->toHaveKeys(['gsc', 'index', 'traffic'])
        ->and($pipeline[0]['metrics'])->toBeNull(); // pipeline jobs aren't live → no tracking computed
});

it('queues a metrics warm pass when rendering the live cards (so the render itself fetches nothing)', function () {
    Queue::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    Job::factory()->published()->create(['site_id' => $site->id]);

    $page = new PublishedJobs;
    $page->siteId = $site->id;
    $page->getPublishedJobsProperty();

    Queue::assertPushed(App\Jobs\WarmLiveMetrics::class, fn ($j): bool => $j->siteId === $site->id);
});

it('does not submit the sitemap when no site is selected', function () {
    $submitter = Mockery::mock(SitemapSubmitter::class);
    $submitter->shouldNotReceive('submit');
    app()->instance(SitemapSubmitter::class, $submitter);

    $page = new PublishedJobs;
    $page->siteId = null;
    $page->submitSitemap();
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

it('re-pushes a live job (idempotent update to the same post)', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $job = Job::factory()->published()->create(['site_id' => $site->id]);

    $page = new PublishedJobs;
    $page->siteId = $site->id;
    $page->retryPublish($job->id);

    Queue::assertPushed(PublishJob::class, fn (PublishJob $j): bool => $j->jobId === $job->id);
});

it('sends a published job back to the review queue to edit it, keeping its WP post', function () {
    $site = Site::factory()->create();
    $job = Job::factory()->published()->create(['site_id' => $site->id, 'wp_post_id' => 7]);

    $page = new PublishedJobs;
    $page->siteId = $site->id;
    $page->editInReview($job->id);

    expect($job->fresh()->status)->toBe(JobStatus::Review)
        ->and($job->fresh()->wp_post_id)->toBe(7); // retained so re-approve republishes the same post
});

it('takes a live job down: pulls the WP post and parks it as approved', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $job = Job::factory()->published()->create(['site_id' => $site->id, 'wp_post_id' => 7]);

    $page = new PublishedJobs;
    $page->siteId = $site->id;
    $page->takeDown($job->id);

    Queue::assertPushed(UnpublishJob::class, fn (UnpublishJob $j): bool => $j->jobId === $job->id);
    expect($job->fresh()->status)->toBe(JobStatus::Approved);
});

it('shows the service type and the storefront the job references as pills', function () {
    $site = Site::factory()->create();
    $county = JobCounty::factory()->create(['county_geoid' => '34001']);
    Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Ocean City Shop', 'is_storefront' => true, 'county_geoids' => ['34001'],
    ]);
    $job = Job::factory()->published()->create(['site_id' => $site->id, 'job_county_id' => $county->id]);
    $job->jobTypes()->create(['label' => 'Sump Pump', 'slug' => 'sump-pump']);

    $page = new PublishedJobs;
    $page->siteId = $site->id;
    $card = $page->getPublishedJobsProperty()[0];

    expect($card['job_types'])->toContain('Sump Pump')
        ->and($card['storefront'])->toBe('Ocean City Shop');
});
