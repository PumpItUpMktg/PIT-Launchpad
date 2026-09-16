<?php

use App\Models\Keyword;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function recollectFixture(): array
{
    config(['services.dataforseo.rate_limit_backoff_ms' => 0]);
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 3, 'found_count' => 0, 'scanned_at' => now()->subDays(2)]);
    foreach (['a', 'b'] as $l) {
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => "phantom-{$l}", 'lat' => 40.0, 'lng' => -74.0, 'query' => 'q', 'provider_task_id' => "task-{$l}", 'collected_at' => now()->subDays(2), 'rank' => null, 'top_results' => []]);
    }
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => 'real-miss', 'lat' => 40.0, 'lng' => -74.0, 'query' => 'q', 'provider_task_id' => 'task-m', 'collected_at' => now()->subDays(2), 'rank' => null, 'top_results' => [['position' => 1, 'url' => 'https://rival.com', 'domain' => 'rival.com']]]);

    return [$site, $scan];
}

it('reports the phantom towns per scan and spends nothing by default', function () {
    [$site] = recollectFixture();
    Http::fake();

    expect(Artisan::call('launchpad:town-rank-recollect', ['site' => 'Sump Pump Gurus']))->toBe(0);
    $out = Artisan::output();

    expect($out)->toContain('sump pump service')->toContain('town search')
        ->toContain('Phantom towns: 2 across 1 scan(s)')
        ->toContain('Read-only');
    Http::assertNothingSent();
    expect(TownRankPoint::whereNotNull('rank')->count())->toBe(0);
});

it('--execute re-reads the phantoms from their tasks and writes the real ranks, leaving the real not-found alone', function () {
    [$site, $scan] = recollectFixture();
    Http::fake([
        '*/task_get/advanced/task-a' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => [['type' => 'organic', 'rank_absolute' => 4, 'url' => 'https://spg.com/a', 'domain' => 'spg.com']]]]]]]),
        '*/task_get/advanced/task-b' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => [['type' => 'organic', 'rank_absolute' => 1, 'url' => 'https://rival.com/b', 'domain' => 'rival.com']]]]]]]),
    ]);

    expect(Artisan::call('launchpad:town-rank-recollect', ['site' => 'Sump Pump Gurus', '--keyword' => 'sump pump service', '--execute' => true]))->toBe(0);
    $out = Artisan::output();

    expect($out)->toContain('Re-reading 2 town(s)')->toContain('read 2 · ranked 1 · not found 1 · unreadable 0');
    $byLabel = $scan->points()->get()->keyBy('label');
    expect($byLabel['phantom-a']->rank)->toBe(4)
        ->and($byLabel['phantom-b']->rank)->toBeNull()
        ->and($byLabel['phantom-b']->top_results)->toHaveCount(1)
        ->and($byLabel['real-miss']->top_results)->toHaveCount(1)
        ->and($scan->fresh()->found_count)->toBe(1);
    Http::assertSentCount(2);
});

it('says so when a site has no phantom towns', function () {
    $site = Site::factory()->create(['brand_name' => 'Clean Co', 'domain_url' => 'https://clean.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'x']);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'local', 'status' => 'complete', 'points_count' => 1, 'found_count' => 1, 'scanned_at' => now()]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => 't', 'lat' => 40.0, 'lng' => -74.0, 'query' => 'q', 'provider_task_id' => 'task-t', 'collected_at' => now(), 'rank' => 2, 'top_results' => []]);

    Artisan::call('launchpad:town-rank-recollect', ['site' => 'Clean Co', '--execute' => true]);

    expect(Artisan::output())->toContain('Phantom towns: 0 across 1 scan(s)');
});
