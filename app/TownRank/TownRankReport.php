<?php

namespace App\TownRank;

use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use Illuminate\Support\Carbon;

/**
 * The town-rank read-model (§ Town Rank): for one (site × keyword), the latest scan per mode joined onto the
 * site's covered towns — one row per town with its published page, the organic rank in each mode (+ the URL
 * that ranks), and the map-pack rank from the latest coverage-mode geo-grid scan for the same keyword, so the
 * website and the GBP listing read side by side. Towns with no scan point yet still appear (rank pending /
 * unknown) so the table is always the whole footprint. Feeds the CLI now and the operator map page next.
 *
 * Operator context crosses tenants, so the {@see SiteScope} is dropped and site_id filtered explicitly.
 */
final class TownRankReport
{
    public function __construct(private readonly TownRankPoints $points) {}

    /**
     * @return array{
     *     keyword: string,
     *     scans: array<string, array{id: string, status: string, scanned_at: string|null, points: int, collected: int, found: int, previous_scanned_at: string|null}|null>,
     *     rows: list<array{coverage_area_id: string, label: string, state: string|null, population: int, page_url: string|null, page_match: string|null, page_published_at: string|null, local_rank: int|null, local_url: string|null, local_state: string, local_prev_rank: int|null, local_change: string|null, town_rank: int|null, town_url: string|null, town_state: string, town_prev_rank: int|null, town_change: string|null, local_page_too_new: bool, town_page_too_new: bool, local_top: list<string>, town_top: list<string>, map_rank: int|null, map_measured_at: Carbon|null}>,
     *     summary: array<string, array{top3: int, page1: int, page2: int, beyond: int, not_found: int, unreadable: int, pending: int, up: int, down: int, new: int, lost: int, same: int}>
     * }
     */
    /**
     * A town page younger than this (at the latest scan) is set aside from every movement count: a page that
     * has not had time to settle in the index cannot have "lost" or "gained" anything meaningful.
     */
    public const DEFAULT_MIN_PAGE_AGE_DAYS = 30;

    public function forKeyword(Site $site, Keyword $keyword, ?int $sinceDays = null, int $minPageAgeDays = self::DEFAULT_MIN_PAGE_AGE_DAYS): array
    {
        // The town list first: a scan's points are linked to the towns that exist NOW (a coverage rebuild
        // gives every town a new row id, which would otherwise orphan every point — see TownPointLinks).
        $towns = $this->points->forSite($site);
        $scans = [];
        $pointsByMode = [];
        $prevByMode = [];
        foreach (TownRankScan::MODES as $mode) {
            $scan = TownRankScan::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
                ->orderByDesc('scanned_at')->first();
            // The scan to compare against: the newest FINALIZED one older than the latest (a pending latest
            // compares against the last finalized, so movement is never measured against half a sweep). With
            // $sinceDays the baseline is the newest finalized scan at least that many days before the latest —
            // "how have we moved in the last two weeks" rather than "since the last sweep".
            $previous = $scan === null ? null : TownRankScan::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
                ->whereIn('status', ['complete', 'partial'])
                ->whereKeyNot($scan->id)
                ->where('scanned_at', '<=', $sinceDays !== null && $scan->scanned_at !== null ? $scan->scanned_at->copy()->subDays($sinceDays) : $scan->scanned_at)
                ->orderByDesc('scanned_at')->first();
            $rawPoints = $scan === null ? collect() : $scan->points()->get();
            $points = TownPointLinks::byTown($towns, $rawPoints);
            $scans[$mode] = $scan === null ? null : [
                'id' => (string) $scan->id,
                'status' => $scan->status,
                'scanned_at' => $scan->scanned_at?->toDateTimeString(),
                'points' => $scan->points_count,
                'collected' => $rawPoints->filter(fn (TownRankPoint $p): bool => $p->collected_at !== null)->count(),
                'found' => $scan->found_count,
                'previous_scanned_at' => $previous?->scanned_at?->toDateTimeString(),
            ];
            $pointsByMode[$mode] = $points;
            $prevByMode[$mode] = $previous === null ? null : TownPointLinks::byTown($towns, $previous->points()->get());
        }

        $mapRanks = $this->mapPackRanks($site, $keyword, $towns);

        $rows = [];
        $summary = [];
        foreach (TownRankScan::MODES as $mode) {
            $summary[$mode] = ['top3' => 0, 'page1' => 0, 'page2' => 0, 'beyond' => 0, 'not_found' => 0, 'unreadable' => 0, 'pending' => 0, 'up' => 0, 'down' => 0, 'new' => 0, 'lost' => 0, 'same' => 0];
        }

        foreach ($towns as $town) {
            $row = [
                'coverage_area_id' => $town['coverage_area_id'],
                'label' => $town['label'],
                'state' => $town['state'],
                'population' => $town['population'],
                'page_url' => $town['page_url'],
                'page_match' => $town['page_match'],
                'page_published_at' => $town['page_published_at'] ?? null,
                'map_rank' => $mapRanks[$town['coverage_area_id']]['rank'] ?? null,
                'map_measured_at' => $mapRanks[$town['coverage_area_id']]['measured_at'] ?? null,
            ];
            foreach (TownRankScan::MODES as $mode) {
                $prefix = $mode === TownRankScan::MODE_LOCAL ? 'local' : 'town';
                /** @var TownRankPoint|null $point */
                $point = $pointsByMode[$mode]->get($town['coverage_area_id']);
                $state = self::stateOf($point, $scans[$mode]);
                $rank = $point?->rank;
                /** @var TownRankPoint|null $prevPoint */
                $prevPoint = $prevByMode[$mode]?->get($town['coverage_area_id']);
                $hasPrevious = $prevByMode[$mode] !== null;
                $prevRank = $hasPrevious ? $prevPoint?->rank : null;
                // No data is not "not found": a point that could not be read on EITHER side carries no
                // movement — otherwise a failed read on a #1 town prints as a lost #1.
                $prevUnreadable = $prevPoint !== null && $prevPoint->read_error !== null;
                // A page too new to judge — younger than the age floor at the latest scan, or published after
                // the baseline scan — is set aside: it carries no movement and no then/now bucket.
                $tooNew = self::pageTooNew($town['page_published_at'] ?? null, $scans[$mode]['scanned_at'] ?? null, $scans[$mode]['previous_scanned_at'] ?? null, $minPageAgeDays);
                $comparable = $hasPrevious && ! $tooNew && ! in_array($state, ['pending', 'unscanned', 'unreadable'], true) && ! $prevUnreadable;
                $change = $comparable ? self::changeOf($rank, $prevRank) : null;
                $row["{$prefix}_page_too_new"] = $tooNew;

                $row["{$prefix}_rank"] = $rank;
                $row["{$prefix}_url"] = $point?->ranking_url;
                $row["{$prefix}_state"] = $state;
                $row["{$prefix}_prev_rank"] = $prevRank;
                $row["{$prefix}_change"] = $change;
                // What the scan SAW at this town (the top of the page, position + domain) — the evidence
                // behind a "not found": local plumbers means a real miss, generic national sites means the
                // search was not placed at the town.
                $row["{$prefix}_top"] = array_map(
                    function (array $r): string {
                        $cell = $r['position'].' '.$r['domain'];
                        if (($r['masked'] ?? false) === true) {
                            // The wrapper hid the host: show every signal the provider still gave us.
                            $signals = array_filter([$r['website_name'] ?? '', $r['breadcrumb'] ?? '', isset($r['title']) ? '«'.mb_strimwidth((string) $r['title'], 0, 48, '…').'»' : '']);
                            $cell .= ' [masked'.($signals !== [] ? ': '.implode(' / ', $signals) : '').']';
                        }

                        return $cell;
                    },
                    array_slice(is_array($point?->top_results) ? $point->top_results : [], 0, 5),
                );
                if ($scans[$mode] !== null && $state !== 'unscanned') {
                    $summary[$mode][$state]++;
                }
                if ($change !== null) {
                    $summary[$mode][$change]++;
                }
            }
            $rows[] = $row;
        }

        return ['keyword' => (string) $keyword->query, 'scans' => $scans, 'rows' => $rows, 'summary' => $summary];
    }

    /**
     * Whether a town's page is too new to compare: published within $minAgeDays of the latest scan, or
     * after the baseline scan it would be compared against. A town with no page is never "too new".
     */
    public static function pageTooNew(?string $publishedAt, ?string $latestScannedAt, ?string $previousScannedAt, int $minAgeDays): bool
    {
        if ($publishedAt === null || $publishedAt === '') {
            return false;
        }
        if ($previousScannedAt !== null && $publishedAt > $previousScannedAt) {
            return true;
        }
        if ($minAgeDays > 0 && $latestScannedAt !== null) {
            return $publishedAt > Carbon::parse($latestScannedAt)->subDays($minAgeDays)->toDateTimeString();
        }

        return false;
    }

    /** Movement since the previous scan: up | down | same | new (ranked now, not before) | lost (the reverse). */
    public static function changeOf(?int $rank, ?int $previous): string
    {
        return match (true) {
            $rank === null && $previous === null => 'same',
            $previous === null => 'new',
            $rank === null => 'lost',
            $rank < $previous => 'up',
            $rank > $previous => 'down',
            default => 'same',
        };
    }

    /**
     * A point's bucket: top3 | page1 | page2 | beyond | not_found | unreadable | pending | unscanned.
     *
     * @param  array{status: string}|null  $scan
     */
    public static function stateOf(?TownRankPoint $point, ?array $scan): string
    {
        if ($point === null) {
            return $scan === null ? 'unscanned' : 'not_found';
        }
        if ($point->collected_at === null) {
            return $scan !== null && $scan['status'] === 'pending' ? 'pending' : 'not_found';
        }
        // Closed without an answer: we know nothing about this town, which is not the same as knowing we
        // don't rank in it.
        if ($point->read_error !== null) {
            return 'unreadable';
        }
        $rank = $point->rank;
        if ($rank === null) {
            // A genuine not-found carries the results that DID rank. "Not found" with NOTHING stored is a
            // phantom — a read that came back empty (rate limit, transport, an empty page) — and is no data,
            // never evidence that the site dropped out. `launchpad:town-rank-recollect` re-reads them free.
            return $point->provider_task_id !== null && ($point->top_results ?? []) === [] ? 'unreadable' : 'not_found';
        }

        return match (true) {
            $rank <= 3 => 'top3',
            $rank <= 10 => 'page1',
            $rank <= 20 => 'page2',
            default => 'beyond',
        };
    }

    /**
     * The GBP's map-pack rank per town from the latest coverage-mode geo-grid scan for this keyword (any
     * location — a town belongs to the scan of whichever location serves it; the newest wins).
     *
     * @param  list<array<string, mixed>>  $towns  the site's town list, to link each scan point to its town
     * @return array<string, array{rank: int|null, measured_at: Carbon|null}> keyed by coverage_area_id
     */
    private function mapPackRanks(Site $site, Keyword $keyword, array $towns): array
    {
        $scans = GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', 'coverage')
            ->whereIn('status', ['complete', 'partial'])
            ->orderByDesc('scanned_at')
            ->with('points')
            ->get();

        $ranks = [];
        foreach ($scans as $scan) {
            foreach (TownPointLinks::byTown($towns, $scan->points) as $areaId => $point) {
                if (! array_key_exists($areaId, $ranks)) {
                    // The POINT's own collected_at, not the scan's scanned_at: a coverage scan collects
                    // incrementally over several sweeps, so towns in one scan are measured minutes or
                    // hours apart. The town's own read is the honest answer to "when is this from".
                    $ranks[$areaId] = ['rank' => $point->rank, 'measured_at' => $point->collected_at];
                }
            }
        }

        return $ranks;
    }
}
