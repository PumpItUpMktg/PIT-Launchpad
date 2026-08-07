<?php

namespace App\Analytics\Gsc;

use App\Models\GscUrlQueryMonthly;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rolls full-grain gsc_url_query_daily rows into the long-term monthly table
 * once they age past the retention window, then prunes the daily rows. Only
 * COMPLETE months older than the window are rolled — a month that could still
 * receive daily rows is left alone, so a monthly row is always computed from
 * the whole month in one pass (an idempotent upsert on the grain hash; a
 * re-run finds the daily rows already pruned and changes nothing).
 *
 * Monthly `position` is the IMPRESSION-WEIGHTED average across the month's
 * daily rows — Σ(position·impressions) / Σ(impressions), never a flat mean —
 * so the top-3 protection lane the lifecycle relay builds on isn't skewed by
 * low-impression days.
 */
class GscRollup
{
    private const UPSERT_CHUNK = 500;

    /**
     * @return array{months: int, monthly_rows: int, daily_pruned: int}
     */
    public function run(Site $site, ?int $retentionDays = null): array
    {
        $retention = $retentionDays ?? (int) config('launchpad.gsc.query_grain_retention_days', 180);
        // The first day of the month that contains the cutoff. Any month strictly
        // before this is fully elapsed and past retention → safe to roll.
        $cutoffMonthStart = Carbon::now()->subDays(max(0, $retention))->startOfMonth();

        $bounds = DB::table('gsc_url_query_daily')
            ->where('site_id', $site->id)
            ->where('date', '<', $cutoffMonthStart->toDateString())
            ->selectRaw('min(date) as min_date, max(date) as max_date')
            ->first();

        $minDate = $bounds->min_date ?? null;
        if ($minDate === null) {
            return ['months' => 0, 'monthly_rows' => 0, 'daily_pruned' => 0];
        }

        $cursor = Carbon::parse((string) $minDate)->startOfMonth();
        $months = 0;
        $monthlyRows = 0;
        $dailyPruned = 0;

        while ($cursor->lessThan($cutoffMonthStart)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth();

            $result = $this->rollMonth($site, $monthStart, $monthEnd);
            if ($result['daily_pruned'] > 0) {
                $months++;
                $monthlyRows += $result['monthly_rows'];
                $dailyPruned += $result['daily_pruned'];
            }

            $cursor->addMonth();
        }

        return ['months' => $months, 'monthly_rows' => $monthlyRows, 'daily_pruned' => $dailyPruned];
    }

    /**
     * @return array{monthly_rows: int, daily_pruned: int}
     */
    private function rollMonth(Site $site, Carbon $monthStart, Carbon $monthEnd): array
    {
        $now = Carbon::now();
        $monthKey = $monthStart->toDateString();

        $aggregates = DB::table('gsc_url_query_daily')
            ->where('site_id', $site->id)
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->groupBy('url', 'query', 'country', 'device')
            ->selectRaw('url, query, country, device')
            ->selectRaw('sum(impressions) as impressions')
            ->selectRaw('sum(clicks) as clicks')
            ->selectRaw('sum(position * impressions) as weighted_pos_sum')
            ->selectRaw('sum(case when position is not null then impressions else 0 end) as pos_weight')
            ->selectRaw('count(distinct date) as days_present')
            ->get();

        if ($aggregates->isEmpty()) {
            return ['monthly_rows' => 0, 'daily_pruned' => 0];
        }

        $buffer = [];
        $written = 0;
        foreach ($aggregates as $agg) {
            $posWeight = (float) $agg->pos_weight;
            $position = $posWeight > 0 ? round(((float) $agg->weighted_pos_sum) / $posWeight, 2) : null;

            $buffer[] = [
                'id' => (string) Str::ulid(),
                'site_id' => $site->id,
                'grain_hash' => Grain::hash([$site->id, $monthKey, (string) $agg->url, (string) $agg->query, (string) $agg->country, (string) $agg->device]),
                'month' => $monthKey,
                'url' => (string) $agg->url,
                'query' => (string) $agg->query,
                'country' => (string) $agg->country,
                'device' => (string) $agg->device,
                'impressions' => (int) $agg->impressions,
                'clicks' => (int) $agg->clicks,
                'position' => $position,
                'days_present' => (int) $agg->days_present,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $written++;

            if (count($buffer) >= self::UPSERT_CHUNK) {
                $this->flushMonthly($buffer);
                $buffer = [];
            }
        }
        $this->flushMonthly($buffer);

        $pruned = DB::table('gsc_url_query_daily')
            ->where('site_id', $site->id)
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->delete();

        return ['monthly_rows' => $written, 'daily_pruned' => $pruned];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function flushMonthly(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        GscUrlQueryMonthly::upsert(
            $rows,
            ['grain_hash'],
            ['impressions', 'clicks', 'position', 'days_present', 'updated_at'],
        );
    }
}
