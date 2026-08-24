<?php

namespace App\Client\Dashboard;

use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * The client dashboard's Search-traffic read-model — the "how people found you" funnel, the site-level
 * ranking stats, the top queries, and the visits-vs-clicks trend (the okara-style Traffic surface).
 *
 * Sources, all already collected: site impressions/clicks and GA4 visits from the metric spine
 * (`metric_snapshots`, providers gsc/ga4); impression-weighted position + CTR from the never-overwritten
 * `gsc_url_daily`; and per-query clicks/impressions/CTR/position from `gsc_url_query_daily`. Honest by
 * construction — real GSC/GA4 counts and period-over-period deltas, no scores, no attribution. Deltas
 * compare the frame to its immediately-preceding equal-length window (Frame::prior*).
 */
class TrafficInsights
{
    /**
     * The impressions → clicks → visits funnel with period-over-period deltas and the click rate.
     *
     * @return array{
     *   impressions: array{value: int, delta_pct: ?float},
     *   clicks: array{value: int, delta_pct: ?float},
     *   visits: array{value: int, delta_pct: ?float, available: bool},
     *   click_rate: float
     * }
     */
    public function funnel(Site $site, Frame $frame): array
    {
        [$impr, $clicks] = $this->gscSiteTotals($site, $frame->startDate(), $frame->endDate());
        [$pImpr, $pClicks] = $this->gscSiteTotals($site, $frame->priorStart->toDateString(), $frame->priorEnd->toDateString());

        $visits = $this->ga4Visits($site, $frame->startDate(), $frame->endDate());
        $pVisits = $this->ga4Visits($site, $frame->priorStart->toDateString(), $frame->priorEnd->toDateString());

        return [
            'impressions' => ['value' => $impr, 'delta_pct' => $this->pct($impr, $pImpr)],
            'clicks' => ['value' => $clicks, 'delta_pct' => $this->pct($clicks, $pClicks)],
            'visits' => ['value' => $visits, 'delta_pct' => $this->pct($visits, $pVisits), 'available' => $this->hasGa4($site)],
            'click_rate' => $impr > 0 ? round($clicks / $impr * 100, 1) : 0.0,
        ];
    }

    /**
     * Site-level ranking stats over the frame from GSC's own blended data: impression-weighted average
     * position, click-through rate, and total clicks — each with a period-over-period delta. Position's
     * delta is positions gained (prior − current), so a positive delta always means "improved".
     *
     * @return array{
     *   avg_position: array{value: ?float, delta: ?float},
     *   ctr: array{value: float, delta_pct: ?float},
     *   clicks: array{value: int, delta_pct: ?float}
     * }
     */
    public function rankingStats(Site $site, Frame $frame): array
    {
        $now = $this->gscDailyAgg($site, $frame->startDate(), $frame->endDate());
        $prior = $this->gscDailyAgg($site, $frame->priorStart->toDateString(), $frame->priorEnd->toDateString());

        $positionDelta = ($now['position'] !== null && $prior['position'] !== null)
            ? round($prior['position'] - $now['position'], 1)   // positions gained: + = moved up
            : null;

        return [
            'avg_position' => ['value' => $now['position'], 'delta' => $positionDelta],
            'ctr' => ['value' => $now['ctr'], 'delta_pct' => $this->pct($now['ctr'], $prior['ctr'])],
            'clicks' => ['value' => $now['clicks'], 'delta_pct' => $this->pct($now['clicks'], $prior['clicks'])],
        ];
    }

    /**
     * The queries bringing the most Search traffic over the frame — clicks, impressions, CTR and
     * impression-weighted position per query, most-clicked first (impressions break ties for a young
     * site with no clicks yet).
     *
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: ?float}>
     */
    public function topQueries(Site $site, Frame $frame, int $limit = 10): array
    {
        return DB::table('gsc_url_query_daily')
            ->where('site_id', $site->id)
            ->whereBetween('date', [$frame->startDate(), $frame->endDate()])
            ->where('query', '!=', '')
            ->groupBy('query')
            ->selectRaw('query, sum(clicks) as clicks, sum(impressions) as impressions, '
                .'sum(position * impressions) as posw, sum(case when position is not null then impressions else 0 end) as impr_pos')
            ->orderByRaw('sum(clicks) desc, sum(impressions) desc')
            ->limit($limit)
            ->get()
            ->map(function ($r): array {
                $impr = (int) $r->impressions;
                $clicks = (int) $r->clicks;

                return [
                    'query' => (string) $r->query,
                    'clicks' => $clicks,
                    'impressions' => $impr,
                    'ctr' => $impr > 0 ? round($clicks / $impr * 100, 1) : 0.0,
                    'position' => ((float) $r->impr_pos) > 0 ? round((float) $r->posw / (float) $r->impr_pos, 1) : null,
                ];
            })
            ->all();
    }

    /**
     * Daily visits (GA4) vs Search clicks (GSC) over the frame — the "traffic over time" trend.
     *
     * @return list<array{date: string, visits: int, clicks: int}>
     */
    public function trafficSeries(Site $site, Frame $frame): array
    {
        $clicks = $this->siteDailySeries($site, 'gsc', 'clicks', $frame);
        $visits = $this->siteDailySeries($site, 'ga4', 'sessions', $frame);

        $out = [];
        foreach (array_keys($visits + $clicks) as $date) {
            $out[$date] = ['date' => $date, 'visits' => (int) ($visits[$date] ?? 0), 'clicks' => (int) ($clicks[$date] ?? 0)];
        }
        ksort($out);

        return array_values($out);
    }

    // ---- internals -------------------------------------------------------

    /** @return array{0: int, 1: int} [impressions, clicks] site totals over [start,end] from the spine. */
    private function gscSiteTotals(Site $site, string $start, string $end): array
    {
        $rows = DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', 'gsc')->where('dimension_type', 'site')
            ->whereIn('metric_key', ['impressions', 'clicks'])
            ->whereBetween('period_date', [$start, $end])
            ->selectRaw('metric_key, sum(value_numeric) as v')->groupBy('metric_key')
            ->pluck('v', 'metric_key');

        return [(int) round((float) ($rows['impressions'] ?? 0)), (int) round((float) ($rows['clicks'] ?? 0))];
    }

    private function ga4Visits(Site $site, string $start, string $end): int
    {
        return (int) round((float) DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', 'ga4')->where('metric_key', 'sessions')
            ->where('dimension_type', 'site')->whereBetween('period_date', [$start, $end])
            ->sum('value_numeric'));
    }

    private function hasGa4(Site $site): bool
    {
        return DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', 'ga4')->where('metric_key', 'sessions')
            ->exists();
    }

    /** @return array{impressions: int, clicks: int, ctr: float, position: ?float} */
    private function gscDailyAgg(Site $site, string $start, string $end): array
    {
        $r = DB::table('gsc_url_daily')
            ->where('site_id', $site->id)->whereBetween('date', [$start, $end])
            ->selectRaw('sum(impressions) as impr, sum(clicks) as clicks, '
                .'sum(position * impressions) as posw, sum(case when position is not null then impressions else 0 end) as impr_pos')
            ->first();

        $impr = (int) ($r->impr ?? 0);
        $clicks = (int) ($r->clicks ?? 0);
        $imprPos = (float) ($r->impr_pos ?? 0);

        return [
            'impressions' => $impr,
            'clicks' => $clicks,
            'ctr' => $impr > 0 ? round($clicks / $impr * 100, 2) : 0.0,
            'position' => $imprPos > 0 ? round((float) $r->posw / $imprPos, 1) : null,
        ];
    }

    /** @return array<string, float> date => value over the frame from the spine. */
    private function siteDailySeries(Site $site, string $provider, string $metricKey, Frame $frame): array
    {
        return DB::table('metric_snapshots')
            ->where('site_id', $site->id)->where('provider', $provider)->where('metric_key', $metricKey)
            ->where('dimension_type', 'site')->where('period_grain', 'day')
            ->whereBetween('period_date', [$frame->startDate(), $frame->endDate()])
            ->orderBy('period_date')
            ->get(['period_date', 'value_numeric'])
            ->mapWithKeys(fn ($r): array => [substr((string) $r->period_date, 0, 10) => (float) $r->value_numeric])
            ->all();
    }

    private function pct(float $current, float $prior): ?float
    {
        return $prior > 0 ? round(($current - $prior) / $prior * 100, 1) : null;
    }
}
