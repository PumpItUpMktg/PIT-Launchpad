<?php

use App\Integrations\Google\SearchAnalyticsRow;
use App\Integrations\Google\SearchConsoleProvider;
use App\Models\GscUrlQueryDaily;
use App\Models\GscUrlQueryMonthly;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * @param  list<SearchAnalyticsRow>  $urlRows
 * @param  list<SearchAnalyticsRow>  $queryRows
 */
function gscBackfillProvider(array $urlRows, array $queryRows): SearchConsoleProvider
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
                return [];
            }

            return $dimensions === ['date', 'page'] ? $this->urlRows : $this->queryRows;
        }
    };
}

it('backfills the full window, rolls aged rows to monthly, and reports the earliest available date', function () {
    $site = Site::factory()->create(['brand_name' => 'Backfill Co', 'gsc_property' => 'sc-domain:bf.example']);

    $old = Carbon::now()->subMonths(14)->startOfMonth()->day(10)->toDateString(); // past retention → rolls
    $recent = Carbon::now()->subDays(3)->toDateString();                          // inside window → stays daily

    app()->instance(SearchConsoleProvider::class, gscBackfillProvider(
        urlRows: [
            new SearchAnalyticsRow([$old, 'https://bf.example/a/'], clicks: 1, impressions: 100, ctr: 0.01, position: 9.0),
            new SearchAnalyticsRow([$recent, 'https://bf.example/a/'], clicks: 2, impressions: 200, ctr: 0.01, position: 4.0),
        ],
        queryRows: [
            new SearchAnalyticsRow([$old, 'https://bf.example/a/', 'a', 'usa', 'MOBILE'], clicks: 1, impressions: 100, ctr: 0.01, position: 9.0),
            new SearchAnalyticsRow([$recent, 'https://bf.example/a/', 'a', 'usa', 'MOBILE'], clicks: 2, impressions: 200, ctr: 0.01, position: 4.0),
        ],
    ));

    $code = Artisan::call('launchpad:backfill-gsc', ['--site' => 'Backfill Co']);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('recovered')
        ->and($output)->toContain($old); // earliest available surfaced

    // The aged query-grain row rolled into the monthly table; the recent one stays daily.
    expect(GscUrlQueryMonthly::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);

    $daily = GscUrlQueryDaily::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($daily)->toHaveCount(1)->and($daily->first()->date->toDateString())->toBe($recent);
});
