<?php

use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

/**
 * Build a DataForSEO response envelope around a task `result` payload, mirroring
 * the vendor's `{status_code, tasks:[{id, status_code, result}]}` shape.
 *
 * @param  array<int, mixed>  $result
 * @return array<string, mixed>
 */
function dfsEnvelope(array $result, int $topStatus = 20000, int $taskStatus = 20000): array
{
    return [
        'status_code' => $topStatus,
        'status_message' => 'ok.',
        'tasks' => [[
            'id' => 'task-abc-123',
            'status_code' => $taskStatus,
            'status_message' => 'Ok.',
            'result' => $result,
        ]],
    ];
}

function dfsClient(): DataForSeoClient
{
    return new DataForSeoClient(app(Http::class), 'login@example.com', 'secret', 'https://api.dataforseo.com', 30);
}

it('builds a batched search_volume request and parses volume/cpc/competition', function () {
    HttpFacade::fake([
        '*/keywords_data/google_ads/search_volume/live' => HttpFacade::response(dfsEnvelope([
            ['keyword' => 'drain cleaning', 'search_volume' => 1300, 'cpc' => 7.25, 'competition_index' => 42],
            ['keyword' => 'water heater repair', 'search_volume' => 880, 'cpc' => 9.1, 'competition_index' => 55],
        ])),
    ]);

    $out = dfsClient()->liveSearchVolume(['drain cleaning', 'water heater repair'], 2840, 'en');

    expect($out['drain cleaning']['volume'])->toBe(1300)
        ->and($out['drain cleaning']['cpc'])->toBe(7.25)
        ->and($out['drain cleaning']['competition'])->toBe(42.0)
        ->and($out['water heater repair']['volume'])->toBe(880);

    HttpFacade::assertSent(function ($request) {
        $body = $request->data()[0];

        return $request->hasHeader('Authorization')
            && str_contains($request->url(), 'search_volume/live')
            && $body['keywords'] === ['drain cleaning', 'water heater repair']
            && $body['location_code'] === 2840
            && $body['language_code'] === 'en';
    });
});

it('parses bulk keyword difficulty as the primary beatability input', function () {
    HttpFacade::fake([
        '*/bulk_keyword_difficulty/live' => HttpFacade::response(dfsEnvelope([
            ['keyword' => 'drain cleaning', 'keyword_difficulty' => 33],
        ])),
    ]);

    expect(dfsClient()->bulkKeywordDifficulty(['drain cleaning'], 2840, 'en'))
        ->toBe(['drain cleaning' => 33]);
});

it('parses related keywords', function () {
    HttpFacade::fake([
        '*/related_keywords/live' => HttpFacade::response(dfsEnvelope([
            ['keyword_data' => ['keyword' => 'clogged drain']],
            ['keyword_data' => ['keyword' => 'rooter service']],
            ['keyword_data' => ['keyword' => 'clogged drain']], // duplicate dropped
        ])),
    ]);

    expect(dfsClient()->relatedKeywords('drain cleaning', 2840, 'en', 20))
        ->toBe(['clogged drain', 'rooter service']);
});

it('parses organic results and ignores non-organic SERP items', function () {
    HttpFacade::fake([
        '*/serp/google/organic/live/advanced' => HttpFacade::response(dfsEnvelope([[
            'items' => [
                ['type' => 'featured_snippet', 'rank_absolute' => 1],
                ['type' => 'organic', 'rank_absolute' => 2, 'url' => 'https://a.com/x', 'domain' => 'a.com'],
                ['type' => 'organic', 'rank_absolute' => 3, 'url' => 'https://b.com/y', 'domain' => 'b.com'],
            ],
        ]])),
    ]);

    $out = dfsClient()->liveOrganic('drain cleaning', 2840, 'en', 20);

    expect($out)->toHaveCount(2)
        ->and($out[0])->toBe(['position' => 2, 'url' => 'https://a.com/x', 'domain' => 'a.com']);
});

it('parses local maps items', function () {
    HttpFacade::fake([
        '*/serp/google/maps/live/advanced' => HttpFacade::response(dfsEnvelope([[
            'items' => [
                ['type' => 'maps_search', 'rank_absolute' => 1, 'title' => 'Joe Plumbing', 'domain' => 'joe.com'],
                ['type' => 'local_pack', 'rank_absolute' => 2, 'title' => 'ignored'],
            ],
        ]])),
    ]);

    $out = dfsClient()->liveMaps('drain cleaning', '30.27,-97.74,14', 'en');

    expect($out)->toHaveCount(1)
        ->and($out[0])->toBe(['rank' => 1, 'name' => 'Joe Plumbing', 'domain' => 'joe.com']);
});

it('reads the zero-cost account endpoint for the verify probe', function () {
    HttpFacade::fake([
        '*/appendix/user_data' => HttpFacade::response(dfsEnvelope([
            ['login' => 'login@example.com', 'money' => ['balance' => 12.34]],
        ])),
    ]);

    expect(dfsClient()->userData())->toBe(['login' => 'login@example.com', 'balance' => 12.34]);
});

it('throws on a non-20000 top-level status_code envelope', function () {
    HttpFacade::fake([
        '*' => HttpFacade::response(dfsEnvelope([], topStatus: 40400)),
    ]);

    dfsClient()->liveSearchVolume(['x'], 2840, 'en');
})->throws(DataForSeoException::class);

it('throws on a non-20000 per-task status_code', function () {
    HttpFacade::fake([
        '*' => HttpFacade::response(dfsEnvelope([], taskStatus: 40501)),
    ]);

    dfsClient()->liveSearchVolume(['x'], 2840, 'en');
})->throws(DataForSeoException::class);

it('fails loudly and fatally on an auth error (HTTP 401)', function () {
    HttpFacade::fake([
        '*' => HttpFacade::response('unauthorized', 401),
    ]);

    try {
        dfsClient()->liveSearchVolume(['x'], 2840, 'en');
        $this->fail('expected DataForSeoException');
    } catch (DataForSeoException $e) {
        expect($e->fatal)->toBeTrue()
            ->and($e->statusCode)->toBe(401);
    }
});

it('marks a 402 quota/payment envelope fatal', function () {
    $e = DataForSeoException::envelope(40200, 'payment required');

    expect($e->fatal)->toBeTrue();
});
