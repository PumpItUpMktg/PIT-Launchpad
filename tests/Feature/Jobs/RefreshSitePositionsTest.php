<?php

use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\LocalGrid\MockLocalGridProvider;
use App\Integrations\Serp\MockSerpProvider;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\Jobs\FinalizeSitePositions;
use App\Jobs\RefreshSitePositions;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

it('force-tracks every scored keyword now, writing organic snapshots (positions only)', function () {
    Queue::fake();

    $serp = new MockSerpProvider;
    foreach (['money kw', 'second kw'] as $q) {
        $serp->setResults($q, new SerpResultSet($q, [new SerpResult(3, 'https://acme.com/'.md5($q), 'acme.com')]));
    }
    app()->instance(SerpProvider::class, $serp);
    app()->instance(LocalGridProvider::class, new MockLocalGridProvider);

    $site = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com']);
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'money kw', 'status' => 'scored']);
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'second kw', 'status' => 'scored']);

    (new RefreshSitePositions($site->id))->handle(app(SitePipelineRefresher::class));

    // Both scored keywords got an organic snapshot — no cadence/budget gating on the force path.
    expect(PositionSnapshot::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(2);
});

it('chains the finalize pass in standard mode so the button self-completes', function () {
    Queue::fake();
    config(['services.dataforseo.mode' => 'standard']);
    app()->instance(SerpProvider::class, new MockSerpProvider);
    app()->instance(LocalGridProvider::class, new MockLocalGridProvider);

    $site = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com']);

    (new RefreshSitePositions($site->id))->handle(app(SitePipelineRefresher::class));

    Queue::assertPushed(FinalizeSitePositions::class, fn (FinalizeSitePositions $job) => $job->siteId === $site->id);
});

it('does not chain a finalize pass in live mode (results are recorded synchronously)', function () {
    Queue::fake();
    config(['services.dataforseo.mode' => 'live']);
    app()->instance(SerpProvider::class, new MockSerpProvider);
    app()->instance(LocalGridProvider::class, new MockLocalGridProvider);

    $site = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com']);

    (new RefreshSitePositions($site->id))->handle(app(SitePipelineRefresher::class));

    Queue::assertNotPushed(FinalizeSitePositions::class);
});

it('is a safe no-op when the site id no longer exists', function () {
    Queue::fake();

    expect(fn () => (new RefreshSitePositions('missing-ulid'))->handle(app(SitePipelineRefresher::class)))
        ->not->toThrow(Exception::class);

    Queue::assertNotPushed(FinalizeSitePositions::class);
});
