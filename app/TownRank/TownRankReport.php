<?php

namespace App\TownRank;

use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;

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
     *     scans: array<string, array{id: string, status: string, scanned_at: string|null, points: int, found: int}|null>,
     *     rows: list<array{coverage_area_id: string, label: string, state: string|null, population: int, page_url: string|null, local_rank: int|null, local_url: string|null, local_state: string, town_rank: int|null, town_url: string|null, town_state: string, map_rank: int|null}>,
     *     summary: array<string, array{top3: int, page1: int, page2: int, beyond: int, not_found: int, pending: int}>
     * }
     */
    public function forKeyword(Site $site, Keyword $keyword): array
    {
        $scans = [];
        $pointsByMode = [];
        foreach (TownRankScan::MODES as $mode) {
            $scan = TownRankScan::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
                ->orderByDesc('scanned_at')->first();
            $scans[$mode] = $scan === null ? null : [
                'id' => (string) $scan->id,
                'status' => $scan->status,
                'scanned_at' => $scan->scanned_at?->toDateTimeString(),
                'points' => $scan->points_count,
                'found' => $scan->found_count,
            ];
            $pointsByMode[$mode] = $scan === null ? collect() : $scan->points()->get()->keyBy('coverage_area_id');
        }

        $mapRanks = $this->mapPackRanks($site, $keyword);

        $rows = [];
        $summary = [];
        foreach (TownRankScan::MODES as $mode) {
            $summary[$mode] = ['top3' => 0, 'page1' => 0, 'page2' => 0, 'beyond' => 0, 'not_found' => 0, 'pending' => 0];
        }

        foreach ($this->points->forSite($site) as $town) {
            $row = [
                'coverage_area_id' => $town['coverage_area_id'],
                'label' => $town['label'],
                'state' => $town['state'],
                'population' => $town['population'],
                'page_url' => $town['page_url'],
                'map_rank' => $mapRanks[$town['coverage_area_id']] ?? null,
            ];
            foreach (TownRankScan::MODES as $mode) {
                $prefix = $mode === TownRankScan::MODE_LOCAL ? 'local' : 'town';
                /** @var TownRankPoint|null $point */
                $point = $pointsByMode[$mode]->get($town['coverage_area_id']);
                $state = self::stateOf($point, $scans[$mode]);
                $row["{$prefix}_rank"] = $point?->rank;
                $row["{$prefix}_url"] = $point?->ranking_url;
                $row["{$prefix}_state"] = $state;
                if ($scans[$mode] !== null && $state !== 'unscanned') {
                    $summary[$mode][$state]++;
                }
            }
            $rows[] = $row;
        }

        return ['keyword' => (string) $keyword->query, 'scans' => $scans, 'rows' => $rows, 'summary' => $summary];
    }

    /**
     * A point's bucket: top3 | page1 | page2 | beyond | not_found | pending | unscanned (no scan / no point).
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
        $rank = $point->rank;
        if ($rank === null) {
            return 'not_found';
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
     * @return array<string, int|null> coverage_area_id => rank
     */
    private function mapPackRanks(Site $site, Keyword $keyword): array
    {
        $scans = GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', 'coverage')
            ->whereIn('status', ['complete', 'partial'])
            ->orderByDesc('scanned_at')
            ->with('points')
            ->get();

        $ranks = [];
        foreach ($scans as $scan) {
            foreach ($scan->points as $point) {
                $areaId = $point->coverage_area_id;
                if (is_string($areaId) && ! array_key_exists($areaId, $ranks)) {
                    $ranks[$areaId] = $point->rank;
                }
            }
        }

        return $ranks;
    }
}
