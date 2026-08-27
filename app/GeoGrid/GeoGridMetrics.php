<?php

namespace App\GeoGrid;

use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\MetricSnapshot;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Derives a geo-grid scan's aggregates from its raw points and trends ATRP into metric_snapshots. Because
 * the raw {@see GeoGridPoint}s are the source of truth, correcting a formula is a RECOMPUTE, not
 * a rescan — which is what makes the §6 calibration loop cheap. The four definitions are treated as
 * UNVERIFIED until calibration (§5/§11): Local Falcon's exact handling of non-ranking points is the specific
 * thing likely to differ, and it's the difference between an ATRP of 8 and 14 on the same scan.
 *
 *   found_rate = % of points where the business appeared at all
 *   ARP        = mean rank across points where found (non-found EXCLUDED)
 *   ATRP       = mean rank across ALL points (non-found counted as depth_cap + 1)
 *   SoLV       = % of points ranked 1–3
 */
final class GeoGridMetrics
{
    /** metric_snapshots key for the trended geo-grid ATRP (per location × keyword, monthly). */
    public const ATRP_METRIC = 'geo_grid_atrp';

    /**
     * @param  iterable<object{rank: int|null}>  $points
     * @return array{found_rate: float|null, arp: float|null, atrp: float|null, solv: float|null}
     */
    public function compute(iterable $points, int $depthCap): array
    {
        $total = 0;
        $found = 0;
        $solv = 0;
        $sumFound = 0;
        $sumAll = 0;
        foreach ($points as $point) {
            $total++;
            $rank = $point->rank;
            if ($rank !== null) {
                $found++;
                $sumFound += $rank;
                $sumAll += $rank;
                if ($rank <= 3) {
                    $solv++;
                }
            } else {
                $sumAll += $depthCap + 1;   // non-found penalised to depth_cap + 1 for ATRP
            }
        }

        if ($total === 0) {
            return ['found_rate' => null, 'arp' => null, 'atrp' => null, 'solv' => null];
        }

        return [
            'found_rate' => round($found / $total * 100, 2),
            'arp' => $found > 0 ? round($sumFound / $found, 2) : null,
            'atrp' => round($sumAll / $total, 2),
            'solv' => round($solv / $total * 100, 2),
        ];
    }

    /** Recompute a scan's aggregates from its stored points (no rescan) and trend its ATRP. */
    public function recompute(GeoGridScan $scan): GeoGridScan
    {
        $metrics = $this->compute($scan->points()->get(['rank']), (int) $scan->depth_cap);

        // Coverage-mode scans get population-weighted variants — visibility where the customers actually are.
        if ($scan->mode === 'coverage') {
            $metrics += $this->populationWeighted($scan);
        }

        $scan->forceFill($metrics)->save();

        if ($metrics['atrp'] !== null) {
            $this->trendAtrp($scan, $metrics);
        }

        return $scan;
    }

    /**
     * Population-weighted found-rate + SoLV for a coverage scan: each town's contribution is its population,
     * so "we're top-3 in towns holding 60% of the served population" — the metric that actually maps to
     * revenue reach. Towns with unknown population (0) don't count toward the total.
     *
     * @return array{pop_found_rate: float|null, pop_solv: float|null}
     */
    private function populationWeighted(GeoGridScan $scan): array
    {
        $points = $scan->points()->get(['rank', 'coverage_area_id']);
        $ids = $points->pluck('coverage_area_id')->filter()->unique()->all();
        $populations = $ids === []
            ? collect()
            : CoverageArea::withoutGlobalScope(SiteScope::class)->whereIn('id', $ids)->pluck('population', 'id');

        $total = 0;
        $found = 0;
        $solv = 0;
        foreach ($points as $point) {
            $weight = (int) ($populations[$point->coverage_area_id] ?? 0);
            if ($weight <= 0) {
                continue;
            }
            $total += $weight;
            if ($point->rank !== null) {
                $found += $weight;
                if ((int) $point->rank <= 3) {
                    $solv += $weight;
                }
            }
        }

        if ($total === 0) {
            return ['pop_found_rate' => null, 'pop_solv' => null];
        }

        return [
            'pop_found_rate' => round($found / $total * 100, 2),
            'pop_solv' => round($solv / $total * 100, 2),
        ];
    }

    /**
     * Upsert the scan's ATRP into metric_snapshots — dimension_type=location, monthly grain, keyed per
     * (location × keyword) so each pair trends its own series. value_json carries the sibling metrics for
     * context. Idempotent on {@see MetricSnapshot::GRAIN_KEYS}.
     *
     * @param  array{found_rate: float|null, arp: float|null, atrp: float|null, solv: float|null}  $metrics
     */
    private function trendAtrp(GeoGridScan $scan, array $metrics): void
    {
        $now = Carbon::now();
        $period = ($scan->scanned_at ?? $now)->copy()->startOfMonth()->toDateString();

        DB::table('metric_snapshots')->upsert([[
            'id' => (string) Str::ulid(),
            'site_id' => $scan->site_id,
            'provider' => 'dataforseo',
            'metric_key' => self::ATRP_METRIC,
            'dimension_type' => 'location',
            'dimension_value' => $scan->location_id.':'.$scan->keyword_id,   // per location × keyword
            'period_grain' => 'month',
            'period_date' => $period,
            'value_numeric' => $metrics['atrp'],
            'value_json' => json_encode([
                'location_id' => $scan->location_id,
                'keyword_id' => $scan->keyword_id,
                'arp' => $metrics['arp'],
                'solv' => $metrics['solv'],
                'found_rate' => $metrics['found_rate'],
            ]),
            'captured_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]], MetricSnapshot::GRAIN_KEYS, ['value_numeric', 'value_json', 'captured_at', 'updated_at']);
    }
}
