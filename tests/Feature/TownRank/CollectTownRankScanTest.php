<?php

use App\Integrations\DataForSeo\DataForSeoException;
use App\Jobs\CollectTownRankScan;
use App\Jobs\IngestTownRankScans;
use App\Models\Keyword;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\TownRankScanner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function directScan(Site $site, string $status = 'pending', int $points = 3): TownRankScan
{
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => $status, 'points_count' => $points, 'scanned_at' => now()]);
    for ($i = 1; $i <= $points; $i++) {
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => "Town {$i}", 'lat' => 40.0 + $i / 100, 'lng' => -74.0, 'query' => 'q', 'provider_task_id' => "task-{$i}"]);
    }

    return $scan;
}

it('dispatches one unique collector per pending scan and nothing for finished ones', function () {
    Bus::fake();
    $site = Site::factory()->create();
    $a = directScan($site);
    $b = directScan($site);
    directScan($site, 'complete');

    (new IngestTownRankScans)->handle();

    Bus::assertDispatchedTimes(CollectTownRankScan::class, 2);
    Bus::assertDispatched(CollectTownRankScan::class, fn (CollectTownRankScan $j) => $j->scanId === $a->id);
    Bus::assertDispatched(CollectTownRankScan::class, fn (CollectTownRankScan $j) => $j->scanId === $b->id);
    expect(new CollectTownRankScan($a->id))->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new CollectTownRankScan($a->id))->uniqueId())->toBe($a->id);
});

it('reads a scan\'s tasks directly by id: finished → collected, not finished yet → waits without an attempt, errored → an attempt, auth → stops', function () {
    config(['services.dataforseo.rate_limit_backoff_ms' => 0]);
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $scan = directScan($site);
    $ok = fn (int $rank) => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => [['type' => 'organic', 'rank_absolute' => $rank, 'url' => 'https://spg.com/x', 'domain' => 'spg.com']]]]]]]);
    Http::fake([
        '*/task_get/advanced/task-1' => $ok(2),
        '*/task_get/advanced/task-2' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'task-2', 'status_code' => 40601, 'status_message' => 'Task Handed.']]]),
        '*/task_get/advanced/task-3' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'task-3', 'status_code' => 40501, 'status_message' => 'Invalid Field.']]]),
        '*/tasks_ready' => Http::response('should not be called', 500),   // the direct path never asks
    ]);

    $spent = app(TownRankScanner::class)->collectDirect($scan, 10);

    $p = fn (int $i) => TownRankPoint::query()->withoutGlobalScopes()->where('scan_id', $scan->id)->where('label', "Town {$i}")->first();
    expect($spent)->toBe(3)
        ->and($p(1)->rank)->toBe(2)->and($p(1)->collected_at)->not->toBeNull()
        ->and($p(2)->collected_at)->toBeNull()->and($p(2)->read_attempts)->toBe(0)    // not finished on the vendor's side — no penalty
        ->and($p(3)->collected_at)->toBeNull()->and($p(3)->read_attempts)->toBe(1)->and($p(3)->read_error)->toContain('40501')
        ->and($scan->fresh()->status)->toBe('pending');
});

it('stops the run outright on an auth / quota failure — nothing is collectable through it', function () {
    config(['services.dataforseo.rate_limit_backoff_ms' => 0]);
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $scan = directScan($site);
    Http::fake(['*/task_get/advanced/*' => Http::response(['status_code' => 40100, 'status_message' => 'Unauthorized'])]);

    expect(fn () => app(TownRankScanner::class)->collectDirect($scan, 10))->toThrow(DataForSeoException::class);
});

it('the per-scan job collects within its budget and deadline, and closes an expired scan as partial', function () {
    config(['services.dataforseo.rate_limit_backoff_ms' => 0, 'launchpad.town_rank.ingest_batch' => 2]);
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $scan = directScan($site);
    Http::fake(['*/task_get/advanced/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => []]]]]])]);

    (new CollectTownRankScan($scan->id))->handle(app(TownRankScanner::class));
    expect(TownRankPoint::query()->withoutGlobalScopes()->where('scan_id', $scan->id)->whereNotNull('collected_at')->count())->toBe(2)   // budget 2 of 3
        ->and($scan->fresh()->status)->toBe('pending');

    (new CollectTownRankScan($scan->id))->handle(app(TownRankScanner::class));
    expect($scan->fresh()->status)->toBe('complete');
});

it('closes a scan past the expiry window as partial over what it has when its task never finishes', function () {
    config(['services.dataforseo.rate_limit_backoff_ms' => 0]);
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $stale = directScan($site, 'pending', 1);
    $stale->forceFill(['scanned_at' => now()->subHours(30)])->save();
    Http::fake(['*/task_get/advanced/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'x', 'status_code' => 40602, 'status_message' => 'Task In Queue.']]])]);

    (new CollectTownRankScan($stale->id))->handle(app(TownRankScanner::class));

    expect($stale->fresh()->status)->toBe('partial')
        ->and(TownRankPoint::query()->withoutGlobalScopes()->where('scan_id', $stale->id)->value('read_attempts'))->toBe(0);
});
