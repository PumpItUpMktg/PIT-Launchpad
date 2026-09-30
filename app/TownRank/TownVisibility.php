<?php

namespace App\TownRank;

use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use Illuminate\Support\Facades\Cache;

/**
 * Town Visibility (§ Town Rank): ONE number, 0–100, for "how much of the territory's search demand can see
 * this site" — per keyword and mode, and for the site as a whole. Each town scores by where the site ranks
 * there (top-3 full credit, page 1 most, page 2 a little, beyond / not found none) and towns are weighted by
 * population, so ranking in the county seat counts for more than a hamlet nobody searches from. The report's
 * ▲▼ movement counts remain the detail underneath; this is the headline the client reads and the trend it
 * moves along week to week.
 *
 * Honest framing: observed rankings and their movement only — no traffic, lead or revenue claim is derived.
 */
final class TownVisibility
{
    /** Credit per rank band — a #1 and a #3 are both "found first"; page 1 is the goal; page 2 is a foothold. */
    public const CREDIT = ['top3' => 1.0, 'page1' => 0.7, 'page2' => 0.3];

    /** Finalized scans considered for the site trend, per keyword and mode (weekly cadence → ~3 months). */
    public const HISTORY_SCANS = 12;

    private const CACHE_SECONDS = 1800;

    public function __construct(private readonly TownRankReport $report, private readonly TownRankPoints $points) {}

    /**
     * Score one report row-set for a mode: the latest scan's score, the previous finalized scan's score on
     * the same towns, and the delta. Null score = no finalized scan (or no populated towns) to score.
     *
     * @param  list<array<string, mixed>>  $rows  {@see TownRankReport::forKeyword()} rows
     * @param  'town'|'local'  $prefix
     * @return array{score: int|null, previous: int|null, delta: int|null, towns: int, page1_towns: int, top3_towns: int}
     */
    public function scoreRows(array $rows, string $prefix): array
    {
        $weight = 0;
        $credit = 0.0;
        $prevWeight = 0;
        $prevCredit = 0.0;
        $page1 = 0;
        $top3 = 0;
        $towns = 0;
        $hasPrevious = false;

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
            if (array_key_exists("{$prefix}_change", $row) && $row["{$prefix}_change"] !== null) {
                $hasPrevious = true;
                $prevRank = $row["{$prefix}_prev_rank"] ?? null;
                $prevWeight += $pop;
                $prevCredit += $pop * self::creditFor(is_int($prevRank) ? $prevRank : null);
            }
        }

        $score = $weight > 0 ? (int) round(100 * $credit / $weight) : null;
        $previous = $hasPrevious && $prevWeight > 0 ? (int) round(100 * $prevCredit / $prevWeight) : null;

        return [
            'score' => $score,
            'previous' => $previous,
            'delta' => $score !== null && $previous !== null ? $score - $previous : null,
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
     * Per keyword: both modes scored from the report (latest vs previous finalized scan).
     *
     * @return array<string, array{score: int|null, previous: int|null, delta: int|null, towns: int, page1_towns: int, top3_towns: int}>
     */
    public function forKeyword(Site $site, Keyword $keyword): array
    {
        $data = $this->report->forKeyword($site, $keyword);
        $out = [];
        foreach (TownRankScan::MODES as $mode) {
            $prefix = $mode === TownRankScan::MODE_LOCAL ? 'local' : 'town';
            $out[$mode] = $data['scans'][$mode] === null
                ? ['score' => null, 'previous' => null, 'delta' => null, 'towns' => 0, 'page1_towns' => 0, 'top3_towns' => 0]
                : $this->scoreRows($data['rows'], $prefix);
        }

        return $out;
    }

    /**
     * The site as a whole, per mode: the mean of every tracked keyword's latest score, the same for the
     * previous scans, the page-1 town count summed across keywords, and the weekly trend — one point per
     * scan date, the mean score of the keywords finalized that day. Cached for half an hour.
     *
     * @return array<string, array{score: int|null, previous: int|null, delta: int|null, keywords: int, towns: int, page1_towns: int, top3_towns: int, history: list<array{date: string, score: int}>}>
     */
    public function forSite(Site $site): array
    {
        return Cache::remember('town-visibility:'.$site->id, self::CACHE_SECONDS, fn (): array => $this->computeSite($site));
    }

    public static function forget(Site $site): void
    {
        Cache::forget('town-visibility:'.$site->id);
    }

    /** @return array<string, array{score: int|null, previous: int|null, delta: int|null, keywords: int, towns: int, page1_towns: int, top3_towns: int, history: list<array{date: string, score: int}>}> */
    private function computeSite(Site $site): array
    {
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('track_town_rank', true)->get();
        $population = $this->populationIndex($site);

        $out = [];
        foreach (TownRankScan::MODES as $mode) {
            $scores = [];
            $previous = [];
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
                    }
                }
                foreach ($this->history($site, $keyword, $mode, $population) as $date => $score) {
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
     * One keyword's score per finalized scan date (newest {@see HISTORY_SCANS}), scored straight from the
     * stored points against today's town populations.
     *
     * @param  array{geo: array<string, int>, id: array<string, int>}  $population
     * @return array<string, int> Y-m-d => score
     */
    private function history(Site $site, Keyword $keyword, string $mode, array $population): array
    {
        $scans = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
            ->whereIn('status', ['complete', 'partial'])
            ->orderByDesc('scanned_at')->limit(self::HISTORY_SCANS)->get();
        if ($scans->isEmpty()) {
            return [];
        }

        $points = TownRankPoint::withoutGlobalScope(SiteScope::class)
            ->whereIn('scan_id', $scans->pluck('id')->all())
            ->whereNotNull('collected_at')
            ->get(['scan_id', 'geo_id', 'coverage_area_id', 'rank']);

        $out = [];
        foreach ($scans as $scan) {
            $weight = 0;
            $credit = 0.0;
            foreach ($points->where('scan_id', $scan->id) as $p) {
                $pop = $population['geo'][(string) $p->geo_id] ?? $population['id'][(string) $p->coverage_area_id] ?? 0;
                if ($pop <= 0) {
                    continue;
                }
                $weight += $pop;
                $credit += $pop * self::creditFor($p->rank !== null ? (int) $p->rank : null);
            }
            if ($weight > 0 && $scan->scanned_at !== null) {
                $out[$scan->scanned_at->toDateString()] = (int) round(100 * $credit / $weight);
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array{geo: array<string, int>, id: array<string, int>} */
    private function populationIndex(Site $site): array
    {
        $geo = [];
        $id = [];
        foreach ($this->points->forSite($site) as $town) {
            $id[$town['coverage_area_id']] = (int) $town['population'];
            if ($town['geo_id'] !== null) {
                $geo[$town['geo_id']] = (int) $town['population'];
            }
        }

        return ['geo' => $geo, 'id' => $id];
    }
}
