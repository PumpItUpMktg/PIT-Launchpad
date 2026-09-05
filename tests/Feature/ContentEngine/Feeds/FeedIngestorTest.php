<?php

use App\ContentEngine\CandidateFunnel;
use App\ContentEngine\Feeds\FeedFetcher;
use App\ContentEngine\Feeds\FeedIngestor;
use App\ContentEngine\FunnelResult;
use App\Enums\FeedOrigin;
use App\Models\Silo;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Support\Facades\Http as HttpFacade;
use Tests\Support\Feeds;

it('fetches a feed, records healthy telemetry, and routes items with the silo hint', function () {
    HttpFacade::fake(['*' => HttpFacade::response(Feeds::directXml(), 200, ['Content-Type' => 'application/rss+xml'])]);

    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $feed = Source::factory()->create([
        'site_id' => $site->id,
        'silo_id' => $silo->id,
        'origin' => FeedOrigin::Client->value,
        'url' => 'https://techcrunch.com/feed/',
        'enabled' => true,
    ]);

    $funnel = Mockery::mock(CandidateFunnel::class);
    $funnel->shouldReceive('process')->once()
        ->withArgs(fn ($s, $items, $hint) => $s->id === $site->id && count($items) === 1 && $hint === $silo->id)
        ->andReturn(new FunnelResult([], [], [], [], []));

    (new FeedIngestor(app(FeedFetcher::class), $funnel))->ingestFeed($feed);

    $feed->refresh();
    expect($feed->last_fetched_at)->not->toBeNull()
        ->and($feed->last_item_at)->not->toBeNull()
        ->and($feed->last_error)->toBeNull();
});

it('records the error and skips the funnel when a fetch fails', function () {
    HttpFacade::fake(['*' => HttpFacade::response('boom', 500)]);

    $site = Site::factory()->create();
    $feed = Source::factory()->create([
        'site_id' => $site->id,
        'origin' => FeedOrigin::Client->value,
        'url' => 'https://example.com/feed',
        'enabled' => true,
    ]);

    $funnel = Mockery::mock(CandidateFunnel::class);
    $funnel->shouldReceive('process')->never();

    $report = (new FeedIngestor(app(FeedFetcher::class), $funnel))->ingestFeed($feed);

    expect($report->error)->toContain('HTTP 500')
        ->and($report->fetched)->toBe(0)
        ->and($report->routed)->toBe(0);
    $feed->refresh();
    expect($feed->last_error)->toContain('HTTP 500')
        ->and($feed->last_item_at)->toBeNull()
        ->and($feed->last_fetched_at)->not->toBeNull();
});

it('ingests only active feeds for a site', function () {
    HttpFacade::fake(['*' => HttpFacade::response(Feeds::directXml(), 200, ['Content-Type' => 'application/xml'])]);

    $site = Site::factory()->create();
    Source::factory()->create(['site_id' => $site->id, 'origin' => FeedOrigin::Client->value, 'url' => 'https://a.example/feed', 'enabled' => true]);
    Source::factory()->create(['site_id' => $site->id, 'origin' => FeedOrigin::Client->value, 'url' => 'https://b.example/feed', 'enabled' => false]); // paused
    Source::factory()->create(['site_id' => $site->id, 'origin' => FeedOrigin::Generated->value, 'url' => null, 'enabled' => true]); // no url

    $funnel = Mockery::mock(CandidateFunnel::class);
    $funnel->shouldReceive('process')->once()->andReturn(new FunnelResult([], [], [], [], []));

    $summary = (new FeedIngestor(app(FeedFetcher::class), $funnel))->ingestSite($site);

    expect($summary['feeds'])->toBe(1);
});

it('ingests due feeds stalest-first across tenants (never-fetched, then oldest)', function () {
    HttpFacade::fake(['*' => HttpFacade::response(Feeds::directXml(), 200, ['Content-Type' => 'application/xml'])]);

    // Two tenants, three feeds with different freshness — fairness is global, not per-site.
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    $recent = Source::factory()->create(['site_id' => $siteA->id, 'origin' => FeedOrigin::Client->value, 'url' => 'https://recent.example/feed', 'enabled' => true]);
    $recent->forceFill(['last_fetched_at' => now()->subHour()])->save();
    $old = Source::factory()->create(['site_id' => $siteB->id, 'origin' => FeedOrigin::Client->value, 'url' => 'https://old.example/feed', 'enabled' => true]);
    $old->forceFill(['last_fetched_at' => now()->subDays(5)])->save();
    $never = Source::factory()->create(['site_id' => $siteA->id, 'origin' => FeedOrigin::Client->value, 'url' => 'https://never.example/feed', 'enabled' => true]);
    $never->forceFill(['last_fetched_at' => null])->save();

    $funnel = Mockery::mock(CandidateFunnel::class);
    $funnel->shouldReceive('process')->andReturn(new FunnelResult([], [], [], [], []));

    $result = (new FeedIngestor(app(FeedFetcher::class), $funnel))->ingestDue();

    expect($result['feeds'])->toBe(3)
        ->and($result['skipped'])->toBe(0)
        ->and(array_map(fn ($r) => $r->feedId, $result['reports']))
        ->toBe([$never->id, $old->id, $recent->id]); // never-fetched → oldest → most recent
});

it('stops at the budget deadline and reports the untouched feeds as skipped', function () {
    HttpFacade::fake(['*' => HttpFacade::response(Feeds::directXml(), 200, ['Content-Type' => 'application/xml'])]);

    $site = Site::factory()->create();
    Source::factory()->count(3)->create(['site_id' => $site->id, 'origin' => FeedOrigin::Client->value, 'enabled' => true])
        ->each(fn ($s) => $s->forceFill(['url' => 'https://'.$s->id.'.example/feed'])->save());

    $funnel = Mockery::mock(CandidateFunnel::class);
    $funnel->shouldReceive('process')->never(); // budget spent before any feed runs

    // A zero budget → the deadline is already past on the first iteration: nothing processed, all skipped.
    $result = (new FeedIngestor(app(FeedFetcher::class), $funnel))->ingestDue(budgetSeconds: 0.0);

    expect($result['feeds'])->toBe(0)
        ->and($result['skipped'])->toBe(3);
});

it('stamps per-feed wall-clock on the report', function () {
    HttpFacade::fake(['*' => HttpFacade::response(Feeds::directXml(), 200, ['Content-Type' => 'application/xml'])]);

    $site = Site::factory()->create();
    $feed = Source::factory()->create(['site_id' => $site->id, 'origin' => FeedOrigin::Client->value, 'url' => 'https://t.example/feed', 'enabled' => true]);

    $funnel = Mockery::mock(CandidateFunnel::class);
    $funnel->shouldReceive('process')->andReturn(new FunnelResult([], [], [], [], []));

    $report = (new FeedIngestor(app(FeedFetcher::class), $funnel))->ingestFeed($feed);

    expect($report->durationMs)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($report->toLog())->toHaveKey('duration_ms');
});
