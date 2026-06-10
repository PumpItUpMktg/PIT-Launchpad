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
