<?php

use App\Enums\BeatabilityLane;
use App\Jobs\SyncSiteMetrics;
use App\Metrics\MetricProviderRegistry;
use App\Metrics\Providers\DataForSeoMetricProvider;
use App\Models\Keyword;
use App\Models\MetricSnapshot;
use App\Models\PositionSnapshot;
use App\Models\Site;
use App\Support\CurrentSite;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Queue;

afterEach(function () {
    CurrentSite::clear();
});

function dfsOrganicSnap(Site $site, Keyword $kw, int $rank, string $capturedAt): void
{
    PositionSnapshot::factory()->create([
        'site_id' => $site->id, 'keyword_id' => $kw->id, 'lane' => BeatabilityLane::Organic,
        'rank' => $rank, 'market_id' => null, 'captured_at' => $capturedAt,
    ]);
}

/** @return array<string, MetricSnapshot> keyed "metric|dimType|dimValue|date". */
function rankSpine(Site $site): array
{
    $out = [];
    foreach (MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)->get() as $s) {
        $out[$s->metric_key.'|'.$s->dimension_type.'|'.$s->dimension_value.'|'.$s->period_date->toDateString()] = $s;
    }

    return $out;
}

it('rolls organic position snapshots up into per-keyword rank and site standings', function () {
    $site = Site::factory()->create();
    $k1 = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair']);
    $k2 = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'french drain']);
    $k3 = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'foundation crack']);

    // k1 improves 15 → (8 then 6 same day); two captures that day → the later one wins.
    dfsOrganicSnap($site, $k1, 15, now()->subDays(10)->setTime(9, 0)->toDateTimeString());
    dfsOrganicSnap($site, $k1, 8, now()->subDays(2)->setTime(10, 0)->toDateTimeString());
    dfsOrganicSnap($site, $k1, 6, now()->subDays(2)->setTime(14, 0)->toDateTimeString());
    dfsOrganicSnap($site, $k2, 2, now()->subDays(5)->toDateTimeString());
    dfsOrganicSnap($site, $k3, 25, now()->subDays(1)->toDateTimeString());
    // A local-pack sample must be ignored by this (organic) rollup.
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $k1->id, 'lane' => BeatabilityLane::LocalPack, 'rank' => 1, 'captured_at' => now()->toDateTimeString()]);

    (new DataForSeoMetricProvider)->sync($site, CarbonPeriod::create(now()->subDays(30)->toDateString(), now()->toDateString()));

    $spine = rankSpine($site);

    // Per-keyword rank rows: k1 has two days (only organic), k2 one, k3 one.
    $keywordRows = MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)->where('dimension_type', 'keyword')->get();
    expect($keywordRows)->toHaveCount(4);

    // Latest capture that day wins → k1's subDays(2) row is 6, not 8.
    $k1Day2 = "rank|keyword|{$k1->id}|".now()->subDays(2)->toDateString();
    expect((int) $spine[$k1Day2]->value_numeric)->toBe(6)
        ->and($spine[$k1Day2]->value_json)->toBe(['query' => 'sump pump repair']);

    // Site standings as-of the range end (carry-forward latest organic rank per keyword): k1=6, k2=2, k3=25.
    $end = now()->toDateString();
    $val = fn (string $key) => (int) $spine["$key|site||$end"]->value_numeric;
    expect($val('keywords_ranked'))->toBe(3)
        ->and($val('keywords_top3'))->toBe(1)     // only k2 (2)
        ->and($val('keywords_top10'))->toBe(2)    // k1 (6), k2 (2)
        ->and($val('keywords_top20'))->toBe(2);   // k3 (25) excluded
});

it('bounds per-keyword rows to the window but standings use full history', function () {
    $site = Site::factory()->create();
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'x']);

    dfsOrganicSnap($site, $kw, 12, now()->subDays(60)->toDateTimeString()); // outside a 7-day window
    dfsOrganicSnap($site, $kw, 4, now()->subDays(1)->toDateTimeString());   // inside

    (new DataForSeoMetricProvider)->sync($site, CarbonPeriod::create(now()->subDays(7)->toDateString(), now()->toDateString()));

    // Only the in-window day produced a per-keyword row…
    expect(MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)->where('dimension_type', 'keyword')->count())->toBe(1);

    // …but standings still see the keyword ranked (latest = 4 → ranked + top10, not top3).
    $end = now()->toDateString();
    $spine = rankSpine($site);
    expect((int) $spine["keywords_ranked|site||$end"]->value_numeric)->toBe(1)
        ->and((int) $spine["keywords_top10|site||$end"]->value_numeric)->toBe(1)
        ->and((int) $spine["keywords_top3|site||$end"]->value_numeric)->toBe(0);
});

it('is idempotent across re-runs', function () {
    $site = Site::factory()->create();
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'x']);
    dfsOrganicSnap($site, $kw, 9, now()->subDays(1)->toDateTimeString());

    $provider = new DataForSeoMetricProvider;
    $range = CarbonPeriod::create(now()->subDays(7)->toDateString(), now()->toDateString());
    $provider->sync($site, $range);
    $provider->sync($site, $range);

    expect(MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)->where('dimension_type', 'keyword')->count())->toBe(1);
});

it('registers dataforseo in the provider registry', function () {
    $registry = app(MetricProviderRegistry::class);

    expect($registry->has('dataforseo'))->toBeTrue()
        ->and($registry->get('dataforseo'))->toBeInstanceOf(DataForSeoMetricProvider::class);
});

it('sync-rankings dispatches a rankings sync per site', function () {
    Queue::fake();
    $site = Site::factory()->create();

    $this->artisan('sandhog:sync-rankings', ['site' => $site->id])->assertSuccessful();

    Queue::assertPushed(SyncSiteMetrics::class, fn (SyncSiteMetrics $j): bool => $j->provider === 'dataforseo' && $j->siteId === $site->id && $j->queue === 'metrics:dataforseo');
});
