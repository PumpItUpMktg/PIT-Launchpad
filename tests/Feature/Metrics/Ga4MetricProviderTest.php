<?php

use App\Integrations\Analytics\SiteTrafficProvider;
use App\Jobs\SyncSiteMetrics;
use App\Metrics\MetricProviderRegistry;
use App\Metrics\Providers\Ga4MetricProvider;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Support\CurrentSite;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

afterEach(function () {
    CurrentSite::clear();
});

/** A deterministic site-traffic source — no GA4 HTTP. */
function fakeSiteTraffic(?array $daily, bool $connected = true): SiteTrafficProvider
{
    return new class($daily, $connected) implements SiteTrafficProvider
    {
        public function __construct(private ?array $daily, private bool $connected) {}

        public function connected(Site $site): bool
        {
            return $this->connected;
        }

        public function dailySessions(Site $site, Carbon $start, Carbon $end): ?array
        {
            return $this->daily;
        }
    };
}

it('rolls GA4 daily sessions into site-level spine snapshots', function () {
    $site = Site::factory()->create();

    $result = (new Ga4MetricProvider(fakeSiteTraffic([
        '2026-08-01' => 12,
        '2026-08-02' => 0,   // a real zero-session day is kept
        '2026-08-03' => 40,
    ])))->sync($site, CarbonPeriod::create('2026-08-01', '2026-08-03'));

    expect($result->status)->toBe('success')->and($result->rowsWritten)->toBe(3);

    $snap = fn (string $date) => MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)
        ->where('provider', 'ga4')->where('metric_key', 'sessions')->where('dimension_type', 'site')
        ->where('period_date', $date)->value('value_numeric');

    expect((int) $snap('2026-08-01'))->toBe(12)
        ->and((int) $snap('2026-08-02'))->toBe(0)
        ->and((int) $snap('2026-08-03'))->toBe(40);
});

it('is idempotent — a re-run updates in place, never duplicates', function () {
    $site = Site::factory()->create();
    $range = CarbonPeriod::create('2026-08-01', '2026-08-01');

    (new Ga4MetricProvider(fakeSiteTraffic(['2026-08-01' => 5])))->sync($site, $range);
    (new Ga4MetricProvider(fakeSiteTraffic(['2026-08-01' => 9])))->sync($site, $range);

    expect(MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)->where('provider', 'ga4')->count())->toBe(1)
        ->and((int) MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)->where('provider', 'ga4')->value('value_numeric'))->toBe(9);
});

it('is a clean no-op when GA4 is not connected', function () {
    $site = Site::factory()->create();

    $result = (new Ga4MetricProvider(fakeSiteTraffic(null, connected: false)))
        ->sync($site, CarbonPeriod::create('2026-08-01', '2026-08-03'));

    expect($result->rowsWritten)->toBe(0)
        ->and(MetricSnapshot::withoutGlobalScopes()->where('provider', 'ga4')->count())->toBe(0);
});

it('registers ga4 in the provider registry and dispatches onto its own queue', function () {
    expect(app(MetricProviderRegistry::class)->has('ga4'))->toBeTrue()
        ->and(SyncSiteMetrics::queueFor('ga4'))->toBe('metrics:ga4');

    Queue::fake();
    $site = Site::factory()->create();
    SyncSiteMetrics::dispatch($site->id, 'ga4', '2026-08-01', '2026-08-31');
    Queue::assertPushed(SyncSiteMetrics::class, fn (SyncSiteMetrics $j): bool => $j->provider === 'ga4' && $j->queue === 'metrics:ga4');
});
