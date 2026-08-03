<?php

namespace App\Client;

use App\Enums\BeatabilityLane;
use App\Integrations\Google\SearchConsoleProvider;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * The client's monthly keyword-improvement report: month-over-month organic movement (what climbed,
 * what newly ranked, what reached page one, what slipped) plus the month's Search Console reach
 * (impressions / clicks vs the prior month). §7c honest framing — observed movement only, no causal
 * or ROI claims; a metric shows only when its source has data, else it is simply absent.
 *
 * Movement is measured by each keyword's organic rank AS-OF the end of the report month vs as-of the
 * end of the prior month (the latest snapshot on/before each anchor). GSC totals come from the range
 * {@see SearchConsoleProvider} over the two month windows, cached so a page render / mail run doesn't
 * re-query GSC each time.
 */
class MonthlyKeywordReport
{
    public function __construct(
        private readonly SearchConsoleProvider $gsc,
        private readonly CacheRepository $cache,
        private readonly int $gscCacheTtl = 21600,
    ) {}

    /**
     * @return array{
     *   month: string,
     *   month_label: string,
     *   positions: array{
     *     improved: list<array{keyword_id: string, query: string, from: int|null, to: int|null, delta: int|null}>,
     *     new: list<array{keyword_id: string, query: string, from: int|null, to: int|null, delta: int|null}>,
     *     page1: list<array{keyword_id: string, query: string, from: int|null, to: int|null, delta: int|null}>,
     *     declined: list<array{keyword_id: string, query: string, from: int|null, to: int|null, delta: int|null}>,
     *     lost: list<array{keyword_id: string, query: string, from: int|null, to: int|null, delta: int|null}>,
     *     summary: array{improved: int, new: int, page1: int, declined: int, lost: int, tracked: int}
     *   },
     *   search: array{connected: bool, this: array{impressions: int, clicks: int}, prior: array{impressions: int, clicks: int}, delta: array{impressions: int, clicks: int}},
     *   queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>
     * }
     */
    public function for(Site $site, ?Carbon $month = null): array
    {
        $month = ($month ?? Carbon::now())->copy()->startOfMonth();
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $now = Carbon::now();
        if ($monthEnd->greaterThan($now)) {
            $monthEnd = $now; // current month → to-date
        }
        $priorStart = $month->copy()->subMonthNoOverflow()->startOfMonth();
        $priorEnd = $month->copy()->subMonthNoOverflow()->endOfMonth();

        return [
            'month' => $month->format('Y-m'),
            'month_label' => $month->format('F Y'),
            'positions' => $this->positions($site, $monthEnd, $priorEnd),
            'search' => $this->search($site, $monthStart, $monthEnd, $priorStart, $priorEnd),
            'queries' => $this->topQueries($site, $monthStart, $monthEnd),
        ];
    }

    /**
     * The month's top search queries from Search Console — the free, complete long tail the site was
     * found for (every geo / "near me" variant), most impressions first. This is the "all the long
     * tails in use" view that DataForSEO position tracking (geo-neutral silo keywords) doesn't cover.
     * Cached like the totals so a page render / mail run doesn't re-query GSC.
     *
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>
     */
    private function topQueries(Site $site, Carbon $start, Carbon $end, int $limit = 25): array
    {
        $key = 'gsc:queries:'.md5($site->id.'|'.$start->format('Y-m-d').'|'.$end->format('Y-m-d').'|'.$limit);

        /** @var list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}> */
        return $this->cache->remember($key, $this->gscCacheTtl, function () use ($site, $start, $end, $limit): array {
            $rows = $this->gsc->searchAnalytics($site, $start, $end, ['query'], $limit);

            $out = [];
            foreach ($rows as $row) {
                $query = (string) ($row->keys[0] ?? '');
                if ($query === '') {
                    continue;
                }
                $out[] = [
                    'query' => $query,
                    'clicks' => $row->clicks,
                    'impressions' => $row->impressions,
                    'ctr' => round($row->ctr * 100, 1),
                    'position' => round($row->position, 1),
                ];
            }

            usort($out, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

            return $out;
        });
    }

    /**
     * @return array{
     *   improved: list<array{keyword_id: string, query: string, from: int|null, to: int|null, delta: int|null}>,
     *   new: list<array{keyword_id: string, query: string, from: int|null, to: int|null, delta: int|null}>,
     *   page1: list<array{keyword_id: string, query: string, from: int|null, to: int|null, delta: int|null}>,
     *   declined: list<array{keyword_id: string, query: string, from: int|null, to: int|null, delta: int|null}>,
     *   lost: list<array{keyword_id: string, query: string, from: int|null, to: int|null, delta: int|null}>,
     *   summary: array{improved: int, new: int, page1: int, declined: int, lost: int, tracked: int}
     * }
     */
    private function positions(Site $site, Carbon $monthEnd, Carbon $priorEnd): array
    {
        // One pull of every organic snapshot on/before the report month, grouped per keyword.
        $byKeyword = PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('lane', BeatabilityLane::Organic->value)
            ->whereNull('market_id')
            ->where('captured_at', '<=', $monthEnd)
            ->orderBy('captured_at')
            ->get(['keyword_id', 'rank', 'captured_at'])
            ->groupBy('keyword_id');

        $queries = Keyword::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $byKeyword->keys()->all())
            ->pluck('query', 'id');

        $improved = $new = $page1 = $declined = $lost = [];

        foreach ($byKeyword as $keywordId => $snapshots) {
            $to = $snapshots->last()?->rank;                                   // latest on/before month end
            $prior = $snapshots->filter(fn (PositionSnapshot $s): bool => $s->captured_at !== null && $s->captured_at->lessThanOrEqualTo($priorEnd))->last();
            $from = $prior?->rank;

            $row = [
                'keyword_id' => (string) $keywordId,
                'query' => (string) ($queries[$keywordId] ?? ''),
                'from' => $from,
                'to' => $to,
                'delta' => ($from !== null && $to !== null) ? $from - $to : null, // + = climbed
            ];

            if ($from !== null && $to !== null && $to < $from) {
                $improved[] = $row;
            } elseif ($from === null && $to !== null) {
                $new[] = $row;
            } elseif ($from !== null && $to !== null && $to > $from) {
                $declined[] = $row;
            } elseif ($from !== null && $to === null) {
                $lost[] = $row;
            }

            // Page-one highlight (may overlap improved/new): reached the top 10 this month.
            if ($to !== null && $to <= 10 && ($from === null || $from > 10)) {
                $page1[] = $row;
            }
        }

        $sort = static function (array &$rows): void {
            usort($rows, fn (array $a, array $b): int => ($b['delta'] ?? PHP_INT_MIN) <=> ($a['delta'] ?? PHP_INT_MIN));
        };
        $sort($improved);
        $sort($new);
        $sort($page1);

        return [
            'improved' => $improved,
            'new' => $new,
            'page1' => $page1,
            'declined' => $declined,
            'lost' => $lost,
            'summary' => [
                'improved' => count($improved),
                'new' => count($new),
                'page1' => count($page1),
                'declined' => count($declined),
                'lost' => count($lost),
                'tracked' => $byKeyword->count(),
            ],
        ];
    }

    /**
     * @return array{connected: bool, this: array{impressions: int, clicks: int}, prior: array{impressions: int, clicks: int}, delta: array{impressions: int, clicks: int}}
     */
    private function search(Site $site, Carbon $monthStart, Carbon $monthEnd, Carbon $priorStart, Carbon $priorEnd): array
    {
        $this_ = $this->gscTotals($site, $monthStart, $monthEnd);
        $prior = $this->gscTotals($site, $priorStart, $priorEnd);

        return [
            'connected' => $this_['has_data'] || $prior['has_data'],
            'this' => ['impressions' => $this_['impressions'], 'clicks' => $this_['clicks']],
            'prior' => ['impressions' => $prior['impressions'], 'clicks' => $prior['clicks']],
            'delta' => [
                'impressions' => $this_['impressions'] - $prior['impressions'],
                'clicks' => $this_['clicks'] - $prior['clicks'],
            ],
        ];
    }

    /**
     * Aggregate GSC impressions + clicks for the site over one window (cached). `has_data` is false
     * when the source returned nothing — a not-yet-connected / not-yet-collecting month.
     *
     * @return array{impressions: int, clicks: int, has_data: bool}
     */
    private function gscTotals(Site $site, Carbon $start, Carbon $end): array
    {
        $key = 'gsc:month:'.md5($site->id.'|'.$start->format('Y-m-d').'|'.$end->format('Y-m-d'));

        /** @var array{impressions: int, clicks: int, has_data: bool} */
        return $this->cache->remember($key, $this->gscCacheTtl, function () use ($site, $start, $end): array {
            $rows = $this->gsc->searchAnalytics($site, $start, $end, [], 1);

            $impressions = 0;
            $clicks = 0;
            foreach ($rows as $row) {
                $impressions += $row->impressions;
                $clicks += $row->clicks;
            }

            return ['impressions' => $impressions, 'clicks' => $clicks, 'has_data' => $rows !== []];
        });
    }
}
