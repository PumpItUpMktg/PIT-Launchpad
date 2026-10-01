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
use App\TownPages\PrioritySectionStatus;

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
        private readonly TownVisibility $visibility,
        private readonly PrioritySectionStatus $sectionStatus,
    ) {}

    /**
     * Every service area of the site: the location, the counties it serves (labelled from the county
     * registry), how many towns that is, and how many keywords are tracked.
     *
     * @return list<array{location_id: string, name: string, city: string, state: string, counties: list<array{geoid: string, label: string}>, towns: int, keywords: int, visibility: array{score: int|null, previous: int|null, delta: int|null, baseline_scans: int, keywords: int}}>
     */
    public function areas(Site $site): array
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('name')->get();
        if ($locations->isEmpty()) {
            return [];
        }
        $townsByLocation = $this->coverage->pointsForMany($locations);
        $keywordModels = $this->keywordModels($site);
        $keywords = count($keywordModels);

        $geoids = [];
        foreach ($locations as $location) {
            $geoids = array_merge($geoids, $this->map->countyGeoIds($location));
        }
        $labels = $this->map->countyLabels(array_values(array_unique($geoids)));

        // Every office's Town Visibility (town search) from ONE point query — the score a client reads per office.
        $sets = [];
        foreach ($locations as $location) {
            $sets[(string) $location->id] = self::townSet($townsByLocation[(string) $location->id] ?? []);
        }
        $visibility = $this->visibility->forTownSets($site, $keywordModels, $sets);

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
                'visibility' => self::setSummary($visibility[(string) $location->id]),
            ];
        }

        return $areas;
    }

    /**
     * The tracked keywords as models, in the wall's order.
     *
     * @return list<Keyword>
     */
    private function keywordModels(Site $site): array
    {
        $ids = array_column($this->board->keywords($site), 'keyword_id');
        if ($ids === []) {
            return [];
        }
        $byId = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (string $id) => $byId->get($id), $ids)));
    }

    /**
     * A town list as a {@see TownVisibility::forTownSets()} set: coverage-area ids + GEOIDs (a point matches
     * on either, so a coverage rebuild that re-ids the rows still finds the town by GEOID).
     *
     * @param  list<array{coverage_area_id: string, geo_id: string}>  $towns
     * @return array{ids: list<string>, geo: list<string>}
     */
    private static function townSet(array $towns): array
    {
        return [
            'ids' => array_values(array_unique(array_column($towns, 'coverage_area_id'))),
            'geo' => array_values(array_unique(array_filter(array_column($towns, 'geo_id')))),
        ];
    }

    /**
     * The set's headline without the per-keyword detail.
     *
     * @param  array{score: int|null, previous: int|null, delta: int|null, baseline_scans: int, keywords: int, per_keyword: array<string, mixed>}  $set
     * @return array{score: int|null, previous: int|null, delta: int|null, baseline_scans: int, keywords: int}
     */
    private static function setSummary(array $set): array
    {
        return ['score' => $set['score'], 'previous' => $set['previous'], 'delta' => $set['delta'], 'baseline_scans' => $set['baseline_scans'], 'keywords' => $set['keywords']];
    }

    /**
     * An area's towns grouped by the county on their GEOID (a county subdivision's 10-digit GEOID starts
     * with the 5-digit county; a place carries no county and is left to the area total) — the county sets
     * the map header scores.
     *
     * @param  list<array{coverage_area_id: string, geo_id: string, label: string, lat: float, lng: float, population: int}>  $towns
     * @return array<string, list<array{coverage_area_id: string, geo_id: string, label: string, lat: float, lng: float, population: int}>> county GEOID → its towns
     */
    private static function townsByCounty(array $towns): array
    {
        $byCounty = [];
        foreach ($towns as $town) {
            $geo = trim((string) $town['geo_id']);
            if (strlen($geo) !== 10) {
                continue;
            }
            $byCounty[substr($geo, 0, 5)][] = $town;
        }

        return $byCounty;
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
     *     visibility: array{score: int|null, previous: int|null, delta: int|null, baseline_scans: int, keywords: int},
     *     county_visibility: list<array{geoid: string, label: string, towns: int, score: int|null, previous: int|null, delta: int|null, baseline_scans: int}>,
     *     outlines: list<array{geoid: string, label: string, paths: list<string>}>,
     *     town_paths: array<string, list<string>>,
     *     cards: list<array{
     *         keyword_id: string, query: string, silo: string|null,
     *         web: array{mode: string, status: string|null, scanned_at: string|null, summary: array<string, int>, markers: list<array{id: string, unreadable: bool, sections: bool, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}>}|null,
     *         gbp: array{scan_id: string, status: string, scanned_at: string|null, summary: array<string, int>, markers: list<array{id: string, unreadable: bool, sections: bool, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}>}|null,
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
        $keywordModels = $this->keywordModels($site);

        // Where each town stands on its priority sections (one read for the area): the map rings the towns
        // whose sections are live, a priority card counts the towns off page 1 it could push, and the
        // panel's "Draft & push" knows the page to queue.
        $populationByGeo = [];
        foreach ($areaTowns as $t) {
            if (trim($t['geo_id']) !== '') {
                $populationByGeo[$t['geo_id']] = (int) $t['population'];
            }
        }
        $statusByGeo = $this->sectionStatus->forGeoIds($site, $populationByGeo);
        $statusByTown = [];
        foreach ($areaTowns as $t) {
            $statusByTown[$t['coverage_area_id']] = $statusByGeo[$t['geo_id']] ?? null;
        }
        $sectionsLive = array_keys(array_filter($statusByTown, fn (?array $st): bool => $st !== null && $st['state'] === PrioritySectionStatus::CURRENT));

        // Town Visibility (town search) for the area and each of its counties from ONE point query; every
        // keyword card reads its own number from the area set. A card whose only scan is searched-from-town
        // scores that mode for itself (rare: a keyword never scanned by town query).
        $byCounty = self::townsByCounty($areaTowns);
        $sets = ['area' => self::townSet($areaTowns)];
        foreach ($frame['counties'] as $county) {
            if (($byCounty[$county['geoid']] ?? []) !== []) {
                $sets['county:'.$county['geoid']] = self::townSet($byCounty[$county['geoid']]);
            }
        }
        $visibility = $this->visibility->forTownSets($site, $keywordModels, $sets);
        $countyVisibility = [];
        foreach ($frame['counties'] as $county) {
            $v = $visibility['county:'.$county['geoid']] ?? ['score' => null, 'previous' => null, 'delta' => null, 'baseline_scans' => 0];
            $countyVisibility[] = [
                'geoid' => $county['geoid'],
                'label' => $county['label'],
                'towns' => count($byCounty[$county['geoid']] ?? []),
                'score' => $v['score'],
                'previous' => $v['previous'],
                'delta' => $v['delta'],
                'baseline_scans' => $v['baseline_scans'],
            ];
        }

        $cards = [];
        foreach ($this->board->keywords($site) as $entry) {
            $keyword = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($entry['keyword_id'])->first();
            if ($keyword === null) {
                continue;
            }
            $priority = $keyword->town_priority_rank !== null ? (int) $keyword->town_priority_rank : null;
            // The "sections live" ring belongs to the priority cards — the sections are those keywords' own.
            $web = $this->webMap($site, $keyword, $areaIds, $coords, $project, $priority !== null ? array_fill_keys($sectionsLive, true) : []);
            $gbp = $this->gbpMap($location, $keyword, $siteTowns, $coords, $project);
            $score = match (true) {
                $web === null => null,
                $web['mode'] === TownRankScan::MODE_TOWN_QUERY => $visibility['area']['per_keyword'][(string) $keyword->id] ?? null,
                default => $this->visibility->forTowns($site, $keyword, (string) $web['mode'], $sets['area']['ids'], $sets['area']['geo']),
            };
            $cards[] = [
                'keyword_id' => (string) $keyword->id,
                'query' => (string) $keyword->query,
                // The §4 silo, carried from the wall's own grouping so both surfaces read in the same terms.
                'silo' => $entry['silo'],
                'priority' => $priority,
                // A priority keyword's towns OFF page 1 here that have a page and can carry sections — the
                // ones "Draft & push" on this card would queue (largest first).
                'lagging' => $priority === null || $web === null ? null : self::lagging($web['markers'], $statusByTown),
                'web' => $web,
                'gbp' => $gbp,
                // The GBP report is one Maps search per town from the town's own coordinates (a coverage scan).
                'gbp_run' => [
                    'requests' => count($areaTowns),
                    'cost' => round(count($areaTowns) * (float) config('launchpad.geo_grid.cost_per_request', 0.002), 2),
                    'pending' => $gbp !== null && $gbp['status'] === 'pending',
                ],
                'metrics' => $this->metrics(count($areaTowns), $web, $gbp, $score),
            ];
        }

        // Priority keywords first, in rank order, so the operator weighs them together; the rest keep the
        // wall's silo grouping beneath.
        usort($cards, fn (array $a, array $b): int => ($a['priority'] ?? PHP_INT_MAX) <=> ($b['priority'] ?? PHP_INT_MAX));

        $cityState = $location->cityState();

        return [
            'location' => ['location_id' => (string) $location->id, 'name' => (string) $location->name, 'city' => $cityState['city'], 'state' => $cityState['state']],
            'counties' => $frame['counties'],
            'towns' => count($areaTowns),
            'visibility' => self::setSummary($visibility['area']),
            'county_visibility' => $countyVisibility,
            'outlines' => $frame['outlines'],
            'town_paths' => $townPaths,
            'cards' => $cards,
        ];
    }

    /**
     * The towns on a priority card's map that are OFF page 1 (page 2, beyond, or not found) and have a page:
     * `eligible` are the ones whose page can carry sections now (at or above the tier, not already queued),
     * with their content ids largest town first — what "Draft & push" on the card queues.
     *
     * @param  list<array{id: string, rank: int|null, page: bool, population: int, unreadable: bool}>  $markers
     * @param  array<string, array{content_id: string|null, state: string, eligible: bool, keep: list<array{query: string, rank: int}>}|null>  $statusByTown
     * @return array{off_page1: int, eligible: int, ranking_elsewhere: int, content_ids: list<string>}
     */
    private static function lagging(array $markers, array $statusByTown): array
    {
        $off = 0;
        $eligible = [];
        $ranking = 0;
        foreach ($markers as $m) {
            if (! $m['page'] || $m['unreadable'] || ($m['rank'] !== null && $m['rank'] <= 10)) {
                continue;
            }
            $off++;
            $st = $statusByTown[$m['id']] ?? null;
            if ($st !== null && $st['eligible'] && $st['content_id'] !== null) {
                $eligible[] = ['id' => $st['content_id'], 'population' => (int) $m['population']];
                // Off page 1 for THIS keyword, but page 1 for another priority keyword: that section is kept.
                if ($st['keep'] !== []) {
                    $ranking++;
                }
            }
        }
        usort($eligible, fn (array $a, array $b): int => $b['population'] <=> $a['population']);

        return ['off_page1' => $off, 'eligible' => count($eligible), 'ranking_elsewhere' => $ranking, 'content_ids' => array_column($eligible, 'id')];
    }

    /**
     * The website's town-rank map for this keyword, sliced to the area: town-search mode when it has been
     * scanned, else searched-from-town, else null (no Town Rank scan at all).
     *
     * @param  array<string, true>  $areaIds
     * @param  array<string, array{lat: float, lng: float}>  $coords
     * @param  callable(float, float): array{float, float}  $project
     * @return array{mode: string, status: string|null, scanned_at: string|null, progress: array<string, mixed>|null, uncollected: int|null, summary: array<string, int>, markers: list<array{id: string, unreadable: bool, sections: bool, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}>}|null
     */
    private function webMap(Site $site, Keyword $keyword, array $areaIds, array $coords, callable $project, array $sectionsLive = []): ?array
    {
        $data = $this->report->forKeyword($site, $keyword);
        $mode = $data['scans'][TownRankScan::MODE_TOWN_QUERY] !== null ? TownRankScan::MODE_TOWN_QUERY : TownRankScan::MODE_LOCAL;
        $scan = $data['scans'][$mode];
        if ($scan === null) {
            return null;
        }
        $prefix = $mode === TownRankScan::MODE_LOCAL ? 'local' : 'town';

        $summary = ['top3' => 0, 'page1' => 0, 'page2' => 0, 'beyond' => 0, 'not_found' => 0, 'unreadable' => 0, 'pending' => 0];
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
            $markers[] = self::marker($id, $coords[$id], $project, $rank, (string) $row['label'], $row['state'], (int) $row['population'], $row['page_url'] !== null, $state === 'unreadable', isset($sectionsLive[$id]));
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
     * @return array{scan_id: string, status: string, scanned_at: string|null, summary: array<string, int>, markers: list<array{id: string, unreadable: bool, sections: bool, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}>}|null
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

        $summary = ['top3' => 0, 'top7' => 0, 'top10' => 0, 'beyond' => 0, 'absent' => 0, 'unreadable' => 0, 'pending' => 0];
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
            $unreadable = $point->read_error !== null;
            if ($point->collected_at === null && $scan->status === 'pending') {
                $summary['pending']++;
            } else {
                $summary[match (true) {
                    $unreadable => 'unreadable',
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
                $unreadable,
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
     * @param  array{score: int|null, previous: int|null, delta: int|null, baseline_scans: int, towns: int, page1_towns: int, top3_towns: int}|null  $score  Town Visibility over the area's towns
     * @return list<array{key: string, label: string, value: string|null, note: string}>
     */
    private function metrics(int $towns, ?array $web, ?array $gbp, ?array $score = null): array
    {
        $visibility = $score === null || $score['score'] === null ? null : $score['score'].' / 100'.match (true) {
            $score['delta'] === null => '',
            $score['delta'] > 0 => ' ▲'.$score['delta'],
            $score['delta'] < 0 => ' ▼'.abs($score['delta']),
            default => ' =',
        };
        $share = fn (int $n): ?string => $towns > 0 ? sprintf('%d%%', (int) round($n / $towns * 100)) : null;
        $webSummary = is_array($web['summary'] ?? null) ? $web['summary'] : [];
        $gbpSummary = is_array($gbp['summary'] ?? null) ? $gbp['summary'] : [];

        return [
            ['key' => 'web_page1_share', 'label' => 'Website page-1 share', 'value' => $web === null ? null : $share((int) ($webSummary['top3'] ?? 0) + (int) ($webSummary['page1'] ?? 0)), 'note' => 'towns where the site ranks 1–10 (provisional)'],
            ['key' => 'web_top3_share', 'label' => 'Website top-3 share', 'value' => $web === null ? null : $share((int) ($webSummary['top3'] ?? 0)), 'note' => 'towns where the site ranks 1–3 (provisional)'],
            ['key' => 'gbp_top3_share', 'label' => 'GBP top-3 share', 'value' => $gbp === null ? null : $share((int) ($gbpSummary['top3'] ?? 0)), 'note' => 'towns where the GBP is in the map pack (provisional)'],
            ['key' => 'score', 'label' => 'Town Visibility', 'value' => $visibility, 'note' => 'population-weighted share of these towns where the site ranks (top-3 full, page 1 most, page 2 a little)'.($score !== null && $score['baseline_scans'] >= 2 ? ' · movement vs the last two scans' : ($score !== null && $score['baseline_scans'] === 1 ? ' · movement since the last scan' : ''))],
        ];
    }

    /**
     * @param  array{lat: float, lng: float}  $c
     * @param  callable(float, float): array{float, float}  $project
     * @return array{id: string, unreadable: bool, sections: bool, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}
     */
    private static function marker(string $id, array $c, callable $project, ?int $rank, string $name, ?string $state, int $population, bool $page, bool $unreadable = false, bool $sections = false): array
    {
        [$x, $y] = $project($c['lat'], $c['lng']);

        return [
            'id' => $id,
            'unreadable' => $unreadable,
            'sections' => $sections,
            'x' => $x,
            'y' => $y,
            'rank' => $rank,
            'color' => $unreadable ? GeoGridPalette::UNREADABLE : GeoGridPalette::absolute($rank),
            'label' => $name.($state !== null && $state !== '' ? ", {$state}" : ''),
            'population' => $population,
            'page' => $page,
        ];
    }
}
