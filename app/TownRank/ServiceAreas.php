<?php

namespace App\TownRank;

use App\GeoGrid\CountyOutlines;
use App\GeoGrid\CoverageGrid;
use App\GeoGrid\GeoGridPalette;
use App\Integrations\Census\TigerwebGazetteer;
use App\Models\GeoGridScan;
use App\Models\JobCounty;
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
        private readonly CountyOutlines $outlines,
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
            $geoids = array_merge($geoids, $this->countyGeoIds($location));
        }
        $labels = $this->countyLabels(array_values(array_unique($geoids)));

        $areas = [];
        foreach ($locations as $location) {
            $cityState = $location->cityState();
            $areas[] = [
                'location_id' => (string) $location->id,
                'name' => (string) $location->name,
                'city' => $cityState['city'],
                'state' => $cityState['state'],
                'counties' => array_map(fn (string $g): array => ['geoid' => $g, 'label' => $labels[$g] ?? "County {$g}"], $this->countyGeoIds($location)),
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
     *     cards: list<array{
     *         keyword_id: string, query: string,
     *         web: array{mode: string, status: string|null, scanned_at: string|null, summary: array<string, int>, markers: list<array{id: string, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}>}|null,
     *         gbp: array{scan_id: string, status: string, scanned_at: string|null, summary: array<string, int>, markers: list<array{id: string, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}>}|null,
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

        $areaTowns = $this->coverage->pointsFor($location);
        $areaIds = array_fill_keys(array_column($areaTowns, 'coverage_area_id'), true);
        $siteTowns = [];
        foreach ($this->points->forSite($site) as $town) {
            if (isset($areaIds[$town['coverage_area_id']])) {
                $siteTowns[$town['coverage_area_id']] = $town;
            }
        }
        $coords = [];
        foreach ($areaTowns as $town) {
            $coords[$town['coverage_area_id']] = ['lat' => $town['lat'], 'lng' => $town['lng']];
        }
        $countyIds = $this->countyGeoIds($location);
        $rings = $this->outlines->for($countyIds);
        // The frame is the counties' extent when their outlines are known (so the whole county shows and the
        // dots sit inside it), else the towns' extent; one uniform scale keeps the county's true shape.
        $extent = $rings !== [] ? self::ringsExtent($rings) : self::pointsExtent($coords);
        $project = self::projector($extent);

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
                'metrics' => $this->metrics(count($areaTowns), $web, $gbp),
            ];
        }

        $cityState = $location->cityState();
        $labels = $this->countyLabels($countyIds);
        $outlines = [];
        foreach ($rings as $geoId => $countyRings) {
            $paths = [];
            foreach ($countyRings as $ring) {
                $d = '';
                foreach ($ring as $i => $pt) {
                    [$x, $y] = $project((float) $pt['lat'], (float) $pt['lng']);
                    $d .= ($i === 0 ? 'M' : 'L').$x.' '.$y.' ';
                }
                if ($d !== '') {
                    $paths[] = trim($d).' Z';
                }
            }
            $outlines[] = ['geoid' => (string) $geoId, 'label' => $labels[$geoId] ?? "County {$geoId}", 'paths' => $paths];
        }

        return [
            'location' => ['location_id' => (string) $location->id, 'name' => (string) $location->name, 'city' => $cityState['city'], 'state' => $cityState['state']],
            'counties' => array_map(fn (string $g): array => ['geoid' => $g, 'label' => $labels[$g] ?? "County {$g}"], $countyIds),
            'towns' => count($areaTowns),
            'outlines' => $outlines,
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
     * @return array{mode: string, status: string|null, scanned_at: string|null, summary: array<string, int>, markers: list<array{id: string, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}>}|null
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
        foreach ($scan->points as $point) {
            $id = (string) $point->coverage_area_id;
            if ($id === '' || ! isset($coords[$id])) {
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

        return [
            'scan_id' => (string) $scan->id,
            'status' => (string) $scan->status,
            'scanned_at' => $scan->scanned_at?->toDateTimeString(),
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

    /**
     * A lat/lng → SVG (0–100 viewBox) projector for one frame: the frame is centred, scaled uniformly (the
     * longitude span corrected by cos(latitude) so a county keeps its shape) to fit inside 6..94, north up.
     *
     * @param  array{minLat: float, maxLat: float, minLng: float, maxLng: float}  $extent
     * @return callable(float, float): array{float, float}
     */
    private static function projector(array $extent): callable
    {
        $midLat = ($extent['minLat'] + $extent['maxLat']) / 2;
        $midLng = ($extent['minLng'] + $extent['maxLng']) / 2;
        $cos = max(0.05, cos(deg2rad($midLat)));
        $spanX = ($extent['maxLng'] - $extent['minLng']) * $cos;
        $spanY = $extent['maxLat'] - $extent['minLat'];
        $span = max($spanX, $spanY);
        $scale = $span > 0 ? 88 / $span : 0.0;

        return fn (float $lat, float $lng): array => [
            round(50 + ($lng - $midLng) * $cos * $scale, 2),
            round(50 - ($lat - $midLat) * $scale, 2),
        ];
    }

    /**
     * @param  array<string, array{lat: float, lng: float}>  $coords
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    private static function pointsExtent(array $coords): array
    {
        $lats = array_column($coords, 'lat');
        $lngs = array_column($coords, 'lng');
        if ($lats === []) {
            return ['minLat' => 0.0, 'maxLat' => 0.0, 'minLng' => 0.0, 'maxLng' => 0.0];
        }

        return ['minLat' => min($lats), 'maxLat' => max($lats), 'minLng' => min($lngs), 'maxLng' => max($lngs)];
    }

    /**
     * @param  array<string, list<list<array{lat: float, lng: float}>>>  $rings
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    private static function ringsExtent(array $rings): array
    {
        $lats = [];
        $lngs = [];
        foreach ($rings as $countyRings) {
            foreach ($countyRings as $ring) {
                foreach ($ring as $pt) {
                    $lats[] = (float) $pt['lat'];
                    $lngs[] = (float) $pt['lng'];
                }
            }
        }
        if ($lats === []) {
            return ['minLat' => 0.0, 'maxLat' => 0.0, 'minLng' => 0.0, 'maxLng' => 0.0];
        }

        return ['minLat' => min($lats), 'maxLat' => max($lats), 'minLng' => min($lngs), 'maxLng' => max($lngs)];
    }

    /**
     * The 5-digit county GEOIDs the location serves — the same set the coverage grid scans.
     *
     * @return list<string>
     */
    private function countyGeoIds(Location $location): array
    {
        return collect([$location->home_county_geoid])
            ->merge(is_array($location->county_geoids) ? $location->county_geoids : [])
            ->map(fn ($g): string => trim((string) $g))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * "Warren County, NJ" per GEOID: from the county registry when it has the county, else the Census name
     * that came with the county's outline plus the state read off the GEOID; a county neither knows keeps
     * its GEOID as the label.
     *
     * @param  list<string>  $geoids
     * @return array<string, string>
     */
    private function countyLabels(array $geoids): array
    {
        if ($geoids === []) {
            return [];
        }
        $labels = [];
        foreach (JobCounty::query()->whereIn('county_geoid', $geoids)->get() as $county) {
            $labels[(string) $county->county_geoid] = self::countyLabel((string) $county->name, $county->state);
        }
        $missing = array_values(array_filter($geoids, fn (string $g): bool => ! isset($labels[$g])));
        if ($missing !== []) {
            foreach ($this->outlines->names($missing) as $geoId => $name) {
                $labels[(string) $geoId] = self::countyLabel($name, TigerwebGazetteer::stateForFips((string) $geoId));
            }
        }

        return $labels;
    }

    private static function countyLabel(string $name, ?string $state): string
    {
        $name = trim($name);
        if (! preg_match('/county|parish|borough|census area|municipio/i', $name)) {
            $name .= ' County';
        }

        return $name.($state !== null && $state !== '' ? ", {$state}" : '');
    }
}
