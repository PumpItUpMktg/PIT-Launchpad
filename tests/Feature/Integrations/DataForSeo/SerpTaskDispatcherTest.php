<?php

use App\Enums\SerpTaskState;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\SerpTaskDispatcher;
use App\Models\SerpTask;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

function serpDispatcher(): SerpTaskDispatcher
{
    return new SerpTaskDispatcher(new DataForSeoClient(app(Http::class), 'login', 'pass', 'https://api.dataforseo.com', 30));
}

const ORGANIC_POST = '/v3/serp/google/organic/task_post';

it('posts a fresh query and records a pending task', function () {
    HttpFacade::fake([
        '*/serp/google/organic/task_post' => HttpFacade::response(dfsEnvelope([])), // tasks[0].id = task-abc-123
    ]);

    $key = 'dfs:organic:2840:en:'.md5('sump pump repair');
    $task = serpDispatcher()->ensure('organic', $key, ORGANIC_POST, ['keyword' => 'sump pump repair']);

    expect($task)->not->toBeNull()
        ->and($task->state)->toBe(SerpTaskState::Pending)
        ->and($task->task_id)->toBe('task-abc-123');
    HttpFacade::assertSent(fn ($r) => str_contains($r->url(), '/task_post'));
});

it('never re-posts a query that already returned no_results (40102) — stops the dead-query spend', function () {
    $key = 'dfs:organic:2840:en:'.md5('Kitchen Plumbing');
    // A prior dead result for this exact (function × cache_key).
    $dead = SerpTask::factory()->create([
        'function' => 'organic',
        'task_id' => 'old-task',
        'cache_key' => $key,
        'query' => 'Kitchen Plumbing',
        'state' => SerpTaskState::NoResults,
    ]);

    HttpFacade::fake([
        '*/serp/google/organic/task_post' => HttpFacade::response(dfsEnvelope([])),
    ]);

    $task = serpDispatcher()->ensure('organic', $key, ORGANIC_POST, ['keyword' => 'Kitchen Plumbing']);

    // Returns the dead row, and — the point — makes NO new task_post call (no spend).
    expect($task->id)->toBe($dead->id)
        ->and($task->state)->toBe(SerpTaskState::NoResults);
    HttpFacade::assertNothingSent();
});
