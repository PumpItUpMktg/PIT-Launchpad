<?php

use App\Analytics\Gsc\Grain;
use App\Analytics\Gsc\GscRollup;
use App\Analytics\Gsc\GscSnapshotIngestor;
use App\Integrations\Google\SearchAnalyticsRow;
use App\Integrations\Google\SearchConsoleProvider;
use App\Models\GscUrlDaily;
use App\Models\GscUrlQueryDaily;
use App\Models\GscUrlQueryMonthly;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A provider that answers the URL grain ([date,page]) and the URL×query grain
 * ([date,page,query,country,device]) with distinct programmed rows, so ingest
 * parsing is exercised precisely per grain.
 *
 * @param  list<SearchAnalyticsRow>  $urlRows
 * @param  list<SearchAnalyticsRow>  $queryRows
 */
function gscProvider(array $urlRows, array $queryRows): SearchConsoleProvider
{
    return new class($urlRows, $queryRows) implements SearchConsoleProvider
    {
        /**
         * @param  list<SearchAnalyticsRow>  $urlRows
         * @param  list<SearchAnalyticsRow>  $queryRows
         */
        public function __construct(private array $urlRows, private array $queryRows) {}

        public function searchAnalytics(Site $site, DateTimeInterface $start, DateTimeInterface $end, array $dimensions = ['query'], int $rowLimit = 1000, int $startRow = 0): array
        {
            if ($startRow > 0) {
                return []; // single page in tests
            }

            return $dimensions === ['date', 'page'] ? $this->urlRows : $this->queryRows;
        }
    };
}

function gscSite(): Site
{
    return Site::factory()->create(['brand_name' => 'GSC Co', 'gsc_property' => 'sc-domain:gsc.example']);
}

it('ingests both grains and parses the dimension keys per grain', function () {
    $site = gscSite();
    $d = Carbon::now()->subDays(2)->toDateString();

    $ingestor = new GscSnapshotIngestor(gscProvider(
        urlRows: [new SearchAnalyticsRow([$d, 'https://gsc.example/sump-pump-repair/'], clicks: 5, impressions: 200, ctr: 0.025, position: 4.2)],
        queryRows: [
            new SearchAnalyticsRow([$d, 'https://gsc.example/sump-pump-repair/', 'sump pump repair', 'usa', 'MOBILE'], clicks: 3, impressions: 120, ctr: 0.025, position: 3.0),
            new SearchAnalyticsRow([$d, 'https://gsc.example/sump-pump-repair/', 'sump pump repair', 'usa', 'DESKTOP'], clicks: 2, impressions: 80, ctr: 0.025, position: 6.0),
        ],
    ));

    $out = $ingestor->sync($site);

    expect($out['url_daily'])->toBe(1)->and($out['url_query_daily'])->toBe(2);

    $url = GscUrlDaily::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->sole();
    expect($url->url)->toBe('https://gsc.example/sump-pump-repair/')
        ->and((int) $url->impressions)->toBe(200)
        ->and((float) $url->position)->toBe(4.2);

    $mobile = GscUrlQueryDaily::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('device', 'MOBILE')->sole();
    expect($mobile->query)->toBe('sump pump repair')->and($mobile->country)->toBe('usa')->and((int) $mobile->impressions)->toBe(120);
});

it('stores a very long GSC query (over the old 512-char limit) without truncating or crashing', function () {
    $site = gscSite();
    $d = Carbon::now()->subDays(2)->toDateString();
    $longQuery = str_repeat('best sump pump for a residential basement ', 25); // ~1050 chars

    (new GscSnapshotIngestor(gscProvider(
        urlRows: [new SearchAnalyticsRow([$d, 'https://gsc.example/p/'], clicks: 1, impressions: 10, ctr: 0.1, position: 5.0)],
        queryRows: [new SearchAnalyticsRow([$d, 'https://gsc.example/p/', $longQuery, 'usa', 'DESKTOP'], clicks: 1, impressions: 10, ctr: 0.1, position: 5.0)],
    )))->sync($site);

    $row = GscUrlQueryDaily::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->sole();
    expect($row->query)->toBe($longQuery)->and(strlen($row->query))->toBeGreaterThan(512);
});

it('is idempotent — re-syncing an already-pulled window upserts in place, never doubling', function () {
    $site = gscSite();
    $d = Carbon::now()->subDays(2)->toDateString();

    // First pull.
    (new GscSnapshotIngestor(gscProvider(
        urlRows: [new SearchAnalyticsRow([$d, 'https://gsc.example/a/'], clicks: 1, impressions: 100, ctr: 0.01, position: 8.0)],
        queryRows: [new SearchAnalyticsRow([$d, 'https://gsc.example/a/', 'a', 'usa', 'MOBILE'], clicks: 1, impressions: 100, ctr: 0.01, position: 8.0)],
    )))->sync($site);

    // Second pull of the SAME window with GSC-revised metrics (the trailing re-pull).
    (new GscSnapshotIngestor(gscProvider(
        urlRows: [new SearchAnalyticsRow([$d, 'https://gsc.example/a/'], clicks: 4, impressions: 150, ctr: 0.0266, position: 5.5)],
        queryRows: [new SearchAnalyticsRow([$d, 'https://gsc.example/a/', 'a', 'usa', 'MOBILE'], clicks: 4, impressions: 150, ctr: 0.0266, position: 5.5)],
    )))->sync($site);

    expect(GscUrlDaily::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1)
        ->and(GscUrlQueryDaily::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);

    $url = GscUrlDaily::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->sole();
    expect((int) $url->impressions)->toBe(150)->and((float) $url->position)->toBe(5.5); // revised values won
});

it('rolls a month past retention into an impression-weighted monthly row and prunes the daily rows', function () {
    $site = gscSite();
    $month = Carbon::now()->subMonths(14)->startOfMonth();

    $insert = function (string $date, int $impressions, float $position) use ($site): void {
        DB::table('gsc_url_query_daily')->insert([
            'id' => (string) Str::ulid(),
            'site_id' => $site->id,
            'grain_hash' => Grain::hash([$site->id, $date, 'https://gsc.example/p/', 'q', 'usa', 'MOBILE']),
            'date' => $date,
            'url' => 'https://gsc.example/p/',
            'query' => 'q',
            'country' => 'usa',
            'device' => 'MOBILE',
            'impressions' => $impressions,
            'clicks' => 0,
            'ctr' => 0,
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    // Same grain, two days in the old month: (2·100 + 6·300)/400 = 5.0 weighted.
    $insert($month->copy()->day(5)->toDateString(), 100, 2.0);
    $insert($month->copy()->day(6)->toDateString(), 300, 6.0);
    // A recent row inside the retention window — must survive.
    $recent = Carbon::now()->subDays(5)->toDateString();
    $insert($recent, 50, 1.0);

    $out = (new GscRollup)->run($site);

    expect($out['months'])->toBe(1)->and($out['daily_pruned'])->toBe(2);

    $monthly = GscUrlQueryMonthly::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->sole();
    expect((int) $monthly->impressions)->toBe(400)
        ->and((float) $monthly->position)->toBe(5.0)
        ->and((int) $monthly->days_present)->toBe(2);

    // The old daily rows are gone; the recent one remains.
    $remaining = GscUrlQueryDaily::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($remaining)->toHaveCount(1)->and($remaining->first()->date->toDateString())->toBe($recent);
});
