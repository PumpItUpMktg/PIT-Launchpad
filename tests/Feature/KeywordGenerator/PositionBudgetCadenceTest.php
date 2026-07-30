<?php

use App\Enums\PipelineTrigger;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\LocalGrid\MockLocalGridProvider;
use App\Integrations\Serp\MockSerpProvider;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/** Bind a SERP mock that returns an own-domain result for each given query, plus a null local grid. */
function pbcSerp(array $queries): void
{
    $serp = new MockSerpProvider;
    foreach ($queries as $q) {
        $serp->setResults($q, new SerpResultSet($q, [new SerpResult(4, 'https://acme.com/'.md5($q), 'acme.com')]));
    }
    app()->instance(SerpProvider::class, $serp);
    app()->instance(LocalGridProvider::class, new MockLocalGridProvider);
}

function pbcSnapshots(Site $site): int
{
    return PositionSnapshot::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count();
}

function pbcScored(Site $site, string $query, float $opportunity, int $priority = 0): Keyword
{
    return Keyword::factory()->create([
        'site_id' => $site->id, 'query' => $query, 'status' => 'scored',
        'opportunity_score' => $opportunity, 'priority' => $priority,
    ]);
}

it('caps the scheduled sweep at the tenant budget, always keeping operator-priority targets', function () {
    pbcSerp(['money kw', 'filler a', 'filler b']);
    $site = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com', 'budget_ceiling' => 1]);

    $money = pbcScored($site, 'money kw', 1.0, priority: 10); // forced — never dropped
    pbcScored($site, 'filler a', 0.5);
    pbcScored($site, 'filler b', 0.4);

    app(SitePipelineRefresher::class)->refresh($site, PipelineTrigger::Scheduled);

    // Ceiling of 1 → only the forced money keyword is sampled this run.
    $snaps = PositionSnapshot::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($snaps)->toHaveCount(1)
        ->and($snaps[0]->keyword_id)->toBe($money->id);
});

it('is uncapped when no budget ceiling is set — every fresh scored keyword is sampled', function () {
    pbcSerp(['a', 'b', 'c']);
    $site = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com', 'budget_ceiling' => null]);
    pbcScored($site, 'a', 0.9);
    pbcScored($site, 'b', 0.5);
    pbcScored($site, 'c', 0.2);

    app(SitePipelineRefresher::class)->refresh($site, PipelineTrigger::Scheduled);

    expect(pbcSnapshots($site))->toBe(3);
});

it('skips a keyword still inside its tier cadence window on the scheduled sweep', function () {
    pbcSerp(['plumber austin']);
    $site = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com']);
    $keyword = pbcScored($site, 'plumber austin', 1.0); // single keyword → tier A (7-day cadence)

    // A snapshot 3 days ago is INSIDE tier A's 7-day window → not due.
    PositionSnapshot::factory()->create([
        'site_id' => $site->id, 'keyword_id' => $keyword->id, 'market_id' => null,
        'captured_at' => now()->subDays(3),
    ]);

    $scheduled = app(SitePipelineRefresher::class)->refresh($site, PipelineTrigger::Scheduled);

    // Tracking ran, but the keyword was inside its window → no new snapshot.
    expect($scheduled->trackingRan)->toBeTrue()
        ->and(pbcSnapshots($site))->toBe(1);
});

it('samples a keyword once it is past its tier cadence window', function () {
    pbcSerp(['plumber austin']);
    $site = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com']);
    $keyword = pbcScored($site, 'plumber austin', 1.0); // tier A → 7-day cadence

    // A snapshot 10 days ago is PAST tier A's 7-day window → due again.
    PositionSnapshot::factory()->create([
        'site_id' => $site->id, 'keyword_id' => $keyword->id, 'market_id' => null,
        'captured_at' => now()->subDays(10),
    ]);

    app(SitePipelineRefresher::class)->refresh($site, PipelineTrigger::Scheduled);

    expect(pbcSnapshots($site))->toBe(2); // re-sampled
});
