<?php

use App\Enums\DataForSeoMode;
use App\Enums\SerpTaskState;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoSerpProvider;
use App\Integrations\DataForSeo\SerpTaskDispatcher;
use App\Integrations\Serp\KeywordMetrics;
use App\Integrations\Serp\SerpResultSet;
use App\Models\SerpTask;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

function serpProvider(DataForSeoMode $mode): DataForSeoSerpProvider
{
    $client = new DataForSeoClient(app(Http::class), 'login', 'pass', 'https://api.dataforseo.com', 30);

    return new DataForSeoSerpProvider(
        $client,
        new SerpTaskDispatcher($client),
        app(Cache::class),
        $mode,
        locationCode: 2840,
        language: 'en',
        serpDepth: 20,
        relatedLimit: 20,
        cacheTtlHours: 168,
    );
}

it('assembles metrics from search_volume + difficulty + related, then caches', function () {
    HttpFacade::fake([
        '*/search_volume/live' => HttpFacade::response(dfsEnvelope([
            ['keyword' => 'drain cleaning', 'search_volume' => 1300, 'cpc' => 7.0, 'competition_index' => 40],
        ])),
        '*/bulk_keyword_difficulty/live' => HttpFacade::response(dfsEnvelope([
            ['keyword' => 'drain cleaning', 'keyword_difficulty' => 28],
        ])),
        '*/related_keywords/live' => HttpFacade::response(dfsEnvelope([
            ['keyword_data' => ['keyword' => 'clogged drain']],
        ])),
    ]);

    $provider = serpProvider(DataForSeoMode::Standard);

    $metrics = $provider->metrics('drain cleaning');

    expect($metrics)->toBeInstanceOf(KeywordMetrics::class)
        ->and($metrics->volume)->toBe(1300)
        ->and($metrics->difficulty)->toBe(28)
        ->and($metrics->relatedTerms)->toBe(['clogged drain']);

    // Second call is served from cache — no further HTTP within the cadence window.
    $provider->metrics('drain cleaning');
    HttpFacade::assertSentCount(3);
});

it('returns a live organic result set in live mode', function () {
    HttpFacade::fake([
        '*/serp/google/organic/live/advanced' => HttpFacade::response(dfsEnvelope([[
            'items' => [
                ['type' => 'organic', 'rank_absolute' => 1, 'url' => 'https://a.com/x', 'domain' => 'a.com'],
            ],
        ]])),
    ]);

    $set = serpProvider(DataForSeoMode::Live)->results('drain cleaning');

    expect($set)->toBeInstanceOf(SerpResultSet::class)
        ->and($set->results)->toHaveCount(1)
        ->and($set->results[0]->domain)->toBe('a.com');
});

it('dispatches a deduped standard task and returns an empty set until ingested', function () {
    HttpFacade::fake([
        '*/serp/google/organic/task_post' => HttpFacade::response([
            'status_code' => 20000,
            'tasks' => [['id' => 'task-xyz']],
        ]),
    ]);

    $provider = serpProvider(DataForSeoMode::Standard);

    $first = $provider->results('drain cleaning');

    expect($first->results)->toBe([]);
    expect(SerpTask::where('function', 'organic')->where('state', SerpTaskState::Pending->value)->count())->toBe(1);

    // A refresh within the window must not double-spend: no second task posted.
    $provider->results('drain cleaning');

    expect(SerpTask::where('function', 'organic')->count())->toBe(1);
    HttpFacade::assertSentCount(1);
});
