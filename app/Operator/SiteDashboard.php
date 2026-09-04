<?php

namespace App\Operator;

use App\Enums\IndexCoverageState;
use App\Models\Keyword;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The per-tenant operator dashboard read-model (Relay 3 · PR 4). Every metric card reads ONLY from
 * persisted state — the metric spine (`metric_snapshots`) plus the durable `page_vitals`,
 * `page_index_states`, and `keywords` tables — and NEVER calls a live provider (GSC / GA4 /
 * PageSpeed / DataForSEO) at render. That is a hard contract (acceptance 16): a metric card must be
 * a pure DB read, so the dashboard is instant and works offline of every vendor.
 *
 * The spine is populated out-of-band by the metric sync jobs (`app/Metrics/Providers/*`); this class
 * only reads what those have written. Summable traffic metrics (impressions / clicks / sessions) are
 * a trailing-28-day window; standings (index, rankings) are the latest value as of the newest data.
 *
 * Reads are scoped explicitly to the passed Site (drop {@see SiteScope} for determinism, mirroring the
 * client dashboard) so a card returns the same numbers regardless of the request's ambient tenant.
 */
class SiteDashboard
{
    /** Trailing window for summable traffic metrics (matches the client dashboard's momentum window). */
    private const WINDOW_DAYS = 28;

    /**
     * The eight metric cards, each a persisted read. Shape per card is stable so the Blade view is dumb:
     * `value` (the headline number, already formatted-friendly int/float), `label`, `sub` (context line),
     * and `empty` (true when nothing has been measured yet — the card renders a "no data yet" state).
     *
     * @return array{
     *   data_through: ?string,
     *   pagespeed: array{empty: bool, value: ?int, cwv_pass: int, measured: int},
     *   impressions: array{empty: bool, value: int, days: int},
     *   clicks: array{empty: bool, value: int, days: int},
     *   avg_position: array{empty: bool, value: ?float},
     *   sessions: array{empty: bool, value: int, days: int},
     *   indexed: array{empty: bool, value: int, known: int},
     *   not_indexed: array{empty: bool, value: int, reasons: list<array{label: string, count: int}>},
     *   keywords: array{empty: bool, value: int},
     *   rankings: array{empty: bool, value: int, top3: int, top10: int, top20: int}
     * }
     */
    public function metrics(Site $site): array
    {
        $end = Carbon::now();
        $windowStart = $end->copy()->subDays(self::WINDOW_DAYS - 1)->toDateString();
        $endDate = $end->toDateString();

        return [
            'data_through' => $this->dataThrough($site),
            'pagespeed' => $this->pagespeed($site),
            'impressions' => $this->siteSum($site, 'gsc', 'impressions', $windowStart, $endDate),
            'clicks' => $this->siteSum($site, 'gsc', 'clicks', $windowStart, $endDate),
            'avg_position' => $this->averagePosition($site, $windowStart, $endDate),
            'sessions' => $this->siteSum($site, 'ga4', 'sessions', $windowStart, $endDate),
            'indexed' => $this->indexed($site, $endDate),
            'not_indexed' => $this->notIndexed($site),
            'keywords' => $this->keywords($site),
            'rankings' => $this->rankings($site, $endDate),
        ];
    }

    /** The freshest `period_date` any provider has written for this site — the dashboard's "data through" stamp. */
    private function dataThrough(Site $site): ?string
    {
        $through = DB::table('metric_snapshots')->where('site_id', $site->id)->max('period_date');

        return $through !== null ? substr((string) $through, 0, 10) : null;
    }

    /**
     * Site speed from the durable `page_vitals` readings: median performance score across measured pages,
     * and how many pass Core Web Vitals. Empty until the PageSpeed sweep has run.
     *
     * @return array{empty: bool, value: ?int, cwv_pass: int, measured: int}
     */
    private function pagespeed(Site $site): array
    {
        $rows = DB::table('page_vitals')
            ->where('site_id', $site->id)->whereNotNull('measured_at')
            ->get(['performance_score', 'lcp_ms', 'cls', 'inp_ms']);

        if ($rows->isEmpty()) {
            return ['empty' => true, 'value' => null, 'cwv_pass' => 0, 'measured' => 0];
        }

        $scores = $rows->pluck('performance_score')->filter(fn ($s) => $s !== null)
            ->map(fn ($s): int => (int) $s)->sort()->values()->all();
        // Core Web Vitals pass: LCP ≤ 2.5s, CLS ≤ 0.1, INP ≤ 200ms (INP absent ⇒ not disqualifying).
        $pass = $rows->filter(fn ($r): bool => $r->lcp_ms !== null && $r->cls !== null
            && (int) $r->lcp_ms <= 2500 && (float) $r->cls <= 0.1
            && ($r->inp_ms === null || (int) $r->inp_ms <= 200))->count();

        return [
            'empty' => false,
            'value' => $scores === [] ? null : (int) round($this->median($scores)),
            'cwv_pass' => $pass,
            'measured' => $rows->count(),
        ];
    }

    /**
     * Site-level sum of a summable daily metric over the trailing window (GSC impressions/clicks, GA4
     * sessions all land as `dimension_type='site'`, `period_grain='day'`).
     *
     * @return array{empty: bool, value: int, days: int}
     */
    private function siteSum(Site $site, string $provider, string $metricKey, string $start, string $end): array
    {
        $rows = DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', $provider)->where('metric_key', $metricKey)
            ->where('dimension_type', 'site')->where('period_grain', 'day')
            ->whereBetween('period_date', [$start, $end])
            ->get(['value_numeric']);

        return [
            'empty' => $rows->isEmpty(),
            'value' => (int) round($rows->sum(fn ($r): float => (float) $r->value_numeric)),
            'days' => self::WINDOW_DAYS,
        ];
    }

    /**
     * Site average Search position over the window: impression-weighted mean of the per-page GSC position
     * rows (position is only stored per-page; the site figure is the weighted roll-up Google itself reports).
     *
     * @return array{empty: bool, value: ?float}
     */
    private function averagePosition(Site $site, string $start, string $end): array
    {
        // Position is stored per-page (impression-weighted per day). Key both series on (path|date) so
        // each position weights against its own day's impressions — the roll-up Google itself reports.
        $posByKey = DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', 'gsc')->where('metric_key', 'position')
            ->where('dimension_type', 'page')
            ->whereBetween('period_date', [$start, $end])
            ->get(['dimension_value', 'period_date', 'value_numeric'])
            ->mapWithKeys(fn ($r): array => [$r->dimension_value.'|'.$r->period_date => (float) $r->value_numeric]);

        $weighted = 0.0;
        $weight = 0.0;
        DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', 'gsc')->where('metric_key', 'impressions')
            ->where('dimension_type', 'page')
            ->whereBetween('period_date', [$start, $end])
            ->get(['dimension_value', 'period_date', 'value_numeric'])
            ->each(function ($r) use (&$weighted, &$weight, $posByKey): void {
                $pos = $posByKey->get($r->dimension_value.'|'.$r->period_date);
                if ($pos === null) {
                    return;
                }
                $impr = (float) $r->value_numeric;
                $weighted += $pos * $impr;
                $weight += $impr;
            });

        return [
            'empty' => $weight <= 0.0,
            'value' => $weight > 0.0 ? round($weighted / $weight, 1) : null,
        ];
    }

    /**
     * Indexed pages: the latest `pages_indexed` / `pages_known` site standings the index sync rolled up.
     *
     * @return array{empty: bool, value: int, known: int}
     */
    private function indexed(Site $site, string $asOf): array
    {
        $indexed = $this->siteValueAsOf($site, 'index', 'pages_indexed', $asOf);
        $known = $this->siteValueAsOf($site, 'index', 'pages_known', $asOf);

        return [
            'empty' => $indexed === null && $known === null,
            'value' => $indexed ?? 0,
            'known' => $known ?? 0,
        ];
    }

    /**
     * Not-indexed pages + reasons, from the durable per-URL `page_index_states`: every URL whose verdict
     * is not PASS, grouped by its normalized coverage state (redirect/canonical/blocked/crawled-not-indexed
     * …). Correct-by-design states (a 301 redirect source, a canonical duplicate) are still surfaced here as
     * reasons — the operator decides; the card is diagnostic, not a defect count.
     *
     * @return array{empty: bool, value: int, reasons: list<array{label: string, count: int}>}
     */
    private function notIndexed(Site $site): array
    {
        $states = PageIndexState::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->where('index_verdict', '!=', 'PASS')->orWhereNull('index_verdict'))
            ->get(['coverage_state']);

        if ($states->isEmpty()) {
            return ['empty' => true, 'value' => 0, 'reasons' => []];
        }

        $reasons = $states
            ->groupBy(fn ($s): string => (string) ($s->coverage_state ?? IndexCoverageState::NotInspected->value))
            ->map(fn ($group, string $state): array => [
                'label' => (IndexCoverageState::tryFrom($state) ?? IndexCoverageState::NotInspected)->label(),
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return ['empty' => false, 'value' => $states->count(), 'reasons' => $reasons];
    }

    /**
     * Tracked keyword count for the tenant (live rows only; Keyword does not soft-delete).
     *
     * @return array{empty: bool, value: int}
     */
    private function keywords(Site $site): array
    {
        $count = Keyword::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->count();

        return ['empty' => $count === 0, 'value' => $count];
    }

    /**
     * Ranking standings: the latest site-level DataForSEO roll-ups — keywords ranked at all, and the
     * top-3 / top-10 / top-20 bands.
     *
     * @return array{empty: bool, value: int, top3: int, top10: int, top20: int}
     */
    private function rankings(Site $site, string $asOf): array
    {
        $ranked = $this->siteValueAsOf($site, 'dataforseo', 'keywords_ranked', $asOf);
        $top3 = $this->siteValueAsOf($site, 'dataforseo', 'keywords_top3', $asOf);
        $top10 = $this->siteValueAsOf($site, 'dataforseo', 'keywords_top10', $asOf);
        $top20 = $this->siteValueAsOf($site, 'dataforseo', 'keywords_top20', $asOf);

        return [
            'empty' => $ranked === null,
            'value' => $ranked ?? 0,
            'top3' => $top3 ?? 0,
            'top10' => $top10 ?? 0,
            'top20' => $top20 ?? 0,
        ];
    }

    /** The latest site-level value for a metric on or before $asOf, or null when the spine has none. */
    private function siteValueAsOf(Site $site, string $provider, string $metricKey, string $asOf): ?int
    {
        $row = DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', $provider)->where('metric_key', $metricKey)
            ->where('dimension_type', 'site')->where('period_date', '<=', $asOf)
            ->orderByDesc('period_date')
            ->first(['value_numeric']);

        return $row === null ? null : (int) round((float) $row->value_numeric);
    }

    /** @param  list<int>  $sorted  ascending */
    private function median(array $sorted): float
    {
        $n = count($sorted);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }
}
