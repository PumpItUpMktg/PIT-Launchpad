<?php

use App\ContentEngine\CandidateFunnel;
use App\ContentEngine\FunnelResult;
use App\Enums\FeedOrigin;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Support\Facades\Http as HttpFacade;
use Tests\Support\Feeds;

/** Bind a no-op funnel so the command exercises the ingest loop without a live Claude scoring call. */
function fakeFunnel(): void
{
    $funnel = Mockery::mock(CandidateFunnel::class);
    $funnel->shouldReceive('process')->andReturn(new FunnelResult([], [], [], [], []));
    app()->instance(CandidateFunnel::class, $funnel);
}

it('runs the hourly all-tenant ingest and prints the run summary', function () {
    HttpFacade::fake(['*' => HttpFacade::response(Feeds::directXml(), 200, ['Content-Type' => 'application/xml'])]);
    fakeFunnel();

    $site = Site::factory()->create();
    Source::factory()->create(['site_id' => $site->id, 'origin' => FeedOrigin::Client->value, 'url' => 'https://a.example/feed', 'enabled' => true]);

    $this->artisan('launchpad:ingest-feeds')
        ->assertSuccessful()
        ->expectsOutputToContain('1 feeds processed, 0 skipped');
});

it('reports skipped feeds when the budget is exhausted', function () {
    HttpFacade::fake(['*' => HttpFacade::response(Feeds::directXml(), 200, ['Content-Type' => 'application/xml'])]);
    config(['launchpad.feeds.ingest_budget_seconds' => 0.0]); // deadline already past → nothing processed

    $site = Site::factory()->create();
    Source::factory()->count(2)->create(['site_id' => $site->id, 'origin' => FeedOrigin::Client->value, 'enabled' => true])
        ->each(fn ($s) => $s->forceFill(['url' => 'https://'.$s->id.'.example/feed'])->save());

    $this->artisan('launchpad:ingest-feeds')
        ->assertSuccessful()
        ->expectsOutputToContain('0 feeds processed, 2 skipped')
        ->expectsOutputToContain('lead the next tick');
});

it('a single-site run is unbounded (budget none)', function () {
    HttpFacade::fake(['*' => HttpFacade::response(Feeds::directXml(), 200, ['Content-Type' => 'application/xml'])]);
    config(['launchpad.feeds.ingest_budget_seconds' => 0.0]); // ignored for --site
    fakeFunnel();

    $site = Site::factory()->create();
    Source::factory()->create(['site_id' => $site->id, 'origin' => FeedOrigin::Client->value, 'url' => 'https://a.example/feed', 'enabled' => true]);

    // One output write for a no-skip run, so assert both facts in a single substring
    // (expectsOutputToContain matches per output write).
    $this->artisan('launchpad:ingest-feeds', ['--site' => $site->id])
        ->assertSuccessful()
        ->expectsOutputToContain('1 feeds processed, 0 skipped (budget none');
});
