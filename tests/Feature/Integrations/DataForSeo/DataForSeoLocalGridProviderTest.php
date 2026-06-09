<?php

use App\Enums\DataForSeoMode;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoLocalGridProvider;
use App\Integrations\DataForSeo\SerpTaskDispatcher;
use App\Models\Market;
use App\Models\SerpTask;
use App\Models\Site;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

function gridProvider(DataForSeoMode $mode): DataForSeoLocalGridProvider
{
    $client = new DataForSeoClient(app(Http::class), 'login', 'pass', 'https://api.dataforseo.com', 30);

    return new DataForSeoLocalGridProvider(
        $client,
        new SerpTaskDispatcher($client),
        app(Cache::class),
        $mode,
        language: 'en',
        gridSize: 3,
        gridStep: 0.01,
        cacheTtlHours: 168,
    );
}

it('derives a grid from the market centre, locates the own listing, and aggregates', function () {
    $site = Site::factory()->create(['domain_url' => 'https://ourbrand.com']);
    $market = Market::factory()->for($site)->create(['lat' => 30.2672, 'lng' => -97.7431]);

    HttpFacade::fake([
        '*/serp/google/maps/live/advanced' => HttpFacade::response(dfsEnvelope([[
            'items' => [
                ['type' => 'maps_search', 'rank_absolute' => 1, 'title' => 'Joe Plumbing', 'domain' => 'joe.com'],
                ['type' => 'maps_search', 'rank_absolute' => 2, 'title' => 'Our Brand', 'domain' => 'www.ourbrand.com'],
            ],
        ]])),
    ]);

    $grid = gridProvider(DataForSeoMode::Live)->grid('drain cleaning', $market->id);

    // 3x3 grid, every cell returns our listing at rank 2 and one competitor.
    expect($grid->coverage)->toBe(1.0)
        ->and($grid->avgRank)->toBe(2.0)
        ->and($grid->pctTop3)->toBe(1.0)
        ->and($grid->packCompetitors)->toHaveCount(1)
        ->and($grid->packCompetitors[0]->name)->toBe('Joe Plumbing');

    HttpFacade::assertSentCount(9);
});

it('dispatches one deduped maps task per cell in standard mode and returns a neutral grid', function () {
    $site = Site::factory()->create(['domain_url' => 'https://ourbrand.com']);
    $market = Market::factory()->for($site)->create(['lat' => 30.2672, 'lng' => -97.7431]);

    HttpFacade::fake([
        '*/serp/google/maps/task_post' => HttpFacade::response([
            'status_code' => 20000,
            'tasks' => [['id' => 'task-maps']],
        ]),
    ]);

    $grid = gridProvider(DataForSeoMode::Standard)->grid('drain cleaning', $market->id);

    expect($grid->coverage)->toBe(0.0)
        ->and($grid->packCompetitors)->toBe([]);
    expect(SerpTask::where('function', 'maps')->count())->toBe(9);

    // Re-running inside the window posts no new tasks (dedupe per cell).
    gridProvider(DataForSeoMode::Standard)->grid('drain cleaning', $market->id);
    expect(SerpTask::where('function', 'maps')->count())->toBe(9);
});

it('returns a neutral grid when the market has no geo centre', function () {
    $market = Market::factory()->create(['lat' => null, 'lng' => null]);

    HttpFacade::fake();

    $grid = gridProvider(DataForSeoMode::Live)->grid('drain cleaning', $market->id);

    expect($grid->coverage)->toBe(0.0)
        ->and($grid->avgRank)->toBe(0.0);
    HttpFacade::assertNothingSent();
});
