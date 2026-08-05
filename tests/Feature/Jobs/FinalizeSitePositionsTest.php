<?php

use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\LocalGrid\MockLocalGridProvider;
use App\Integrations\Serp\MockSerpProvider;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\Jobs\FinalizeSitePositions;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

function finalizeSite(): Site
{
    $site = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com']);
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'money kw', 'status' => 'scored']);

    return $site;
}

it('reads the ingested cache into a snapshot — the back half that closes the loop', function () {
    Queue::fake(); // isolate the self-rearm; we assert the write here

    $serp = new MockSerpProvider;
    $serp->setResults('money kw', new SerpResultSet('money kw', [new SerpResult(4, 'https://acme.com/x', 'acme.com')]));
    app()->instance(SerpProvider::class, $serp);
    app()->instance(LocalGridProvider::class, new MockLocalGridProvider);

    $site = finalizeSite();

    (new FinalizeSitePositions($site->id))->handle(app(SitePipelineRefresher::class));

    $snap = PositionSnapshot::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->sole();
    expect($snap->rank)->toBe(4);
});

it('is idempotent across retries — a keyword captured this cycle is not re-snapshotted', function () {
    Queue::fake();

    $serp = new MockSerpProvider;
    $serp->setResults('money kw', new SerpResultSet('money kw', [new SerpResult(4, 'https://acme.com/x', 'acme.com')]));
    app()->instance(SerpProvider::class, $serp);
    app()->instance(LocalGridProvider::class, new MockLocalGridProvider);

    $site = finalizeSite();
    $refresher = app(SitePipelineRefresher::class);

    (new FinalizeSitePositions($site->id, 1))->handle($refresher);
    (new FinalizeSitePositions($site->id, 2))->handle($refresher); // a later pass in the same cycle

    // Still one snapshot — the recent-capture guard stopped the retry stacking a duplicate.
    expect(PositionSnapshot::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});

it('re-arms itself until the attempt cap, then stops', function () {
    Queue::fake();
    app()->instance(SerpProvider::class, new MockSerpProvider);
    app()->instance(LocalGridProvider::class, new MockLocalGridProvider);

    $site = finalizeSite();

    // A mid-window pass schedules the next one...
    (new FinalizeSitePositions($site->id, 1))->handle(app(SitePipelineRefresher::class));
    Queue::assertPushed(FinalizeSitePositions::class, fn (FinalizeSitePositions $job) => $job->attempt === 2);

    // ...the final pass does not — bounded so a never-ingesting task can't loop forever.
    (new FinalizeSitePositions($site->id, 5))->handle(app(SitePipelineRefresher::class));
    Queue::assertPushed(FinalizeSitePositions::class, 1); // still just the one from the first pass
});

it('is a safe no-op when the site id no longer exists', function () {
    Queue::fake();

    expect(fn () => (new FinalizeSitePositions('missing-ulid'))->handle(app(SitePipelineRefresher::class)))
        ->not->toThrow(Exception::class);

    Queue::assertNotPushed(FinalizeSitePositions::class);
});
