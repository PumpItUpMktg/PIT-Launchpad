<?php

use App\Client\MonthlyKeywordReport;
use App\Enums\BeatabilityLane;
use App\Integrations\Google\SearchAnalyticsRow;
use App\Integrations\Google\SearchConsoleProvider;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\Site;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

/** Fake GSC range provider: canned monthly totals keyed by the window's start month. */
function bindMonthlyGsc(array $byMonth): void
{
    app()->instance(SearchConsoleProvider::class, new class($byMonth) implements SearchConsoleProvider
    {
        public function __construct(private array $byMonth) {}

        public function searchAnalytics(Site $site, DateTimeInterface $start, DateTimeInterface $end, array $dimensions = ['query'], int $rowLimit = 1000): array
        {
            $key = $start->format('Y-m');
            if (! isset($this->byMonth[$key])) {
                return [];
            }
            [$impr, $clicks] = $this->byMonth[$key];

            return [new SearchAnalyticsRow(keys: [], clicks: $clicks, impressions: $impr, ctr: 0.0, position: 0.0)];
        }
    });
}

function organicSnap(Site $site, Keyword $keyword, int $rank, string $on): void
{
    PositionSnapshot::factory()->create([
        'site_id' => $site->id, 'keyword_id' => $keyword->id, 'market_id' => null,
        'lane' => BeatabilityLane::Organic->value, 'rank' => $rank, 'captured_at' => Carbon::parse($on),
    ]);
}

it('buckets month-over-month organic movement by as-of-month rank', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 15));
    bindMonthlyGsc([]);
    $site = Site::factory()->create();

    $a = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'water heater repair']);
    organicSnap($site, $a, 15, '2026-05-20');
    organicSnap($site, $a, 8, '2026-06-20'); // 15 → 8: climbed + reached page one

    $b = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'drain cleaning']);
    organicSnap($site, $b, 5, '2026-06-20'); // newly ranking (no May snapshot) + page one

    $c = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump']);
    organicSnap($site, $c, 4, '2026-05-20');
    organicSnap($site, $c, 9, '2026-06-20'); // 4 → 9: slipped (still page one, so not a page-one gain)

    $report = app(MonthlyKeywordReport::class)->for($site, Carbon::create(2026, 6, 1));

    $p = $report['positions'];
    expect($report['month'])->toBe('2026-06')
        ->and(collect($p['improved'])->pluck('query')->all())->toBe(['water heater repair'])
        ->and($p['improved'][0]['delta'])->toBe(7)
        ->and(collect($p['new'])->pluck('query')->all())->toBe(['drain cleaning'])
        ->and(collect($p['page1'])->pluck('query')->sort()->values()->all())->toBe(['drain cleaning', 'water heater repair'])
        ->and(collect($p['declined'])->pluck('query')->all())->toBe(['sump pump'])
        ->and($p['summary'])->toMatchArray(['improved' => 1, 'new' => 1, 'page1' => 2, 'declined' => 1, 'tracked' => 3]);
});

it('lists the month\'s top Search Console queries (the long tail), most impressions first', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 15));
    $site = Site::factory()->create();

    // A range provider that answers the query-dimension pull with per-query rows.
    app()->instance(SearchConsoleProvider::class, new class implements SearchConsoleProvider
    {
        public function searchAnalytics(Site $site, DateTimeInterface $start, DateTimeInterface $end, array $dimensions = ['query'], int $rowLimit = 1000): array
        {
            if ($dimensions !== ['query']) {
                return [];
            }

            return [
                new SearchAnalyticsRow(keys: ['sump pump service norristown'], clicks: 3, impressions: 90, ctr: 0.0333, position: 5.1),
                new SearchAnalyticsRow(keys: ['sump pump repair'], clicks: 8, impressions: 240, ctr: 0.0333, position: 8.4),
            ];
        }
    });

    $queries = app(MonthlyKeywordReport::class)->for($site, Carbon::create(2026, 6, 1))['queries'];

    // Sorted by impressions desc; ctr converted to percent.
    expect($queries)->toHaveCount(2)
        ->and($queries[0]['query'])->toBe('sump pump repair')
        ->and($queries[0]['impressions'])->toBe(240)
        ->and($queries[0]['ctr'])->toBe(3.3)
        ->and($queries[1]['query'])->toBe('sump pump service norristown');
});

it('reports GSC reach as a month-over-month delta, and stays honest when unconnected', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 15));
    $site = Site::factory()->create();

    // June 1000 impr / 50 clicks; May 600 / 30.
    bindMonthlyGsc(['2026-06' => [1000, 50], '2026-05' => [600, 30]]);
    $search = app(MonthlyKeywordReport::class)->for($site, Carbon::create(2026, 6, 1))['search'];

    expect($search['connected'])->toBeTrue()
        ->and($search['this'])->toMatchArray(['impressions' => 1000, 'clicks' => 50])
        ->and($search['delta'])->toMatchArray(['impressions' => 400, 'clicks' => 20]);

    // A different site with no GSC data → not connected, no fabricated numbers.
    $unconnected = Site::factory()->create();
    bindMonthlyGsc([]);
    $none = app(MonthlyKeywordReport::class)->for($unconnected, Carbon::create(2026, 6, 1))['search'];
    expect($none['connected'])->toBeFalse()
        ->and($none['this']['impressions'])->toBe(0);
});
