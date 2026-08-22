<?php

namespace App\Metrics\Providers;

use App\Enums\BeatabilityLane;
use App\Metrics\Contracts\MetricProvider;
use App\Metrics\SyncResult;
use App\Models\MetricSnapshot;
use App\Models\Site;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rank-tracking slice of the metric spine (§ Client Dashboard v1, PR 4). Like the GSC provider it rolls
 * UP from a store we already fill — the §5 `position_snapshots` time-series (populated by the DataForSEO
 * SERP sweep) — rather than re-hitting DataForSEO. Pure, deterministic, re-runnable transform.
 *
 * It writes two things:
 *  - Per-keyword ORGANIC rank, one row per (keyword, day) in the range — the movement backbone the dashboard
 *    draws each keyword's trend from and derives "keywords improved" (PR 5/6) off. dimension_value is the
 *    keyword id (stable/joinable); the query text rides along in value_json.
 *  - Site-level derived standings AS OF the range end: keywords_ranked / keywords_top3 / _top10 / _top20,
 *    counted from each keyword's most-recent organic rank on or before the end date (carry-forward, so a
 *    keyword not sampled that day still counts at its last known rank). Stamped at the end date; run daily,
 *    this grows into the standings trend.
 *
 * Local-pack (per-market) series stay in `position_snapshots` for the existing §7c LocalGrid widget — not
 * duplicated into the spine in v1. Writes are idempotent upserts on {@see MetricSnapshot::GRAIN_KEYS}.
 */
class DataForSeoMetricProvider implements MetricProvider
{
    public const PROVIDER = 'dataforseo';

    private const UPSERT_CHUNK = 500;

    /** Standings bands the site-level rollup counts. */
    private const BANDS = ['keywords_top3' => 3, 'keywords_top10' => 10, 'keywords_top20' => 20];

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function sync(Site $site, CarbonPeriod $range): SyncResult
    {
        $start = Carbon::parse($range->getStartDate())->startOfDay();
        $end = Carbon::parse($range->getEndDate())->endOfDay();
        $now = Carbon::now();

        $queries = $this->keywordQueries($site);
        $upserts = [];

        // Per-keyword organic rank, one row per (keyword, day) in the range — latest capture that day wins.
        foreach ($this->organicRanksByKeywordDay($site, $start, $end) as $row) {
            $upserts[] = $this->keywordRow($site->id, $row['keyword_id'], $row['date'], (float) $row['rank'], $queries[$row['keyword_id']] ?? null, $now);
        }

        // Site-level standings as of the range end (carry-forward from each keyword's latest organic rank).
        $latest = $this->latestOrganicRankPerKeyword($site, $end);
        $ranked = count($latest);
        $bands = array_fill_keys(array_keys(self::BANDS), 0);
        foreach ($latest as $rank) {
            foreach (self::BANDS as $key => $threshold) {
                if ($rank <= $threshold) {
                    $bands[$key]++;
                }
            }
        }

        $endDate = $end->toDateString();
        $upserts[] = $this->siteRow($site->id, 'keywords_ranked', $endDate, (float) $ranked, $now);
        foreach ($bands as $key => $count) {
            $upserts[] = $this->siteRow($site->id, $key, $endDate, (float) $count, $now);
        }

        foreach (array_chunk($upserts, self::UPSERT_CHUNK) as $chunk) {
            DB::table('metric_snapshots')->upsert(
                $chunk,
                MetricSnapshot::GRAIN_KEYS,
                ['value_numeric', 'value_json', 'captured_at', 'updated_at'],
            );
        }

        return SyncResult::success(count($upserts));
    }

    /** keyword_id => query, for the value_json label. @return array<string, string> */
    private function keywordQueries(Site $site): array
    {
        return DB::table('keywords')->where('site_id', $site->id)->pluck('query', 'id')
            ->map(fn ($q): string => (string) $q)->all();
    }

    /**
     * The organic rank per (keyword, day) within the range — the latest capture on each day wins.
     *
     * @return list<array{keyword_id: string, date: string, rank: int}>
     */
    private function organicRanksByKeywordDay(Site $site, Carbon $start, Carbon $end): array
    {
        $latestByDay = []; // "keyword|date" => ['captured_at' => ts, 'rank' => int, ...]
        $cursor = DB::table('position_snapshots')
            ->where('site_id', $site->id)
            ->where('lane', BeatabilityLane::Organic->value)
            ->whereNotNull('rank')
            ->whereBetween('captured_at', [$start, $end])
            ->orderBy('captured_at')
            ->select(['keyword_id', 'rank', 'captured_at'])
            ->cursor();

        foreach ($cursor as $s) {
            $date = substr((string) $s->captured_at, 0, 10);
            // Ordered ascending, so the last write per (keyword,date) is the latest capture that day.
            $latestByDay[$s->keyword_id.'|'.$date] = ['keyword_id' => (string) $s->keyword_id, 'date' => $date, 'rank' => (int) $s->rank];
        }

        return array_values($latestByDay);
    }

    /**
     * Each keyword's most-recent organic rank on or before $asOf (carry-forward standings).
     *
     * @return array<string, int> keyword_id => rank
     */
    private function latestOrganicRankPerKeyword(Site $site, Carbon $asOf): array
    {
        $latest = [];
        $cursor = DB::table('position_snapshots')
            ->where('site_id', $site->id)
            ->where('lane', BeatabilityLane::Organic->value)
            ->whereNotNull('rank')
            ->where('captured_at', '<=', $asOf)
            ->orderBy('captured_at')
            ->select(['keyword_id', 'rank'])
            ->cursor();

        foreach ($cursor as $s) {
            $latest[(string) $s->keyword_id] = (int) $s->rank; // ascending → ends on the latest
        }

        return $latest;
    }

    /** @return array<string, mixed> */
    private function keywordRow(string $siteId, string $keywordId, string $date, float $rank, ?string $query, Carbon $now): array
    {
        return [
            'id' => (string) Str::ulid(),
            'site_id' => $siteId,
            'provider' => self::PROVIDER,
            'metric_key' => 'rank',
            'dimension_type' => 'keyword',
            'dimension_value' => $keywordId,
            'period_grain' => 'day',
            'period_date' => $date,
            'value_numeric' => $rank,
            'value_json' => $query !== null ? json_encode(['query' => $query]) : null,
            'captured_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array<string, mixed> */
    private function siteRow(string $siteId, string $metricKey, string $date, float $value, Carbon $now): array
    {
        return [
            'id' => (string) Str::ulid(),
            'site_id' => $siteId,
            'provider' => self::PROVIDER,
            'metric_key' => $metricKey,
            'dimension_type' => 'site',
            'dimension_value' => '',
            'period_grain' => 'day',
            'period_date' => $date,
            'value_numeric' => $value,
            'value_json' => null,
            'captured_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
