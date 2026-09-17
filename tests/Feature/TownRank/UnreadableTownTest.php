<?php

use App\Models\Keyword;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\TownRankReport;
use App\TownRank\TownRankScanner;
use Illuminate\Support\Facades\Http;

it('gives a town two reads, then closes it as unreadable instead of retrying it forever', function () {
    config(['services.dataforseo.rate_limit_backoff_ms' => 0]);
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'pending',
        'points_count' => 1, 'scanned_at' => now()]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => 'Nowhere', 'lat' => 40.0, 'lng' => -74.0,
        'query' => 'q', 'provider_task_id' => 'task-bad']);

    // A task the vendor will never answer for: not "no results", just never a result.
    Http::fake([
        '*/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'r', 'status_code' => 20000, 'result' => [['id' => 'task-bad']]]]]),
        '*/task_get/advanced/task-bad' => Http::response(['status_code' => 40501, 'status_message' => 'Invalid Field']),
    ]);
    $scanner = app(TownRankScanner::class);

    // First read: counted, town left open for another pass.
    $scanner->collectPending($scan, 10);
    $point = $scan->points()->sole();
    expect($point->read_attempts)->toBe(1)
        ->and($point->collected_at)->toBeNull()
        ->and($point->read_error)->toContain('40501')
        ->and($scan->fresh()->status)->toBe('pending');

    // Second read: the ceiling. Closed with the reason, so the scan can finalize.
    $scanner->collectPending($scan->fresh(), 10);
    $point = $scan->points()->sole();
    expect($point->read_attempts)->toBe(2)
        ->and($point->collected_at)->not->toBeNull()
        ->and($point->rank)->toBeNull()
        ->and($scan->fresh()->status)->toBe('complete');   // no longer waiting on a task that never answers
});

it('reads an unreadable town as its own state, never as "not found"', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete',
        'points_count' => 2, 'scanned_at' => now()]);
    $missed = TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => 'RealMiss', 'lat' => 40.0, 'lng' => -74.0,
        'query' => 'q', 'rank' => null, 'collected_at' => now()]);
    $dead = TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => 'NoData', 'lat' => 40.1, 'lng' => -74.1,
        'query' => 'q', 'rank' => null, 'collected_at' => now(), 'read_attempts' => 2, 'read_error' => 'Invalid Field']);

    // Google answered for one and never for the other: two different facts, two different states.
    expect(TownRankReport::stateOf($missed, ['status' => 'complete']))->toBe('not_found')
        ->and(TownRankReport::stateOf($dead, ['status' => 'complete']))->toBe('unreadable');
});
