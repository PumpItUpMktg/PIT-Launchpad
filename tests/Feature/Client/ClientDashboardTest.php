<?php

use App\Client\Dashboard\ClientDashboard;
use App\Client\Dashboard\Frame;
use App\Client\Dashboard\LaunchWindow;
use App\Enums\AuditAction;
use App\Models\ClientMilestone;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

function cdSnap(Site $site, string $provider, string $metric, string $dimType, string $dimValue, string $date, float $value, ?array $json = null): void
{
    DB::table('metric_snapshots')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'provider' => $provider, 'metric_key' => $metric,
        'dimension_type' => $dimType, 'dimension_value' => $dimValue, 'period_grain' => 'day', 'period_date' => $date,
        'value_numeric' => $value, 'value_json' => $json !== null ? json_encode($json) : null,
        'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** A fixed frame for deterministic assertions. */
function cdFrame(string $start = '2026-01-01', string $end = '2026-08-31'): Frame
{
    return new Frame('since_launch', 'Since launch', Carbon::parse($start), Carbon::parse($end),
        Carbon::parse($start)->subMonths(1), Carbon::parse($start));
}

it('anchors the launch date on the go-live audit, else earliest spine data', function () {
    $site = Site::factory()->create();
    cdSnap($site, 'gsc', 'impressions', 'site', '', '2026-03-01', 5);

    // With only spine data, launch falls back to the earliest snapshot.
    expect(app(LaunchWindow::class)->launchDate($site)->toDateString())->toBe('2026-03-01');

    // A go-live audit row takes precedence.
    DB::table('audit_logs')->insert([
        'id' => (string) Str::ulid(), 'action' => AuditAction::SiteWentLive->value,
        'target_type' => $site->getMorphClass(), 'target_id' => $site->id, 'created_at' => '2026-02-10 09:00:00',
    ]);

    expect(app(LaunchWindow::class)->launchDate($site->fresh())->toDateString())->toBe('2026-02-10');
});

it('offers last_28 always and since_launch only once a launch anchor exists', function () {
    $site = Site::factory()->create();
    expect(array_keys(app(LaunchWindow::class)->frames($site)))->toBe(['last_28']);

    cdSnap($site, 'gsc', 'impressions', 'site', '', '2026-03-01', 5);
    expect(array_keys(app(LaunchWindow::class)->frames($site)))->toBe(['since_launch', 'last_28']);
});

it('builds the movement-first hero from the spine', function () {
    $site = Site::factory()->create();
    $frame = cdFrame(end: '2026-08-31');

    // pages working: 4 distinct pages with impressions in-frame; momentum recent(2) − previous(1) = +1.
    cdSnap($site, 'gsc', 'impressions', 'page', '/a', '2026-08-20', 10); // recent
    cdSnap($site, 'gsc', 'impressions', 'page', '/d', '2026-08-25', 4);  // recent
    cdSnap($site, 'gsc', 'impressions', 'page', '/b', '2026-07-15', 7);  // previous 28d
    cdSnap($site, 'gsc', 'impressions', 'page', '/c', '2026-03-01', 3);  // in-frame, older

    // keywords: kwA improves 20→8 (page-1), kwB newly ranked at 5 (page-1), kwC worsens 4→9.
    cdSnap($site, 'dataforseo', 'rank', 'keyword', 'kwA', '2025-12-01', 20, ['query' => 'a']);
    cdSnap($site, 'dataforseo', 'rank', 'keyword', 'kwA', '2026-08-20', 8, ['query' => 'a']);
    cdSnap($site, 'dataforseo', 'rank', 'keyword', 'kwB', '2026-08-10', 5, ['query' => 'b']);
    cdSnap($site, 'dataforseo', 'rank', 'keyword', 'kwC', '2025-12-15', 4, ['query' => 'c']); // baseline before frame
    cdSnap($site, 'dataforseo', 'rank', 'keyword', 'kwC', '2026-08-01', 9, ['query' => 'c']); // worsened, still page 1

    // pages added: indexed 52 as-of end, 43 as-of 28d earlier → +9; total 61.
    cdSnap($site, 'index', 'pages_indexed', 'site', '', '2026-08-03', 43);
    cdSnap($site, 'index', 'pages_indexed', 'site', '', '2026-08-31', 52);
    cdSnap($site, 'index', 'pages_known', 'site', '', '2026-08-31', 61);

    $hero = app(ClientDashboard::class)->hero($site, $frame);

    expect($hero['pages_working'])->toBe(['value' => 4, 'delta' => 1])
        ->and($hero['keywords_improved'])->toBe(['value' => 1, 'reached_page1' => 1])
        ->and($hero['pages_added'])->toBe(['indexed' => 52, 'total' => 61, 'delta' => 9]);
});

it('reports keyword standings as-of the end plus climbers and newly-ranked movers', function () {
    $site = Site::factory()->create();
    $frame = cdFrame(end: '2026-08-31');

    cdSnap($site, 'dataforseo', 'keywords_ranked', 'site', '', '2026-08-15', 30);
    cdSnap($site, 'dataforseo', 'keywords_top3', 'site', '', '2026-08-15', 5);
    cdSnap($site, 'dataforseo', 'keywords_top10', 'site', '', '2026-08-15', 12);
    cdSnap($site, 'dataforseo', 'keywords_top20', 'site', '', '2026-08-15', 22);
    cdSnap($site, 'dataforseo', 'rank', 'keyword', 'kwA', '2025-12-01', 20, ['query' => 'sump pump']);
    cdSnap($site, 'dataforseo', 'rank', 'keyword', 'kwA', '2026-08-20', 8, ['query' => 'sump pump']);
    cdSnap($site, 'dataforseo', 'rank', 'keyword', 'kwB', '2026-08-10', 5, ['query' => 'french drain']);

    $s = app(ClientDashboard::class)->standings($site, $frame);

    expect($s['ranked'])->toBe(30)->and($s['top3'])->toBe(5)->and($s['top10'])->toBe(12)->and($s['top20'])->toBe(22);

    // Biggest climber first (kwA jump 12), newly-ranked kwB surfaced with from=null.
    expect($s['movers'][0])->toBe(['query' => 'sump pump', 'from' => 20, 'to' => 8, 'jump' => 12, 'new' => false])
        ->and(collect($s['movers'])->firstWhere('query', 'french drain'))
        ->toBe(['query' => 'french drain', 'from' => null, 'to' => 5, 'jump' => 0, 'new' => true]);
});

it('carries the visibility series with milestone markers inside the frame', function () {
    $site = Site::factory()->create();
    $frame = cdFrame('2026-08-01', '2026-08-31');

    cdSnap($site, 'gsc', 'impressions', 'site', '', '2026-08-10', 100);
    cdSnap($site, 'gsc', 'clicks', 'site', '', '2026-08-10', 9);
    cdSnap($site, 'gsc', 'impressions', 'site', '', '2026-07-01', 999); // outside frame

    ClientMilestone::withoutGlobalScopes()->create(['site_id' => $site->id, 'key' => 'first_click', 'occurred_on' => '2026-08-10', 'is_client_visible' => true]);
    ClientMilestone::withoutGlobalScopes()->create(['site_id' => $site->id, 'key' => 'first_impression', 'occurred_on' => '2026-06-01', 'is_client_visible' => true]); // outside frame

    $v = app(ClientDashboard::class)->visibility($site, $frame);

    expect($v['series'])->toHaveCount(1)
        ->and($v['series'][0])->toBe(['date' => '2026-08-10', 'impressions' => 100, 'clicks' => 9])
        ->and($v['markers'])->toHaveCount(1)
        ->and($v['markers'][0]['label'])->toBe('First clicks from Google Search');
});

it('lists client-visible milestones, labeled and most-recent first', function () {
    $site = Site::factory()->create();
    ClientMilestone::withoutGlobalScopes()->create(['site_id' => $site->id, 'key' => 'first_page_indexed', 'occurred_on' => '2026-05-18', 'is_client_visible' => true]);
    ClientMilestone::withoutGlobalScopes()->create(['site_id' => $site->id, 'key' => 'blog_post_10', 'occurred_on' => '2026-07-09', 'payload' => ['count' => 10], 'is_client_visible' => true]);
    ClientMilestone::withoutGlobalScopes()->create(['site_id' => $site->id, 'key' => 'internal_note', 'occurred_on' => '2026-08-01', 'is_client_visible' => false]);

    $m = app(ClientDashboard::class)->milestones($site);

    expect($m)->toHaveCount(2) // the internal (non-visible) one is excluded
        ->and($m[0]['label'])->toBe('10 blog posts published')      // 2026-07-09, most recent
        ->and($m[1]['label'])->toBe('Google indexed your first page');
});

it('builds the indexed-vs-known trend over the frame', function () {
    $site = Site::factory()->create();
    $frame = cdFrame('2026-08-01', '2026-08-31');
    cdSnap($site, 'index', 'pages_indexed', 'site', '', '2026-08-05', 40);
    cdSnap($site, 'index', 'pages_known', 'site', '', '2026-08-05', 55);

    expect(app(ClientDashboard::class)->indexTrend($site, $frame))
        ->toBe([['date' => '2026-08-05', 'indexed' => 40, 'known' => 55]]);
});
