<?php

use App\Enums\ContentStatus;
use App\Enums\SiteStatus;
use App\Guided\LiveMetrics;
use App\Integrations\Analytics\PageTrafficProvider;
use App\Jobs\SyncSiteMetrics;
use App\Jobs\WarmGa4Pages;
use App\Metrics\Providers\Ga4MetricProvider;
use App\Models\Content;
use App\Models\Job;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

/*
 * PR 1 acceptance — GA4 is off every render path and runs only on cron:
 *   (1) no GA4 call executes in any render path, on any surface;
 *   (2) GA4 runs on cron — daily site-level (sandhog:sync-ga4), weekly per-page (launchpad:warm-ga4-pages
 *       → WarmGa4Pages).
 */

it('the Live-boards render reads GA4 sessions from cache only — never a live GA4 call (acceptance 1)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $page = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'foo']);

    $traffic = Mockery::mock(PageTrafficProvider::class);
    $traffic->shouldReceive('connected')->andReturnTrue();
    $traffic->shouldReceive('sessionsCached')->once()->andReturn(9); // render reads the warmed cache
    $traffic->shouldNotReceive('sessions');                          // NEVER a live GA4 call on render
    app()->instance(PageTrafficProvider::class, $traffic);

    $m = app(LiveMetrics::class)->for($page->fresh(), liveTraffic: false);

    expect($m['traffic']['sessions'])->toBe(9);
});

it('a non-render caller (default liveTraffic) still fetches GA4 live — so the warm/cron path works', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $page = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'foo']);

    $traffic = Mockery::mock(PageTrafficProvider::class);
    $traffic->shouldReceive('connected')->andReturnTrue();
    $traffic->shouldReceive('sessions')->once()->andReturn(5);
    $traffic->shouldNotReceive('sessionsCached');
    app()->instance(PageTrafficProvider::class, $traffic);

    $m = app(LiveMetrics::class)->for($page->fresh());

    expect($m['traffic']['sessions'])->toBe(5);
});

it('sandhog:sync-ga4 queues a site-level GA4 metric sync per site (acceptance 2 — daily site-level)', function () {
    Queue::fake();
    $site = Site::factory()->create();

    $this->artisan('sandhog:sync-ga4', ['site' => $site->id])->assertSuccessful();

    Queue::assertPushed(SyncSiteMetrics::class, fn (SyncSiteMetrics $j): bool => $j->siteId === (string) $site->id
        && $j->provider === Ga4MetricProvider::PROVIDER
        && $j->rangeStart < $j->rangeEnd); // a trailing window, not a single day
});

it('launchpad:warm-ga4-pages queues one warm job per engine-eligible site (acceptance 2 — weekly per-page)', function () {
    Queue::fake();
    $active = Site::factory()->create(['status' => SiteStatus::Active]);
    Site::factory()->create(['status' => SiteStatus::Onboarding]); // pre-launch → not eligible
    Site::factory()->create(['status' => SiteStatus::Suspended]);  // suspended → not eligible

    $this->artisan('launchpad:warm-ga4-pages')->assertSuccessful();

    Queue::assertPushed(WarmGa4Pages::class, 1);
    Queue::assertPushed(WarmGa4Pages::class, fn (WarmGa4Pages $j): bool => $j->siteId === (string) $active->id);
});

it('WarmGa4Pages force-refreshes GA4 for every published page and job, keyed on the public path', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $page = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'foo']);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Drafted, 'slug' => 'bar']); // not live → skip
    $job = Job::factory()->published()->create(['site_id' => $site->id])->fresh();

    $traffic = Mockery::mock(PageTrafficProvider::class);
    $traffic->shouldReceive('connected')->andReturnTrue();
    $traffic->shouldReceive('refresh')->once()->with(Mockery::any(), '/'.$page->slug);
    $traffic->shouldReceive('refresh')->once()->with(Mockery::any(), $job->publicPath());

    (new WarmGa4Pages((string) $site->id))->handle($traffic);
});

it('WarmGa4Pages is a no-op when GA4 is not connected — no per-page fetch', function () {
    $site = Site::factory()->create();
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'foo']);

    $traffic = Mockery::mock(PageTrafficProvider::class);
    $traffic->shouldReceive('connected')->andReturnFalse();
    $traffic->shouldNotReceive('refresh');

    (new WarmGa4Pages((string) $site->id))->handle($traffic);
});
