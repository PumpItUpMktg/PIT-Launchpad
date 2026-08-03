<?php

use App\Enums\BeatabilityLane;
use App\Enums\MarketTier;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\LocalGrid\MockLocalGridProvider;
use App\Integrations\Serp\MockSerpProvider;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

it('tracks a city keyword\'s local pack in ITS market, while an ordinary keyword uses the site priority market', function () {
    $serp = new MockSerpProvider;
    foreach (['sump pump', 'sump pump norristown'] as $q) {
        $serp->setResults($q, new SerpResultSet($q, [new SerpResult(4, 'https://acme.com/'.md5($q), 'acme.com')]));
    }
    app()->instance(SerpProvider::class, $serp);
    app()->instance(LocalGridProvider::class, new MockLocalGridProvider); // default grid coverage 0.5 → records

    $site = Site::factory()->create(['status' => 'active', 'domain_url' => 'https://acme.com']);
    $priorityMarket = Market::factory()->create(['site_id' => $site->id, 'name' => 'Home Metro', 'tier' => MarketTier::Priority->value]);
    // The pinned market is a distinct (non-priority) market, so the priority fallback is unambiguous.
    $city = Market::factory()->create(['site_id' => $site->id, 'name' => 'Norristown', 'tier' => MarketTier::Coverage->value]);

    // Ordinary silo keyword (no market pin) + a city keyword pinned to Norristown.
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump', 'status' => 'scored', 'market_id' => null]);
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump norristown', 'status' => 'scored', 'market_id' => $city->id]);

    app(SitePipelineRefresher::class)->trackNow($site);

    $localSnaps = PositionSnapshot::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)
        ->where('lane', BeatabilityLane::LocalPack->value)
        ->get()
        ->keyBy(fn (PositionSnapshot $s) => $s->keyword->query);

    // The city keyword grids Norristown; the ordinary keyword falls back to the site's priority market.
    expect($localSnaps['sump pump norristown']->market_id)->toBe($city->id)
        ->and($localSnaps['sump pump']->market_id)->toBe($priorityMarket->id);
});
