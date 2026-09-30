<?php

namespace App\TownRank;

use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankScan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Town Visibility (§ Town Rank): ONE number, 0–100, for "how much of the territory's search demand can see
 * this site" — per keyword and mode, and for the site as a whole. Each town scores by where the site ranks
 * there (top-3 full credit, page 1 most, page 2 a little, beyond / not found none) and towns are weighted by
 * population, so ranking in the county seat counts for more than a hamlet nobody searches from. The report's
 * ▲▼ movement counts remain the detail underneath; this is the headline the client reads and the trend it
 * moves along week to week.
 *
 * Movement is measured against a BASELINE of the previous two finished scans (their mean), not the last one
 * alone: long-tail town queries bounce week to week, and a single-scan delta reads the bounce as a trend.
 *
 * Honest framing: observed rankings and their movement only — no traffic, lead or revenue claim is derived.
 */
final class TownVisibility
{
    /** Credit per rank band — a #1 and a #3 are both "found first"; page 1 is the goal; page 2 is a foothold. */
    public const CREDIT = ['top3' => 1.0, 'page1' => 0.7, 'page2' => 0.3];

    /** Finalized scans considered for the site trend, per keyword and mode (weekly cadence → ~3 months). */
    public const HISTORY_SCANS = 12;

    /** Previous finished scans averaged into the movement baseline. */
    public const BASELINE_SCANS = 2;

    private const CACHE_SECONDS = 1800;

    /**
     * Score one report row-set for a mode — the population-weighted score and page-1 / top-3 counts of the
     * rows as rendered. Kept for callers that already hold report rows; the board and the portal use
     * {@see forKeyword()}, which never touches the report.
     *
     * @param  list<array<string, mixed>>  $rows  {@see TownRankReport::forKeyword()} rows
     * @param  'town'|'local'  $prefix
     * @return array{score: int|null, towns: int, page1_towns: int, top3_towns: int}
     */
    public function scoreRows(array $rows, string $prefix): array
    {
        $weight = 0;
        $credit = 0.0;
        $page1 = 0;
        $top3 = 0;
        $towns = 0;

        foreach ($rows as $row) {
            $pop = max(0, (int) ($row['population'] ?? 0));
            $state = (string) ($row["{$prefix}_state"] ?? 'unscanned');
            if ($pop === 0 || in_array($state, ['unscanned', 'pending'], true)) {
                continue;
            }
            $towns++;
            $weight += $pop;
            $rank = $row["{$prefix}_rank"] ?? null;
            $credit += $pop * self::creditFor(is_int($rank) ? $rank : null);
            if (is_int($rank) && $rank <= 3) {
                $top3++;
            }
            if (is_int($rank) && $rank <= 10) {
                $page1++;
            }
        }

        return [
            'score' => $weight > 0 ? (int) round(100 * $credit / $weight) : null,
            'towns' => $towns,
            'page1_towns' => $page1,
            'top3_towns' => $top3,
        ];
    }

    /** The credit a rank earns: top-3 → 1, page 1 → .7, page 2 → .3, beyond / not found / unreadable → 0. */
    public static function creditFor(?int $rank): float
    {
        return match (true) {
            $rank === null || $rank < 1 => 0.0,
            $rank <= 3 => self::CREDIT['top3'],
            $rank <= 10 => self::CREDIT['page1'],
            $rank <= 20 => self::CREDIT['page2'],
            default => 0.0,
        };
    }

    /**
     * Per keyword and mode: the latest FINISHED scan's score, the baseline (the mean of the previous
     * {@see BASELINE_SCANS} finished scans), the delta, and how many scans the baseline rests on (0 = first
     * measurement, no movement claimed). Every scan is scored by one aggregate query — the report is never
     * built here, so the board (which builds it once per keyword already) pays nothing twice. Cached per
     * keyword, mode and latest scan id, so a finished scan refreshes it and nothing recomputes otherwise.
     *
     * @return array<string, array{score: int|null, previous: int|null, delta: int|null, baseline_scans: int, towns: int, page1_towns: int, top3_towns: int}>
     */
    public function forKeyword(Site $site, Keyword $keyword): array
    {
        $out = [];
        foreach (TownRankScan::MODES as $mode) {
            $latestId = TownRankScan::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
                ->whereIn('status', ['complete', 'partial'])
                ->orderByDesc('scanned_at')->value('id');
            if ($latestId === null) {
                $out[$mode] = ['score' => null, 'previous' => null, 'delta' => null, 'baseline_scans' => 0, 'towns' => 0, 'page1_towns' => 0, 'top3_towns' => 0];

                continue;
            }
            $out[$mode] = Cache::remember(
                'town-visibility:kw:'.$keyword->id.':'.$mode.':'.$latestId,
                self::CACHE_SECONDS,
                function () use ($site, $keyword, $mode): array {
                    $scans = $this->scanScores($site, $keyword, $mode);   // newest first
                    $latest = $scans[0] ?? null;
                    $baseline = array_slice(array_column(array_slice($scans, 1), 'score'), 0, self::BASELINE_SCANS);
                    $previous = $baseline === [] ? null : (int) round(array_sum($baseline) / count($baseline));
                    $score = $latest['score'] ?? null;

                    return [
                        'score' => $score,
                        'previous' => $previous,
                        'delta' => $score !== null && $previous !== null ? $score - $previous : null,
                        'baseline_scans' => count($baseline),
                        'towns' => $latest['towns'] ?? 0,
                        'page1_towns' => $latest['page1_towns'] ?? 0,
                        'top3_towns' => $latest['top3_towns'] ?? 0,
                    ];
                },
            );
        }

        return $out;
    }

    /**
     * The site as a whole, per mode: the mean of every tracked keyword's latest score, the same for the
     * previous scans, the page-1 town count summed across keywords, and the weekly trend — one point per
     * scan date, the mean score of the keywords finalized that day. Cached for half an hour.
     *
     * @return array<string, array{score: int|null, previous: int|null, delta: int|null, baseline_scans: int, keywords: int, towns: int, page1_towns: int, top3_towns: int, history: list<array{date: string, score: int}>}>
     */
    public function forSite(Site $site): array
    {
        return Cache::remember('town-visibility:'.$site->id, self::CACHE_SECONDS, fn (): array => $this->computeSite($site));
    }

    public static function forget(Site $site): void
    {
        Cache::forget('town-visibility:'.$site->id);
    }

    /** @return array<string, array{score: int|null, previous: int|null, delta: int|null, baseline_scans: int, keywords: int, towns: int, page1_towns: int, top3_towns: int, history: list<array{date: string, score: int}>}> */
    private function computeSite(Site $site): array
    {
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('track_town_rank', true)->get();
        $out = [];
        foreach (TownRankScan::MODES as $mode) {
            $scores = [];
            $previous = [];
            $baselineScans = [];
            $page1 = 0;
            $top3 = 0;
            $towns = 0;
            $byDate = [];
            foreach ($keywords as $keyword) {
                $latest = $this->forKeyword($site, $keyword)[$mode];
                if ($latest['score'] !== null) {
                    $scores[] = $latest['score'];
                    $page1 += $latest['page1_towns'];
                    $top3 += $latest['top3_towns'];
                    $towns = max($towns, $latest['towns']);
                    if ($latest['previous'] !== null) {
                        $previous[] = $latest['previous'];
                        $baselineScans[] = $latest['baseline_scans'];
                    }
                }
                foreach ($this->history($site, $keyword, $mode) as $date => $score) {
                    $byDate[$date][] = $score;
                }
            }
            ksort($byDate);
            $score = $scores === [] ? null : (int) round(array_sum($scores) / count($scores));
            $prev = $previous === [] ? null : (int) round(array_sum($previous) / count($previous));
            $out[$mode] = [
                'score' => $score,
                'previous' => $prev,
                'delta' => $score !== null && $prev !== null ? $score - $prev : null,
                'baseline_scans' => $baselineScans === [] ? 0 : max($baselineScans),
                'keywords' => count($scores),
                'towns' => $towns,
                'page1_towns' => $page1,
                'top3_towns' => $top3,
                'history' => array_map(fn (string $date, array $s): array => ['date' => $date, 'score' => (int) round(array_sum($s) / count($s))], array_keys($byDate), $byDate),
            ];
        }

        return $out;
    }

    /**
     * Every finished scan of a keyword and mode (newest {@see HISTORY_SCANS}), each scored by ONE aggregate
     * query: rank-band credit × the town's population (joined on the durable GEOID, falling back to the
     * coverage-area id) summed per scan, plus the page-1 / top-3 / populated-town counts. No point rows are
     * loaded. Newest first; a scan with no populated, collected towns is left out.
     *
     * @return list<array{id: string, date: string, score: int, towns: int, page1_towns: int, top3_towns: int}>
     */
    private function scanScores(Site $site, Keyword $keyword, string $mode): array
    {
        $scans = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
            ->whereIn('status', ['complete', 'partial'])
            ->orderByDesc('scanned_at')->limit(self::HISTORY_SCANS)
            ->get(['id', 'scanned_at']);
        if ($scans->isEmpty()) {
            return [];
        }

        $credit = sprintf(
            'CASE WHEN p.rank IS NULL OR p.rank < 1 THEN 0 WHEN p.rank <= 3 THEN %s WHEN p.rank <= 10 THEN %s WHEN p.rank <= 20 THEN %s ELSE 0 END',
            self::CREDIT['top3'], self::CREDIT['page1'], self::CREDIT['page2'],
        );
        $pop = 'COALESCE(g.population, c.population, 0)';
        $totals = DB::table('town_rank_points as p')
            ->leftJoin('coverage_areas as g', fn ($j) => $j->on('g.site_id', '=', 'p.site_id')->on('g.geo_id', '=', 'p.geo_id'))
            ->leftJoin('coverage_areas as c', 'c.id', '=', 'p.coverage_area_id')
            ->whereIn('p.scan_id', $scans->pluck('id')->all())
            ->whereNotNull('p.collected_at')
            ->groupBy('p.scan_id')
            ->selectRaw(
                'p.scan_id, SUM(('.$credit.') * '.$pop.') as credit, SUM('.$pop.') as weight, '
                .'SUM(CASE WHEN '.$pop.' > 0 THEN 1 ELSE 0 END) as towns, '
                .'SUM(CASE WHEN '.$pop.' > 0 AND p.rank BETWEEN 1 AND 10 THEN 1 ELSE 0 END) as page1, '
                .'SUM(CASE WHEN '.$pop.' > 0 AND p.rank BETWEEN 1 AND 3 THEN 1 ELSE 0 END) as top3'
            )
            ->get()
            ->keyBy('scan_id');

        $out = [];
        foreach ($scans as $scan) {
            $t = $totals->get((string) $scan->id);
            $weight = $t === null ? 0.0 : (float) $t->weight;
            if ($weight <= 0 || $scan->scanned_at === null) {
                continue;
            }
            $out[] = [
                'id' => (string) $scan->id,
                'date' => $scan->scanned_at->toDateString(),
                'score' => (int) round(100 * (float) $t->credit / $weight),
                'towns' => (int) $t->towns,
                'page1_towns' => (int) $t->page1,
                'top3_towns' => (int) $t->top3,
            ];
        }

        return $out;
    }

    /**
     * One keyword's score per finished scan date, oldest first — the trend.
     *
     * @return array<string, int> Y-m-d => score
     */
    private function history(Site $site, Keyword $keyword, string $mode): array
    {
        $out = [];
        foreach ($this->scanScores($site, $keyword, $mode) as $scan) {
            $out[$scan['date']] = $scan['score'];
        }
        ksort($out);

        return $out;
    }
}
