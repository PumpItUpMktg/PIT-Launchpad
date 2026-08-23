<?php

use App\Client\Dashboard\Frame;
use App\Client\Dashboard\TrafficInsights;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

/** Frame 2026-08-15..21, prior week 2026-08-08..14. */
function tiFrame(): Frame
{
    return new Frame('f', 'Frame',
        Carbon::parse('2026-08-15'), Carbon::parse('2026-08-21'),
        Carbon::parse('2026-08-08'), Carbon::parse('2026-08-14'));
}

function tiSnap(Site $site, string $provider, string $metric, string $date, float $value): void
{
    DB::table('metric_snapshots')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'provider' => $provider, 'metric_key' => $metric,
        'dimension_type' => 'site', 'dimension_value' => '', 'period_grain' => 'day', 'period_date' => $date,
        'value_numeric' => $value, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function tiUrlDaily(Site $site, string $date, string $url, int $impr, int $clicks, ?float $position): void
{
    DB::table('gsc_url_daily')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => hash('sha256', $site->id.$date.$url),
        'date' => $date, 'url' => $url, 'impressions' => $impr, 'clicks' => $clicks,
        'ctr' => $impr > 0 ? $clicks / $impr : 0, 'position' => $position, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function tiQueryDaily(Site $site, string $date, string $query, int $impr, int $clicks, ?float $position): void
{
    DB::table('gsc_url_query_daily')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id,
        'grain_hash' => hash('sha256', $site->id.$date.$query), 'date' => $date, 'url' => 'https://x/', 'query' => $query,
        'country' => 'usa', 'device' => 'MOBILE', 'impressions' => $impr, 'clicks' => $clicks,
        'ctr' => $impr > 0 ? $clicks / $impr : 0, 'position' => $position, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('builds the impressions → clicks → visits funnel with period deltas and click rate', function () {
    $site = Site::factory()->create();
    // in-frame
    tiSnap($site, 'gsc', 'impressions', '2026-08-15', 100);
    tiSnap($site, 'gsc', 'impressions', '2026-08-18', 200);
    tiSnap($site, 'gsc', 'clicks', '2026-08-15', 10);
    tiSnap($site, 'gsc', 'clicks', '2026-08-18', 14);
    tiSnap($site, 'ga4', 'sessions', '2026-08-15', 5);
    tiSnap($site, 'ga4', 'sessions', '2026-08-18', 25);
    // prior
    tiSnap($site, 'gsc', 'impressions', '2026-08-10', 150);
    tiSnap($site, 'gsc', 'clicks', '2026-08-10', 12);
    tiSnap($site, 'ga4', 'sessions', '2026-08-10', 10);

    $f = app(TrafficInsights::class)->funnel($site, tiFrame());

    expect($f['impressions'])->toBe(['value' => 300, 'delta_pct' => 100.0])
        ->and($f['clicks'])->toBe(['value' => 24, 'delta_pct' => 100.0])
        ->and($f['visits'])->toBe(['value' => 30, 'delta_pct' => 200.0, 'available' => true])
        ->and($f['click_rate'])->toBe(8.0);
});

it('marks visits unavailable when GA4 has produced no sessions', function () {
    $site = Site::factory()->create();
    tiSnap($site, 'gsc', 'impressions', '2026-08-15', 100);

    expect(app(TrafficInsights::class)->funnel($site, tiFrame())['visits']['available'])->toBeFalse();
});

it('computes site ranking stats: impression-weighted position, CTR, clicks, with deltas', function () {
    $site = Site::factory()->create();
    tiUrlDaily($site, '2026-08-15', 'https://x/a', 100, 10, 20.0);
    tiUrlDaily($site, '2026-08-18', 'https://x/b', 200, 14, 30.0);
    tiUrlDaily($site, '2026-08-10', 'https://x/a', 150, 12, 40.0); // prior

    $r = app(TrafficInsights::class)->rankingStats($site, tiFrame());

    expect($r['avg_position']['value'])->toBe(26.7)              // (20*100 + 30*200) / 300
        ->and($r['avg_position']['delta'])->toBe(13.3)           // 40 (prior) − 26.7 = improved
        ->and($r['ctr']['value'])->toBe(8.0)
        ->and($r['clicks'])->toBe(['value' => 24, 'delta_pct' => 100.0]);
});

it('lists the top queries by clicks with CTR and position', function () {
    $site = Site::factory()->create();
    tiQueryDaily($site, '2026-08-15', 'sump pump gurus', 19, 8, 3.0);
    tiQueryDaily($site, '2026-08-16', 'best sump pump for crawl space', 13, 1, 55.0);
    tiQueryDaily($site, '2026-08-02', 'out of frame', 999, 500, 1.0); // outside the frame → excluded

    $q = app(TrafficInsights::class)->topQueries($site, tiFrame());

    expect($q)->toHaveCount(2)
        ->and($q[0])->toBe(['query' => 'sump pump gurus', 'clicks' => 8, 'impressions' => 19, 'ctr' => 42.1, 'position' => 3.0])
        ->and($q[1]['query'])->toBe('best sump pump for crawl space')
        ->and($q[1]['ctr'])->toBe(7.7);
});

it('builds the daily visits-vs-clicks trend from the spine', function () {
    $site = Site::factory()->create();
    tiSnap($site, 'gsc', 'clicks', '2026-08-15', 10);
    tiSnap($site, 'gsc', 'clicks', '2026-08-18', 14);
    tiSnap($site, 'ga4', 'sessions', '2026-08-15', 5);
    tiSnap($site, 'ga4', 'sessions', '2026-08-18', 25);

    expect(app(TrafficInsights::class)->trafficSeries($site, tiFrame()))->toBe([
        ['date' => '2026-08-15', 'visits' => 5, 'clicks' => 10],
        ['date' => '2026-08-18', 'visits' => 25, 'clicks' => 14],
    ]);
});
