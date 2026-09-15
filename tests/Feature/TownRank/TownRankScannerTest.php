<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\TownRankPoints;
use App\TownRank\TownRankScanner;
use Illuminate\Support\Facades\Http;

/** A site with a domain, one location, and two assigned coverage towns (Hackettstown paged, Mansfield not). */
function townRankSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://www.spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83]);
    $hack = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'population' => 10000, 'lat' => 40.8540, 'lng' => -74.8290, 'source_location_ids' => [$loc->id], 'geo_id' => '3404128590']);
    $mans = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Mansfield', 'state' => 'NJ', 'population' => 7000, 'lat' => 40.80, 'lng' => -74.85, 'source_location_ids' => [$loc->id], 'geo_id' => '3404143050']);
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'geo_id' => '3404128590', 'slug' => 'hackettstown-nj']);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'is_grid_keyword' => true]);

    return [$site, $keyword, $hack, $mans];
}

/** task_post → $count ids; tasks_ready → the first $readyCount; task_get → $items (organic shape). */
function fakeOrganicQueue(int $count, int $readyCount, array $items): void
{
    $ids = collect(range(0, max(0, $count - 1)))->map(fn ($i): string => "otask-{$i}");
    Http::fake([
        '*/serp/google/organic/task_post' => Http::response(['status_code' => 20000, 'tasks' => $ids->map(fn ($id): array => ['id' => $id, 'status_code' => 20000])->all()]),
        '*/serp/google/organic/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'r', 'status_code' => 20000, 'result' => $ids->take($readyCount)->map(fn ($id): array => ['id' => $id])->all()]]]),
        '*/serp/google/organic/task_get/advanced/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => $items]]]]]),
    ]);
}

function organicItems(): array
{
    return [
        ['type' => 'organic', 'rank_absolute' => 1, 'url' => 'https://rival.com/sump', 'domain' => 'rival.com'],
        ['type' => 'paid', 'rank_absolute' => 2, 'url' => 'https://ad.com', 'domain' => 'ad.com'],          // ignored by the parser
        ['type' => 'organic', 'rank_absolute' => 3, 'url' => 'https://www.spg.com/hackettstown-nj/', 'domain' => 'www.spg.com'],
        ['type' => 'organic', 'rank_absolute' => 4, 'url' => 'https://www.spg.com/sump-pump-repair/', 'domain' => 'www.spg.com'],
    ];
}

it('lists the site\'s covered towns once each, population-descending, with the town page matched by GEOID', function () {
    [$site] = townRankSite();

    $points = app(TownRankPoints::class)->forSite($site);

    expect($points)->toHaveCount(2)
        ->and($points[0]['label'])->toBe('Hackettstown')
        ->and($points[0]['state'])->toBe('NJ')
        ->and($points[0]['page_url'])->toBe('https://www.spg.com/hackettstown-nj')
        ->and($points[0]['page_match'])->toBe('geoid')
        ->and($points[1]['label'])->toBe('Mansfield')
        ->and($points[1]['page_url'])->toBeNull()
        ->and($points[1]['page_match'])->toBeNull();
});

it('posts one organic task per town — from the town\'s coordinate in local mode, as "keyword town ST" nationally in town mode', function () {
    fakeOrganicQueue(2, 0, []);
    [$site, $keyword] = townRankSite();

    $local = app(TownRankScanner::class)->post($site, $keyword, TownRankScan::MODE_LOCAL);
    $town = app(TownRankScanner::class)->post($site, $keyword, TownRankScan::MODE_TOWN_QUERY);

    expect($local->status)->toBe('pending')->and($local->points_count)->toBe(2)
        ->and($local->points()->pluck('query')->unique()->all())->toBe(['sump pump repair'])
        ->and($local->points()->pluck('provider_task_id')->all())->toBe(['otask-0', 'otask-1'])
        ->and($town->points()->orderBy('label')->pluck('query')->all())->toBe(['sump pump repair Hackettstown NJ', 'sump pump repair Mansfield NJ']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'organic/task_post')
        && count($request->data()) === 2
        && (isset($request->data()[0]['location_coordinate']) || isset($request->data()[0]['location_code'])));
    $posts = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'task_post'))->map(fn ($pair) => $pair[0]->data());
    expect($posts->first()[0]['location_coordinate'])->toBe('40.8540000,-74.8290000')   // local: from the town
        ->and($posts->last()[0])->toHaveKey('location_code')                              // town query: national
        ->and($posts->last()[0])->not->toHaveKey('location_coordinate');
});

it('collects ready results, ranks the site by its own host (www-insensitive, first owned result), and keeps the top results', function () {
    fakeOrganicQueue(2, 2, organicItems());
    [$site, $keyword] = townRankSite();
    $scan = app(TownRankScanner::class)->post($site, $keyword, TownRankScan::MODE_LOCAL);

    $spent = app(TownRankScanner::class)->collectPending($scan, 10);

    $scan->refresh();
    $point = $scan->points()->where('label', 'Hackettstown')->first();
    expect($spent)->toBe(2)
        ->and($scan->status)->toBe('complete')
        ->and($scan->found_count)->toBe(2)
        ->and($point->rank)->toBe(3)
        ->and($point->ranking_url)->toBe('https://www.spg.com/hackettstown-nj/')
        ->and($point->top_results)->toHaveCount(3)           // organic only — the paid item is dropped
        ->and($point->top_results[0]['domain'])->toBe('rival.com')
        ->and($point->collected_at)->not->toBeNull();
});

it('leaves not-ready towns pending, within the budget, and completes on a later call', function () {
    // tasks_ready answers from a mutable count (a second Http::fake would merge behind the first, not replace it).
    $readyCount = 1;
    $ids = collect(['otask-0', 'otask-1']);
    Http::fake([
        '*/serp/google/organic/task_post' => Http::response(['status_code' => 20000, 'tasks' => $ids->map(fn ($id): array => ['id' => $id, 'status_code' => 20000])->all()]),
        '*/serp/google/organic/tasks_ready' => function () use ($ids, &$readyCount) {   // by reference: an arrow fn would freeze the count at 1
            return Http::response(['status_code' => 20000, 'tasks' => [['id' => 'r', 'status_code' => 20000, 'result' => $ids->take($readyCount)->map(fn ($id): array => ['id' => $id])->all()]]]);
        },
        '*/serp/google/organic/task_get/advanced/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => organicItems()]]]]]),
    ]);
    [$site, $keyword] = townRankSite();
    $scan = app(TownRankScanner::class)->post($site, $keyword, TownRankScan::MODE_LOCAL);

    expect(app(TownRankScanner::class)->collectPending($scan, 10))->toBe(1);
    $scan->refresh();
    expect($scan->status)->toBe('pending')
        ->and($scan->points()->whereNull('collected_at')->count())->toBe(1);

    $readyCount = 2;   // now both ready
    expect(app(TownRankScanner::class)->collectPending($scan, 10))->toBe(1);
    expect($scan->fresh()->status)->toBe('complete');
});

it('records not-found when the site is absent from a town\'s results', function () {
    fakeOrganicQueue(2, 2, [['type' => 'organic', 'rank_absolute' => 1, 'url' => 'https://rival.com', 'domain' => 'rival.com']]);
    [$site, $keyword] = townRankSite();
    $scan = app(TownRankScanner::class)->post($site, $keyword, TownRankScan::MODE_LOCAL);

    app(TownRankScanner::class)->collectPending($scan, 10);

    expect($scan->fresh()->found_count)->toBe(0)
        ->and($scan->points()->whereNull('rank')->whereNotNull('collected_at')->count())->toBe(2);
});

it('derives the host www-insensitively and returns null without a domain', function () {
    expect(TownRankScanner::host('https://www.spg.com/'))->toBe('spg.com')
        ->and(TownRankScanner::host('spg.com'))->toBe('spg.com')
        ->and(TownRankScanner::host(null))->toBeNull();
});

it('stops collecting at a wall-clock deadline and leaves the rest pending for the next run', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair']);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'local', 'status' => 'pending', 'points_count' => 2, 'scanned_at' => now()]);
    foreach (['a', 'b'] as $id) {
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => $id, 'lat' => 40.0, 'lng' => -74.0, 'query' => 'q', 'provider_task_id' => "task-{$id}"]);
    }
    Http::fake([
        '*/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'r', 'status_code' => 20000, 'result' => [['id' => 'task-a'], ['id' => 'task-b']]]]]),
        '*/task_get/advanced/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => []]]]]]),
    ]);

    // A deadline already in the past: nothing is fetched, the scan stays pending.
    expect(app(TownRankScanner::class)->collectPending($scan, 10, microtime(true) - 1))->toBe(0)
        ->and($scan->fresh()->status)->toBe('pending');

    // A deadline well ahead: both collected, scan complete.
    expect(app(TownRankScanner::class)->collectPending($scan, 10, microtime(true) + 60))->toBe(2)
        ->and($scan->fresh()->status)->toBe('complete');
});

it('never records a transient read failure as "not found": only a genuine empty SERP is, the rest waits for the next run', function () {
    config(['services.dataforseo.rate_limit_backoff_ms' => 0]);
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair']);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'local', 'status' => 'pending', 'points_count' => 3, 'scanned_at' => now()]);
    foreach (['limited', 'empty', 'ok'] as $id) {
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => $id, 'lat' => 40.0, 'lng' => -74.0, 'query' => 'q', 'provider_task_id' => "task-{$id}"]);
    }
    Http::fake([
        '*/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'r', 'status_code' => 20000, 'result' => [['id' => 'task-limited'], ['id' => 'task-empty'], ['id' => 'task-ok']]]]]),
        '*/task_get/advanced/task-limited' => Http::response(['status_code' => 40202, 'status_message' => 'Too many requests']),      // rate-limited, even after the client's retries
        '*/task_get/advanced/task-empty' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'e', 'status_code' => 40102, 'status_message' => 'No Search Results']]]),
        '*/task_get/advanced/task-ok' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => [['type' => 'organic', 'rank_absolute' => 2, 'url' => 'https://spg.com/x', 'domain' => 'spg.com']]]]]]]),
    ]);

    app(TownRankScanner::class)->collectPending($scan, 10);

    $byLabel = $scan->points()->get()->keyBy('label');
    expect($byLabel['limited']->collected_at)->toBeNull()          // left for the next run, not a phantom miss
        ->and($byLabel['empty']->collected_at)->not->toBeNull()
        ->and($byLabel['empty']->rank)->toBeNull()                 // Google returned nothing: genuinely not found
        ->and($byLabel['ok']->rank)->toBe(2)
        ->and($scan->fresh()->status)->toBe('pending');            // one town still owed
});

it('posts to DataForSEO\'s high-priority queue only when configured, and the cost estimate doubles with it', function () {
    config(['launchpad.town_rank.cost_per_request' => 0.0012]);
    expect(TownRankScanner::highPriority())->toBeFalse()
        ->and(TownRankScanner::costPerRequest())->toBe(0.0012);

    config(['launchpad.town_rank.priority' => 2]);
    expect(TownRankScanner::highPriority())->toBeTrue()
        ->and(TownRankScanner::costPerRequest())->toBe(0.0024);

    fakeOrganicQueue(2, 0, []);
    [$site, $keyword] = townRankSite();
    app(TownRankScanner::class)->post($site, $keyword, TownRankScan::MODE_LOCAL);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/task_post')
        && collect($request->data())->every(fn (array $task): bool => ($task['priority'] ?? null) === 2));
});
