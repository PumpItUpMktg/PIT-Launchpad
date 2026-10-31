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

it('sees through Google\'s encrypted redirect wrapper: the breadcrumb names the real site, --masked re-reads such towns, and the brand name is the last resort', function () {
    [$site, $scan] = recollectFixture();
    // A town read before the parser saw through the wrapper: "not found", page full of google.com/goto rows.
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => 'masked-town', 'lat' => 40.0, 'lng' => -74.0, 'query' => 'q', 'provider_task_id' => 'task-x', 'collected_at' => now()->subDays(2), 'rank' => null, 'top_results' => [
        ['position' => 5, 'url' => 'https://google.com/goto?url=CAESkgEB', 'domain' => 'google.com'],
        ['position' => 6, 'url' => 'https://google.com/goto?url=CAESaAHr', 'domain' => 'google.com'],
    ]]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => 'brand-town', 'lat' => 40.0, 'lng' => -74.0, 'query' => 'q', 'provider_task_id' => 'task-y', 'collected_at' => now()->subDays(2), 'rank' => null, 'top_results' => [
        ['position' => 5, 'url' => 'https://google.com/goto?url=CAESkgEB', 'domain' => 'google.com'],
    ]]);
    $scan->forceFill(['points_count' => 5])->save();

    $task = fn (array $items): array => ['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => $items]]]]];
    Http::fake([
        '*/task_get/advanced/task-a' => Http::response($task([['type' => 'organic', 'rank_absolute' => 4, 'url' => 'https://spg.com/a', 'domain' => 'spg.com']])),
        '*/task_get/advanced/task-b' => Http::response($task([['type' => 'organic', 'rank_absolute' => 1, 'url' => 'https://rival.com/b', 'domain' => 'rival.com']])),
        // The wrapper everywhere; the breadcrumb still names each site — ours sits at absolute position 6.
        '*/task_get/advanced/task-x' => Http::response($task([
            ['type' => 'ai_overview', 'rank_absolute' => 1],
            ['type' => 'local_pack', 'rank_absolute' => 2, 'url' => 'https://google.com/goto?url=CAESYg', 'domain' => 'google.com'],
            ['type' => 'organic', 'rank_absolute' => 5, 'url' => 'https://google.com/goto?url=CAESkgEB', 'domain' => 'google.com', 'title' => 'TOP 10 BEST Sump Pump Repair', 'breadcrumb' => 'https://www.yelp.com › search', 'website_name' => 'Yelp'],
            ['type' => 'organic', 'rank_absolute' => 6, 'url' => 'https://google.com/goto?url=CAESaAHr', 'domain' => 'google.com', 'title' => 'Sump Pump Service in Mansfield, NJ | Sump Pump Gurus', 'breadcrumb' => 'https://spg.com › hackettstown-nj › mansfield-nj', 'website_name' => 'Sump Pump Gurus'],
            ['type' => 'organic', 'rank_absolute' => 7, 'url' => 'https://townplumbing.com/x', 'domain' => 'townplumbing.com'],
        ])),
        // No usable breadcrumb or site name (the provider echoes the wrapper there too): the page title's brand
        // suffix is what is left — it equals the brand, so the rank is taken, the URL is not. A listing that
        // merely mentions the brand, or a near-miss brand, is never taken.
        '*/task_get/advanced/task-y' => Http::response($task([
            ['type' => 'organic', 'rank_absolute' => 1, 'url' => 'https://google.com/goto?url=CAESxx', 'domain' => 'google.com', 'title' => 'Sump Pump Gurus | Better Business Bureau® Profile', 'website_name' => 'BBB', 'breadcrumb' => 'https://google.com › goto'],
            ['type' => 'organic', 'rank_absolute' => 2, 'url' => 'https://google.com/goto?url=CAESyy', 'domain' => 'google.com', 'title' => 'SUMP PUMP GURUS - Updated October 2026 - 12 Photos - Yelp', 'website_name' => 'Yelp', 'breadcrumb' => 'https://google.com › goto'],
            ['type' => 'organic', 'rank_absolute' => 3, 'url' => 'https://google.com/goto?url=CAESzz', 'domain' => 'google.com', 'title' => 'Sump Pump Gurus | Sump Pump & Basement Water Specialists', 'website_name' => 'google.com', 'breadcrumb' => 'https://google.com › goto'],
            ['type' => 'organic', 'rank_absolute' => 4, 'url' => 'https://google.com/goto?url=CAESqq', 'domain' => 'google.com', 'title' => 'Basement help | Sump Pump Gurus of Texas', 'website_name' => 'Sump Pump Gurus of Texas'],
        ])),
    ]);

    // Report: masked towns are counted separately and NOT re-read without the flag.
    expect(Artisan::call('launchpad:town-rank-recollect', ['site' => 'Sump Pump Gurus', '--keyword' => 'sump pump service']))->toBe(0);
    expect(Artisan::output())->toContain('Masked towns: 2')->toContain('Add --masked to include them');

    expect(Artisan::call('launchpad:town-rank-recollect', ['site' => 'Sump Pump Gurus', '--keyword' => 'sump pump service', '--execute' => true, '--masked' => true]))->toBe(0);
    $out = Artisan::output();
    expect($out)->toContain('Re-reading 4 town(s)')->toContain('read 4 · ranked 3 · not found 1 · unreadable 0');

    $byLabel = $scan->points()->get()->keyBy('label');
    expect($byLabel['masked-town']->rank)->toBe(6)
        ->and($byLabel['masked-town']->ranking_url)->toBe('https://google.com/goto?url=CAESaAHr')
        ->and($byLabel['masked-town']->top_results[0]['domain'])->toBe('yelp.com')   // the breadcrumb host replaces google.com
        ->and($byLabel['masked-town']->top_results[0]['masked'])->toBeTrue()
        ->and($byLabel['masked-town']->top_results[1]['domain'])->toBe('spg.com')
        ->and($byLabel['brand-town']->rank)->toBe(3)
        ->and($byLabel['brand-town']->ranking_url)->toBeNull()
        ->and($byLabel['phantom-a']->rank)->toBe(4)
        ->and($scan->fresh()->found_count)->toBe(3);
});
