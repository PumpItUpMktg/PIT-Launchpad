<?php

use App\Enums\DataForSeoMode;
use App\Enums\SerpTaskState;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoSerpProvider;
use App\Integrations\DataForSeo\IngestSerpTasks;
use App\Integrations\DataForSeo\SerpTaskDispatcher;
use App\Integrations\Serp\SerpResultSet;
use App\Models\SerpTask;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http as HttpFacade;

function organicCacheKey(string $query): string
{
    return 'dfs:organic:2840:en:'.md5($query);
}

function ingestStandardProvider(): DataForSeoSerpProvider
{
    $client = new DataForSeoClient(app(Http::class), 'login', 'pass', 'https://api.dataforseo.com', 30);

    return new DataForSeoSerpProvider(
        $client,
        new SerpTaskDispatcher($client),
        app(Cache::class),
        DataForSeoMode::Standard,
        locationCode: 2840,
        language: 'en',
        serpDepth: 20,
        relatedLimit: 20,
        cacheTtlHours: 168,
    );
}

it('ingests a ready task into the cache and flips it to ingested', function () {
    $key = organicCacheKey('drain cleaning');
    $task = SerpTask::factory()->create([
        'function' => 'organic',
        'task_id' => 'task-xyz',
        'cache_key' => $key,
        'query' => 'drain cleaning',
        'state' => SerpTaskState::Pending,
    ]);

    HttpFacade::fake([
        '*/serp/google/organic/tasks_ready' => HttpFacade::response([
            'status_code' => 20000,
            'tasks' => [['result' => [['id' => 'task-xyz']]]],
        ]),
        '*/serp/google/organic/task_get/advanced/*' => HttpFacade::response(dfsEnvelope([[
            'items' => [
                ['type' => 'organic', 'rank_absolute' => 1, 'url' => 'https://a.com/x', 'domain' => 'a.com'],
            ],
        ]])),
    ]);

    (new IngestSerpTasks)->handle(app(DataForSeoClient::class), app(Cache::class));

    expect($task->refresh()->state)->toBe(SerpTaskState::Ingested);

    $cached = app(Cache::class)->get($key);
    expect($cached)->toBeInstanceOf(SerpResultSet::class)
        ->and($cached->results)->toHaveCount(1);

    // The provider read is now a cache hit — the async loop is closed.
    $set = ingestStandardProvider()->results('drain cleaning');
    expect($set->results)->toHaveCount(1)
        ->and($set->results[0]->domain)->toBe('a.com');
});

it('marks a task failed (and retains the error) when collection errors — never silently dropped', function () {
    $task = SerpTask::factory()->create([
        'function' => 'organic',
        'task_id' => 'task-err',
        'cache_key' => organicCacheKey('broken'),
        'query' => 'broken',
        'state' => SerpTaskState::Pending,
    ]);

    HttpFacade::fake([
        '*/serp/google/organic/tasks_ready' => HttpFacade::response([
            'status_code' => 20000,
            'tasks' => [['result' => [['id' => 'task-err']]]],
        ]),
        '*/serp/google/organic/task_get/advanced/*' => HttpFacade::response(dfsEnvelope([], taskStatus: 40400)),
    ]);

    (new IngestSerpTasks)->handle(app(DataForSeoClient::class), app(Cache::class));

    $task->refresh();
    expect($task->state)->toBe(SerpTaskState::Failed)
        ->and($task->error)->not->toBeNull();
});

it('expires a stale pending task that never produced a result', function () {
    SerpTask::factory()->create([
        'function' => 'organic',
        'task_id' => 'task-old',
        'cache_key' => organicCacheKey('stale'),
        'query' => 'stale',
        'state' => SerpTaskState::Pending,
    ]);
    DB::table('serp_tasks')->update(['created_at' => now()->subHours(48)]);

    HttpFacade::fake([
        '*/serp/google/organic/tasks_ready' => HttpFacade::response([
            'status_code' => 20000,
            'tasks' => [['result' => []]],
        ]),
    ]);

    (new IngestSerpTasks)->handle(app(DataForSeoClient::class), app(Cache::class));

    $task = SerpTask::where('task_id', 'task-old')->first();
    expect($task->state)->toBe(SerpTaskState::Failed)
        ->and($task->error)->toContain('expired');
});
