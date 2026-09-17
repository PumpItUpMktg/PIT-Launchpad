<?php

namespace App\TownRank;

use App\GeoGrid\CoverageGrid;
use App\GeoGrid\GeoGridPalette;
use App\GeoGrid\TownAreaMap;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankScan;

/**
 * The per-service-area read-model (§ Town Rank): one "area" per physical location, defined by the counties
 * that location serves (its home county plus any owner-selected counties — the same set the coverage grid
 * scans). An area page shows every tracked keyword as one card with two maps side by side over the SAME
 * towns and the same bounding box: the website's town rank (the Town Rank scan, sliced to this area) and
 * the GBP's map-pack rank (the latest coverage-mode geo-grid scan for this location × keyword), plus a
 * metrics slot the operator will fill with scoring once the data has been seen. Both maps are drawn over
 * the area's county outlines ({@see CountyOutlines}) when the Census gazetteer knows them, projected with
 * one uniform scale so a county keeps its true shape. Pure read-model; operator context crosses tenants,
 * so the {@see SiteScope} is dropped and site_id filtered explicitly.
 */
final class ServiceAreas
{
    public function __construct(
        private readonly CoverageGrid $coverage,
        private readonly TownRankPoints $points,
        private readonly TownRankBoard $board,
        private readonly TownRankReport $report,
        private readonly TownAreaMap $map,
    ) {}

    /**
     * Every service area of the site: the location, the counties it serves (labelled from the county
     * registry), how many towns that is, and how many keywords are tracked.
     *
     * @return list<array{location_id: string, name: string, city: string, state: string, counties: list<array{geoid: string, label: string}>, towns: int, keywords: int}>
     */
    public function areas(Site $site): array
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('name')->get();
        if ($locations->isEmpty()) {
            return [];
        }
        $townsByLocation = $this->coverage->pointsForMany($locations);
        $keywords = count($this->board->keywords($site));

        $geoids = [];
        foreach ($locations as $location) {
            $geoids = array_merge($geoids, $this->map->countyGeoIds($location));
        }
        $labels = $this->map->countyLabels(array_values(array_unique($geoids)));

        $areas = [];
        foreach ($locations as $location) {
            $cityState = $location->cityState();
            $areas[] = [
                'location_id' => (string) $location->id,
                'name' => (string) $location->name,
                'city' => $cityState['city'],
                'state' => $cityState['state'],
                'counties' => array_map(fn (string $g): array => ['geoid' => $g, 'label' => $labels[$g] ?? "County {$g}"], $this->map->countyGeoIds($location)),
                'towns' => count($townsByLocation[(string) $location->id] ?? []),
                'keywords' => $keywords,
            ];
        }

        return $areas;
    }

    /**
     * One area's page: the header (location + counties + town count) and one card per tracked keyword.
     * Each card carries the website map (`web`), the GBP map-pack map (`gbp`, null until a coverage scan
     * exists for this location × keyword), and a `metrics` list — the scoring slot. Both maps share the
     * area's bounding box so a town sits in the same spot on each.
     *
     * @return array{
     *     location: array{location_id: string, name: string, city: string, state: string},
     *     counties: list<array{geoid: string, label: string}>,
     *     towns: int,
     *     outlines: list<array{geoid: string, label: string, paths: list<string>}>,
     *     town_paths: array<string, list<string>>,
     *     cards: list<array{
     *         keyword_id: string, query: string,
     *         web: array{mode: string, status: string|null, scanned_at: string|null, summary: array<string, int>, markers: list<array{id: string, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}>}|null,
     *         gbp: array{scan_id: string, status: string, scanned_at: string|null, summary: array<string, int>, markers: list<array{id: string, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}>}|null,
     *         gbp_run: array{requests: int, cost: float, pending: bool},
     *         metrics: list<array{key: string, label: string, value: string|null, note: string}>
     *     }>
     * }|null
     */
    public function area(Site $site, string $locationId): ?array
    {
        $location = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($locationId)->first();
        if ($location === null) {
            return null;
        }

        // The county outline, each town's own boundary and the shared projector — the same frame the GBP
        // board draws on, so a town lands on the same spot wherever it is compared ({@see TownAreaMap}).
        $frame = $this->map->for($location);
        $areaTowns = $frame['towns'];
        $areaIds = array_fill_keys(array_column($areaTowns, 'coverage_area_id'), true);
        $siteTowns = [];
        foreach ($this->points->forSite($site) as $town) {
            if (isset($areaIds[$town['coverage_area_id']])) {
                $siteTowns[$town['coverage_area_id']] = $town;
            }
        }
        $coords = $frame['coords'];
        $project = $frame['project'];
        $townPaths = $frame['town_paths'];

        $cards = [];
        foreach ($this->board->keywords($site) as $entry) {
            $keyword = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($entry['keyword_id'])->first();
            if ($keyword === null) {
                continue;
            }
            $web = $this->webMap($site, $keyword, $areaIds, $coords, $project);
            $gbp = $this->gbpMap($location, $keyword, $siteTowns, $coords, $project);
            $cards[] = [
                'keyword_id' => (string) $keyword->id,
                'query' => (string) $keyword->query,
                'web' => $web,
                'gbp' => $gbp,
                // The GBP report is one Maps search per town from the town's own coordinates (a coverage scan).
                'gbp_run' => [
                    'requests' => count($areaTowns),
                    'cost' => round(count($areaTowns) * (float) config('launchpad.geo_grid.cost_per_request', 0.002), 2),
                    'pending' => $gbp !== null && $gbp['status'] === 'pending',
                ],
                'metrics' => $this->metrics(count($areaTowns), $web, $gbp),
            ];
        }

        $cityState = $location->cityState();

        return [
            'location' => ['location_id' => (string) $location->id, 'name' => (string) $location->name, 'city' => $cityState['city'], 'state' => $cityState['state']],
            'counties' => $frame['counties'],
            'towns' => count($areaTowns),
            'outlines' => $frame['outlines'],
            'town_paths' => $townPaths,
            'cards' => $cards,
        ];
    }

    /**
     * The website's town-rank map for this keyword, sliced to the area: town-search mode when it has been
     * scanned, else searched-from-town, else null (no Town Rank scan at all).
     *
     * @param  array<string, true>  $areaIds
     * @param  array<string, array{lat: float, lng: float}>  $coords
     * @param  callable(float, float): array{float, float}  $project
     * @return array{mode: string, status: string|null, scanned_at: string|null, progress: array<string, mixed>|null, uncollected: int|null, summary: array<string, int>, markers: list<array{id: string, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}>}|null
     */
    private function webMap(Site $site, Keyword $keyword, array $areaIds, array $coords, callable $project): ?array
    {
        $data = $this->report->forKeyword($site, $keyword);
        $mode = $data['scans'][TownRankScan::MODE_TOWN_QUERY] !== null ? TownRankScan::MODE_TOWN_QUERY : TownRankScan::MODE_LOCAL;
        $scan = $data['scans'][$mode];
        if ($scan === null) {
            return null;
        }
        $prefix = $mode === TownRankScan::MODE_LOCAL ? 'local' : 'town';

        $summary = ['top3' => 0, 'page1' => 0, 'page2' => 0, 'beyond' => 0, 'not_found' => 0, 'pending' => 0];
        $markers = [];
        foreach ($data['rows'] as $row) {
            $id = (string) $row['coverage_area_id'];
            if (! isset($areaIds[$id], $coords[$id])) {
                continue;
            }
            $state = (string) $row["{$prefix}_state"];
            if (isset($summary[$state])) {
                $summary[$state]++;
            }
            $rank = $row["{$prefix}_rank"] !== null ? (int) $row["{$prefix}_rank"] : null;
            $markers[] = self::marker($id, $coords[$id], $project, $rank, (string) $row['label'], $row['state'], (int) $row['population'], $row['page_url'] !== null);
        }

        return [
            'mode' => $mode,
            'status' => $scan['status'],
            'scanned_at' => $scan['scanned_at'],
            // How much is left and how long that should take, and — once closed — what it never collected.
            'progress' => $scan['status'] === 'pending'
                ? CollectionProgress::for((int) $scan['collected'], (int) $scan['points'])
                : null,
            'uncollected' => $scan['status'] === 'partial'
                ? max(0, (int) $scan['points'] - (int) $scan['collected'])
                : null,
            'summary' => $summary,
            'markers' => $markers,
        ];
    }

    /**
     * The GBP's map-pack map: the latest coverage-mode geo-grid scan for this location × keyword (complete,
     * partial, or still collecting). Null until one exists — the card shows the placeholder.
     *
     * @param  array<string, array<string, mixed>>  $siteTowns
     * @param  array<string, array{lat: float, lng: float}>  $coords
     * @param  callable(float, float): array{float, float}  $project
     * @return array{scan_id: string, status: string, scanned_at: string|null, summary: array<string, int>, markers: list<array{id: string, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}>}|null
     */
    private function gbpMap(Location $location, Keyword $keyword, array $siteTowns, array $coords, callable $project): ?array
    {
        $scan = GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)->where('location_id', $location->id)->where('keyword_id', $keyword->id)
            ->where('mode', 'coverage')
            ->orderByDesc('scanned_at')
            ->with('points')
            ->first();
        if ($scan === null) {
            return null;
        }

        $summary = ['top3' => 0, 'top7' => 0, 'top10' => 0, 'beyond' => 0, 'absent' => 0, 'pending' => 0];
        $markers = [];
        // Linked by GEOID, not by the stored row id: a coverage rebuild replaces every town row, which would
        // otherwise drop every marker off this map (see TownPointLinks).
        $linked = TownPointLinks::byTown(array_values($siteTowns), $scan->points);
        foreach ($linked as $id => $point) {
            $id = (string) $id;
            if (! isset($coords[$id])) {
                continue;
            }
            $town = $siteTowns[$id] ?? null;
            $rank = $point->rank;
            if ($point->collected_at === null && $scan->status === 'pending') {
                $summary['pending']++;
            } else {
                $summary[match (true) {
                    $rank === null => 'absent',
                    $rank <= 3 => 'top3',
                    $rank <= 7 => 'top7',
                    $rank <= 10 => 'top10',
                    default => 'beyond',
                }]++;
            }
            $markers[] = self::marker(
                $id, $coords[$id], $project, $rank,
                (string) ($town['name'] ?? $point->label ?? ''), $town['state'] ?? null, (int) ($town['population'] ?? 0), ($town['page_url'] ?? null) !== null,
            );
        }

        $points = $scan->points->count();
        $collected = $scan->points->filter(fn ($p): bool => $p->collected_at !== null)->count();

        return [
            'scan_id' => (string) $scan->id,
            'status' => (string) $scan->status,
            'scanned_at' => $scan->scanned_at?->toDateTimeString(),
            'progress' => $scan->status === 'pending' ? CollectionProgress::for($collected, $points) : null,
            'uncollected' => $scan->status === 'partial' ? max(0, $points - $collected) : null,
            'summary' => $summary,
            'markers' => $markers,
        ];
    }

    /**
     * The scoring slot. The operator has not chosen a score yet; these are the raw shares the two maps
     * already carry, labelled as provisional, plus an explicit empty score so the card's layout is settled
     * before the formula is.
     *
     * @param  array<string, mixed>|null  $web
     * @param  array<string, mixed>|null  $gbp
     * @return list<array{key: string, label: string, value: string|null, note: string}>
     */
    private function metrics(int $towns, ?array $web, ?array $gbp): array
    {
        $share = fn (int $n): ?string => $towns > 0 ? sprintf('%d%%', (int) round($n / $towns * 100)) : null;
        $webSummary = is_array($web['summary'] ?? null) ? $web['summary'] : [];
        $gbpSummary = is_array($gbp['summary'] ?? null) ? $gbp['summary'] : [];

        return [
            ['key' => 'web_page1_share', 'label' => 'Website page-1 share', 'value' => $web === null ? null : $share((int) ($webSummary['top3'] ?? 0) + (int) ($webSummary['page1'] ?? 0)), 'note' => 'towns where the site ranks 1–10 (provisional)'],
            ['key' => 'web_top3_share', 'label' => 'Website top-3 share', 'value' => $web === null ? null : $share((int) ($webSummary['top3'] ?? 0)), 'note' => 'towns where the site ranks 1–3 (provisional)'],
            ['key' => 'gbp_top3_share', 'label' => 'GBP top-3 share', 'value' => $gbp === null ? null : $share((int) ($gbpSummary['top3'] ?? 0)), 'note' => 'towns where the GBP is in the map pack (provisional)'],
            ['key' => 'score', 'label' => 'Area score', 'value' => null, 'note' => 'not defined yet — the formula is chosen once the data has been seen'],
        ];
    }

    /**
     * @param  array{lat: float, lng: float}  $c
     * @param  callable(float, float): array{float, float}  $project
     * @return array{id: string, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}
     */
    private static function marker(string $id, array $c, callable $project, ?int $rank, string $name, ?string $state, int $population, bool $page): array
    {
        [$x, $y] = $project($c['lat'], $c['lng']);

        return [
            'id' => $id,
            'x' => $x,
            'y' => $y,
            'rank' => $rank,
            'color' => GeoGridPalette::absolute($rank),
            'label' => $name.($state !== null && $state !== '' ? ", {$state}" : ''),
            'population' => $population,
            'page' => $page,
        ];
    }
}
