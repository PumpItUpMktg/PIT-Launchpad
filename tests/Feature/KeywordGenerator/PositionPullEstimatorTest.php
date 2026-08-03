<?php

use App\Enums\MarketTier;
use App\KeywordGenerator\Pipeline\PositionPullEstimator;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Site;

function ppeScored(Site $site, string $query): Keyword
{
    return Keyword::factory()->create(['site_id' => $site->id, 'query' => $query, 'status' => 'scored']);
}

it('counts organic + local-grid tasks per scored keyword and estimates the cost', function () {
    config()->set('services.dataforseo.grid_size', 3); // 3×3 = 9 grid points
    config()->set('services.dataforseo.serp_task_cost', 0.0012);
    config()->set('services.dataforseo.maps_task_cost', 0.002);

    $site = Site::factory()->create(['domain_url' => 'https://acme.com']);
    Market::factory()->create(['site_id' => $site->id, 'tier' => MarketTier::Priority->value]);

    ppeScored($site, 'water heater repair');
    ppeScored($site, 'drain cleaning');
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'draft one', 'status' => 'scoring']); // not scored → ignored

    $e = app(PositionPullEstimator::class)->estimate($site);

    // 2 scored keywords, host set, priority market present → organic 2, local 2×9=18.
    expect($e->keywords)->toBe(2)
        ->and($e->gridPoints)->toBe(9)
        ->and($e->organicTasks)->toBe(2)
        ->and($e->localTasks)->toBe(18)
        ->and($e->totalTasks())->toBe(20)
        ->and($e->isEmpty())->toBeFalse()
        // 2×0.0012 + 18×0.002 = 0.0024 + 0.036 = 0.0384
        ->and(round($e->estimatedCost, 4))->toBe(0.0384)
        ->and($e->costLabel())->toBe('$0.04');
});

it('drops the local lane with no priority market, and the organic lane with no host', function () {
    $noMarket = Site::factory()->create(['domain_url' => 'https://acme.com']);
    ppeScored($noMarket, 'kw');
    $e1 = app(PositionPullEstimator::class)->estimate($noMarket);
    expect($e1->organicTasks)->toBe(1)->and($e1->localTasks)->toBe(0);

    $noHost = Site::factory()->create(['domain_url' => null]);
    Market::factory()->create(['site_id' => $noHost->id, 'tier' => MarketTier::Priority->value]);
    ppeScored($noHost, 'kw');
    $e2 = app(PositionPullEstimator::class)->estimate($noHost);
    expect($e2->organicTasks)->toBe(0)->and($e2->localTasks)->toBeGreaterThan(0);
});

it('is empty when the site has no scored keywords', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.com']);

    $e = app(PositionPullEstimator::class)->estimate($site);

    expect($e->keywords)->toBe(0)
        ->and($e->isEmpty())->toBeTrue()
        ->and($e->totalTasks())->toBe(0);
});
