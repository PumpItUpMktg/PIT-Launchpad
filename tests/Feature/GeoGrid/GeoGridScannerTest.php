<?php

use App\GeoGrid\GeoGridScanner;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('launchpad.geo_grid.grid_size', 3);          // 9 points — a manageable fake
    config()->set('launchpad.geo_grid.poll_max_attempts', 1);
    config()->set('launchpad.geo_grid.poll_interval_seconds', 0);
});

/** Fake the maps standard-queue task lifecycle: 9 posted tasks, all ready, each returning $items. */
function fakeMaps(array $items): void
{
    $ids = collect(range(0, 8))->map(fn ($i): string => "task-{$i}");
    Http::fake([
        '*/serp/google/maps/task_post' => Http::response([
            'status_code' => 20000, 'status_message' => 'ok',
            'tasks' => $ids->map(fn ($id): array => ['id' => $id, 'status_code' => 20000])->all(),
        ]),
        '*/serp/google/maps/tasks_ready' => Http::response([
            'status_code' => 20000,
            'tasks' => [['id' => 'r', 'status_code' => 20000, 'result' => $ids->map(fn ($id): array => ['id' => $id])->all()]],
        ]),
        '*/serp/google/maps/task_get/advanced/*' => Http::response([
            'status_code' => 20000,
            'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => $items]]]],
        ]),
    ]);
}

it('scans a grid and matches the business rank by place_id, never by name', function () {
    fakeMaps([
        // A DIFFERENT business that happens to share our display name — must NOT be matched (wrong place_id).
        ['type' => 'maps_search', 'rank_absolute' => 1, 'title' => 'Sump Pump Gurus', 'domain' => 'impostor.com', 'place_id' => 'ChIJ_other', 'cid' => '999'],
        // Us — different display name, but OUR place_id. This is our real rank.
        ['type' => 'maps_search', 'rank_absolute' => 2, 'title' => 'SPG LLC', 'domain' => 'spg.com', 'place_id' => 'ChIJ_us', 'cid' => '222'],
        ['type' => 'maps_search', 'rank_absolute' => 3, 'title' => 'Rival', 'domain' => 'rival.com', 'place_id' => 'ChIJ_rival', 'cid' => '333'],
    ]);

    $site = Site::factory()->create();
    $location = Location::factory()->create(['site_id' => $site->id, 'gbp_url' => 'https://maps.google/?cid=222', 'place_id' => 'ChIJ_us', 'lat' => 40.7128, 'lng' => -74.0060]);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    $scan = app(GeoGridScanner::class)->scan($location, $keyword);

    expect($scan->status)->toBe('complete')
        ->and($scan->grid_size)->toBe(3)
        ->and($scan->points()->count())->toBe(9);

    // Every point matched US at rank 2 — by place_id, not the rank-1 name collision.
    $ranks = $scan->points()->pluck('rank')->unique()->all();
    expect($ranks)->toBe([2]);

    $point = $scan->points()->first();
    expect($point->competitors)->toHaveCount(2)                  // the two others, us excluded
        ->and($point->competitors[0]['rank'])->toBe(1)
        ->and($point->competitors[0]['name'])->toBe('Sump Pump Gurus');
});

it('records a null rank where the business does not appear within depth', function () {
    fakeMaps([
        ['type' => 'maps_search', 'rank_absolute' => 1, 'title' => 'Rival A', 'domain' => 'a.com', 'place_id' => 'ChIJ_a', 'cid' => '1'],
        ['type' => 'maps_search', 'rank_absolute' => 2, 'title' => 'Rival B', 'domain' => 'b.com', 'place_id' => 'ChIJ_b', 'cid' => '2'],
    ]);

    $site = Site::factory()->create();
    $location = Location::factory()->create(['site_id' => $site->id, 'gbp_url' => 'https://g/?cid=222', 'place_id' => 'ChIJ_us', 'lat' => 40.7128, 'lng' => -74.0060]);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    $scan = app(GeoGridScanner::class)->scan($location, $keyword);

    expect($scan->points()->whereNotNull('rank')->count())->toBe(0)  // never appears → all null
        ->and($scan->points()->first()->competitors)->toHaveCount(2);
});
