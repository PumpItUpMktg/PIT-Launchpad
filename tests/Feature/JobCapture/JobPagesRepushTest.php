<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\JobCapture\Publishing\JobPageTargets;
use App\JobCapture\Publishing\JobPublisher;
use App\Jobs\PublishContent;
use App\Jobs\RepushJobPages;
use App\Local\Proof\JobCaptureServiceJobs;
use App\Local\Proof\ServiceJobProvider;
use App\Models\Connection;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\JobType;
use App\Models\Location;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function serviceJob(Site $site, string $label, array $attrs = [], ?string $jobTypeId = null): Job
{
    $job = Job::factory()->published()->create(array_merge(['site_id' => $site->id, 'post_title' => "{$label} job", 'meta_description' => 'What we did.', 'photos' => []], $attrs));
    $job->jobTypes()->create(['label' => $label, 'slug' => Str::slug($label), 'job_type_id' => $jobTypeId]);

    return $job;
}

it('is bound: a service page shows the site\'s recent published jobs for THAT service, newest first, matched by the catalog link or the label slug', function () {
    expect(app(ServiceJobProvider::class))->toBeInstanceOf(JobCaptureServiceJobs::class);
    $site = Site::factory()->create();
    $maintenance = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Maintenance']);
    $install = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation']);
    $vocab = JobType::factory()->create(['site_id' => $site->id, 'label' => 'Sump Pump Maintenance', 'slug' => 'sump-pump-maintenance', 'service_id' => $maintenance->id]);
    $city = JobCity::factory()->create(['name' => 'Collegeville', 'state' => 'PA']);

    $linked = serviceJob($site, 'Pump tune-up', ['performed_at' => '2026-03-01', 'job_city_id' => $city->id], $vocab->id);      // linked through the vocabulary
    $bySlug = serviceJob($site, 'Sump Pump Maintenance', ['performed_at' => '2026-05-01']);                                       // typed before the catalog mirrored it
    serviceJob($site, 'Sump Pump Installation', ['performed_at' => '2026-06-01']);                                                // another service
    serviceJob($site, 'Sump Pump Maintenance', ['performed_at' => '2026-07-01', 'status' => JobStatus::Review]);                 // not published
    serviceJob(Site::factory()->create(), 'Sump Pump Maintenance', ['performed_at' => '2026-08-01']);                           // another tenant

    $cards = app(ServiceJobProvider::class)->for($maintenance);
    expect(array_map(fn ($c) => $c->title, $cards))->toBe(['Sump Pump Maintenance job', 'Pump tune-up job'])   // newest first
        ->and($cards[1]->town)->toBe('Collegeville')
        ->and($cards[1]->service)->toBe('Pump tune-up');
    expect(app(ServiceJobProvider::class)->for($install))->toHaveCount(1);
    expect(app(ServiceJobProvider::class)->for(Service::factory()->create(['site_id' => $site->id, 'name' => 'Gutters'])))->toBe([]);
});

it('targets the pages that would show new jobs: the services\' pages, the office hubs and town pages within the neighbour radius — each once', function () {
    $site = Site::factory()->create();
    $maintenance = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Maintenance']);
    $vocab = JobType::factory()->create(['site_id' => $site->id, 'label' => 'Sump Pump Maintenance', 'slug' => 'sump-pump-maintenance', 'service_id' => $maintenance->id]);
    $other = Service::factory()->create(['site_id' => $site->id, 'name' => 'Gutters']);
    $page = fn (array $a) => Content::factory()->create(array_merge(['site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published], $a));
    $maintenancePage = $page(['page_type' => PageType::Service, 'primary_service_id' => $maintenance->id, 'title' => 'Sump Pump Maintenance']);
    $gutterPage = $page(['page_type' => PageType::Service, 'primary_service_id' => $other->id, 'title' => 'Gutters']);
    $draftPage = $page(['page_type' => PageType::Service, 'primary_service_id' => $maintenance->id, 'title' => 'Draft', 'status' => ContentStatus::Candidate]);
    $near = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office', 'lat' => 40.82, 'lng' => -74.21]);
    $far = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper office', 'lat' => 40.15, 'lng' => -75.40]);
    $nearHub = $page(['page_type' => PageType::Location, 'location_id' => $near->id, 'title' => 'Montclair']);
    $farHub = $page(['page_type' => PageType::Location, 'location_id' => $far->id, 'title' => 'Trooper']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Verona', 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3401376070', 'lat' => 40.83, 'lng' => -74.24, 'source_location_ids' => [$near->id]]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Oaks', 'state' => 'PA', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '4209155000', 'lat' => 40.13, 'lng' => -75.45, 'source_location_ids' => [$far->id]]);
    $nearTown = $page(['page_type' => PageType::Location, 'location_id' => null, 'parent_location_id' => $near->id, 'geo_id' => '3401376070', 'title' => 'Verona']);
    $farTown = $page(['page_type' => PageType::Location, 'location_id' => null, 'parent_location_id' => $far->id, 'geo_id' => '4209155000', 'title' => 'Oaks']);

    $job = serviceJob($site, 'Sump Pump Maintenance', ['lat_jittered' => 40.81, 'lng_jittered' => -74.20], $vocab->id);   // near Montclair
    $ids = app(JobPageTargets::class)->for($site->id, Job::withoutGlobalScopes()->whereKey($job->id)->with('jobTypes')->get());

    expect($ids)->toEqualCanonicalizing([$maintenancePage->id, $nearHub->id, $nearTown->id])
        ->and($ids)->not->toContain($gutterPage->id)->not->toContain($draftPage->id)->not->toContain($farHub->id)->not->toContain($farTown->id);
    expect(app(JobPageTargets::class)->for($site->id, Job::withoutGlobalScopes()->where('id', 'none')->get()))->toBe([]);
});

it('a publish queues one debounced re-push per site that covers every job published since the last run, and re-pushes each affected page once', function () {
    Queue::fake();
    Http::fake(['*/launchpad/v1/job' => Http::response(['wp_post_id' => 77, 'status' => 'publish'])]);
    config()->set('services.indexnow.ping_on_publish', false);
    $site = Site::factory()->create();
    Connection::factory()->rotated()->create(['site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword->value, 'credentials' => ['base_url' => 'https://wp.example', 'username' => 'u', 'app_password' => 'pw']]);
    $maintenance = Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Maintenance']);
    $vocab = JobType::factory()->create(['site_id' => $site->id, 'label' => 'Sump Pump Maintenance', 'slug' => 'sump-pump-maintenance', 'service_id' => $vocab_service = $maintenance->id]);
    $servicePage = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published, 'page_type' => PageType::Service, 'primary_service_id' => $maintenance->id, 'title' => 'Sump Pump Maintenance']);

    foreach ([1, 2] as $i) {
        $job = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Approved, 'post_title' => "Job {$i}", 'meta_description' => 'm', 'enhanced_description' => 'Done.', 'lat_jittered' => 40.81, 'lng_jittered' => -74.20, 'photos' => []]);
        $job->jobTypes()->create(['label' => 'Sump Pump Maintenance', 'slug' => 'sump-pump-maintenance', 'job_type_id' => $vocab->id]);
        app(JobPublisher::class)->publish($job);
    }
    // Two publishes → ONE queued run (the unique lock absorbs the second while the first waits), delayed.
    Queue::assertPushed(RepushJobPages::class, 1);
    Queue::assertPushed(RepushJobPages::class, fn (RepushJobPages $j) => $j->siteId === $site->id && $j->delay !== null);

    // The run: both jobs are newer than the marker → the service page is re-pushed once, and the marker moves on.
    Cache::forget(RepushJobPages::markerKey($site->id));
    (new RepushJobPages($site->id))->handle(app(JobPageTargets::class));
    Queue::assertPushed(PublishContent::class, 1);
    Queue::assertPushed(PublishContent::class, fn (PublishContent $j) => $j->contentId === $servicePage->id);
    expect(Cache::get(RepushJobPages::markerKey($site->id)))->not->toBeNull();

    // A second run with nothing new since the marker re-pushes nothing.
    (new RepushJobPages($site->id))->handle(app(JobPageTargets::class));
    Queue::assertPushed(PublishContent::class, 1);

    // Switched off → no re-push is queued at all.
    config()->set('launchpad.publish.job_repush_delay_minutes', 0);
    RepushJobPages::after(Site::factory()->create()->id);
    Queue::assertPushed(RepushJobPages::class, 1);
});
