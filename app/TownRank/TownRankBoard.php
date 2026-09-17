<?php

namespace App\TownRank;

use App\GeoGrid\CountyOutlines;
use App\GeoGrid\GeoGridPalette;
use App\GeoGrid\MapProjection;
use App\GeoGrid\TownOutlines;
use App\Jobs\WarmTownOutlines;
use App\Models\GeoGridScan;
use App\Models\JobCounty;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;

/**
 * The operator Town Rank board read-model (§ Town Rank, PR 2): one page per keyword — the site's whole covered
 * footprint as a town scatter coloured by the website's organic rank (in either query mode), the bucket
 * summary, the town table, and a per-town detail with the suggested actions ({@see TownDiagnosis}). Built on
 * {@see TownRankReport}; adds the map geometry (all towns share one bounding box, north-up), the keyword
 * selector (keywords that have been scanned), movement since the previous scan, and the town detail
 * (competitors above us, page state incl. "found by slug, not by GEOID", map-pack rank).
 *
 * Operator context crosses tenants, so the {@see SiteScope} is dropped and site_id filtered explicitly.
 */
final class TownRankBoard
{
    public function __construct(
        private readonly TownRankReport $report,
        private readonly TownRankPoints $points,
        private readonly CountyOutlines $counties,
        private readonly TownOutlines $townOutlines,
    ) {}

    /**
     * The wall's keyword set: every keyword with a town-rank scan, plus the ones tracked for Town Rank or
     * flagged for the geo grid (the sweep's set) that have not been scanned yet. Scanned keywords first, most
     * recent first; unscanned after, by query. `pending` = a scan is still collecting.
     *
     * @return list<array{keyword_id: string, query: string, scanned_at: string|null, pending: bool}>
     */
    public function keywords(Site $site): array
    {
        $scans = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByDesc('scanned_at')
            ->get(['keyword_id', 'scanned_at', 'status']);
        $latest = $scans->unique('keyword_id');
        $pendingIds = $scans->where('status', 'pending')->pluck('keyword_id')->flip();

        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->where('track_town_rank', true)->orWhere('is_grid_keyword', true)->orWhereIn('id', $latest->pluck('keyword_id')->all()))
            ->orderBy('query')
            ->get(['id', 'query'])
            ->keyBy('id');

        $out = [];
        foreach ($latest as $scan) {
            $keyword = $keywords->get($scan->keyword_id);
            if ($keyword === null) {
                continue;
            }
            $out[] = [
                'keyword_id' => (string) $scan->keyword_id,
                'query' => (string) $keyword->query,
                'scanned_at' => $scan->scanned_at?->toDateTimeString(),
                'pending' => $pendingIds->has($scan->keyword_id),
            ];
        }
        $scannedIds = array_flip(array_column($out, 'keyword_id'));
        foreach ($keywords as $id => $keyword) {
            if (isset($scannedIds[(string) $id])) {
                continue;
            }
            $out[] = ['keyword_id' => (string) $id, 'query' => (string) $keyword->query, 'scanned_at' => null, 'pending' => false];
        }

        return $out;
    }

    /**
     * The board for one keyword (the given id, else the most recently scanned) in one mode.
     *
     * @return array{
     *     keyword_id: string, keyword: string, mode: string,
     *     scan: array{id: string, status: string, scanned_at: string|null, points: int, collected: int, found: int, previous_scanned_at: string|null}|null,
     *     outlines: list<array{geoid: string, label: string, paths: list<string>}>,
     *     town_paths: array<string, list<string>>,
     *     progress: array{collected: int, points: int, remaining: int, eta_seconds: int|null, eta: string|null}|null,
     *     uncollected: int|null,
     *     summary: array{top3: int, page1: int, page2: int, beyond: int, not_found: int, pending: int, up: int, down: int, new: int, lost: int, same: int},
     *     has_previous: bool,
     *     markers: list<array{id: string, x: float, y: float, rank: int|null, prev_rank: int|null, change: string|null, color: string, delta_color: string, label: string, population: int, page: bool}>,
     *     rows: list<array<string, mixed>>
     * }|null
     */
    public function for(Site $site, ?string $keywordId, string $mode): ?array
    {
        $keyword = $this->resolveKeyword($site, $keywordId);
        if ($keyword === null) {
            return null;
        }
        $mode = in_array($mode, TownRankScan::MODES, true) ? $mode : TownRankScan::MODE_TOWN_QUERY;
        $prefix = $mode === TownRankScan::MODE_LOCAL ? 'local' : 'town';

        $data = $this->report->forKeyword($site, $keyword);
        $coords = $this->coords($site);
        $frame = $this->frame($site, $coords);

        $scan = $data['scans'][$mode];

        return [
            'keyword_id' => (string) $keyword->id,
            'keyword' => $data['keyword'],
            'mode' => $mode,
            'scan' => $scan,
            'progress' => $scan !== null && $scan['status'] === 'pending'
                ? CollectionProgress::for((int) $scan['collected'], (int) $scan['points'])
                : null,
            'uncollected' => $scan !== null && $scan['status'] === 'partial'
                ? max(0, (int) $scan['points'] - (int) $scan['collected'])
                : null,
            'summary' => $data['summary'][$mode],
            'has_previous' => $scan !== null && $scan['previous_scanned_at'] !== null,
            'markers' => $this->markers($data['rows'], $prefix, $coords, $frame['project']),
            'outlines' => $frame['outlines'],
            'town_paths' => $frame['town_paths'],
            'rows' => $data['rows'],
        ];
    }

    /**
     * The card wall: one card per scanned keyword — both modes' buckets, movement, when it was scanned, and a
     * thumbnail of the town map (coloured by the town-search rank when that mode is scanned, else the local
     * one). Click-through opens {@see for()} for the keyword.
     *
     * @return list<array{
     *     keyword_id: string, query: string, scanned_at: string|null, pending: bool, towns: int, thumbnail_mode: string, has_previous: bool,
     *     modes: array<string, array{top3: int, page1: int, page2: int, beyond: int, not_found: int, pending: int, up: int, down: int, new: int, lost: int, same: int}|null>,
     *     progress: array<string, array{collected: int, points: int, remaining: int, eta_seconds: int|null, eta: string|null}|null>,
     *     uncollected: array<string, int|null>,
     *     markers: list<array{id: string, x: float, y: float, rank: int|null, prev_rank: int|null, change: string|null, color: string, delta_color: string, label: string, population: int, page: bool}>
     * }>
     */
    public function cards(Site $site): array
    {
        $keywords = $this->keywords($site);
        if ($keywords === []) {
            return [];
        }
        $coords = $this->coords($site);
        $towns = count($coords);

        $cards = [];
        foreach ($keywords as $entry) {
            $keyword = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($entry['keyword_id'])->first();
            if ($keyword === null) {
                continue;
            }
            $data = $this->report->forKeyword($site, $keyword);
            $thumbMode = $data['scans'][TownRankScan::MODE_TOWN_QUERY] !== null ? TownRankScan::MODE_TOWN_QUERY : TownRankScan::MODE_LOCAL;
            $modes = [];
            $progress = [];
            $uncollected = [];
            $hasPrevious = false;
            foreach (TownRankScan::MODES as $mode) {
                $scan = $data['scans'][$mode];
                $modes[$mode] = $scan === null ? null : $data['summary'][$mode];
                $progress[$mode] = $scan !== null && $scan['status'] === 'pending'
                    ? CollectionProgress::for((int) $scan['collected'], (int) $scan['points'])
                    : null;
                // A scan that closed without every town is stated as such, with the number it never got —
                // "complete" over 40 missing towns is the lie this replaces.
                $uncollected[$mode] = $scan !== null && $scan['status'] === 'partial'
                    ? max(0, (int) $scan['points'] - (int) $scan['collected'])
                    : null;
                $hasPrevious = $hasPrevious || ($scan !== null && $scan['previous_scanned_at'] !== null);
            }
            $cards[] = [
                'keyword_id' => (string) $keyword->id,
                'query' => $data['keyword'],
                'scanned_at' => $entry['scanned_at'],
                'pending' => $entry['pending'],
                'towns' => $towns,
                'thumbnail_mode' => $thumbMode,
                'has_previous' => $hasPrevious,
                'modes' => $modes,
                'progress' => $progress,
                'uncollected' => $uncollected,
                'markers' => $this->markers($data['rows'], $thumbMode === TownRankScan::MODE_LOCAL ? 'local' : 'town', $coords),
            ];
        }

        return $cards;
    }

    /**
     * Town markers normalised into one shared bounding box (north-up, 6..94 padding so edge dots aren't
     * clipped; a single-town span collapses to the centre), coloured by the given mode's rank.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array{lat: float, lng: float}>  $coords
     * @return list<array{id: string, x: float, y: float, rank: int|null, prev_rank: int|null, change: string|null, color: string, delta_color: string, label: string, population: int, page: bool}>
     */
    private function markers(array $rows, string $prefix, array $coords, ?callable $project = null): array
    {
        $project ??= MapProjection::projector(MapProjection::pointsExtent($coords));

        $markers = [];
        foreach ($rows as $row) {
            $c = $coords[$row['coverage_area_id']] ?? null;
            if ($c === null) {
                continue;
            }
            $rank = $row["{$prefix}_rank"] !== null ? (int) $row["{$prefix}_rank"] : null;
            $prev = $row["{$prefix}_prev_rank"] !== null ? (int) $row["{$prefix}_prev_rank"] : null;
            $change = $row["{$prefix}_change"];
            [$x, $y] = $project($c['lat'], $c['lng']);
            $markers[] = [
                'id' => (string) $row['coverage_area_id'],
                'x' => $x,
                'y' => $y,
                'rank' => $rank,
                'prev_rank' => $prev,
                'change' => is_string($change) ? $change : null,
                'color' => GeoGridPalette::absolute($rank),
                'delta_color' => $change === null ? GeoGridPalette::ABSENT : GeoGridPalette::delta($rank, $prev),
                'label' => (string) $row['label'].($row['state'] !== null ? ', '.$row['state'] : ''),
                'population' => (int) $row['population'],
                'page' => $row['page_url'] !== null,
            ];
        }

        return $markers;
    }

    /**
     * The whole-site drawing frame: the served counties' outlines, each town's own boundary, and the shared
     * projector — the same geography the Service Areas and Geo Grid maps draw on, so a town sits on the same
     * spot everywhere.
     *
     * Counties are few and are fetched normally. Town boundaries are read CACHE-ONLY: a site covers ~700
     * towns and fetching those inside a page render is the shape of request that used to time this page out.
     * A town not cached yet keeps its dot, and {@see WarmTownOutlines} fills the gap off-request
     * so its shape appears on a later view.
     *
     * @param  array<string, array{lat: float, lng: float}>  $coords
     * @return array{project: callable(float, float): array{float, float}, outlines: list<array{geoid: string, label: string, paths: list<string>}>, town_paths: array<string, list<string>>, shaped: int}
     */
    private function frame(Site $site, array $coords): array
    {
        $townGeoIds = [];
        $countyIds = [];
        foreach ($this->points->forSite($site) as $town) {
            $geoId = trim((string) ($town['geo_id'] ?? ''));
            if ($geoId === '' || ! isset($coords[(string) $town['coverage_area_id']])) {
                continue;
            }
            $townGeoIds[(string) $town['coverage_area_id']] = $geoId;
            $countyIds[substr($geoId, 0, 5)] = substr($geoId, 0, 5);
        }

        $countyRings = $this->counties->for(array_values($countyIds));
        $townRings = $this->townOutlines->for(array_values($townGeoIds), fetchMissing: false);

        $project = MapProjection::projector(MapProjection::unionExtent([
            MapProjection::ringsExtent($countyRings),
            MapProjection::ringsExtent($townRings),
            MapProjection::pointsExtent($coords),
        ]));

        $townPaths = [];
        foreach ($townGeoIds as $id => $geoId) {
            if (isset($townRings[$geoId])) {
                $townPaths[(string) $id] = MapProjection::paths($townRings[$geoId], $project);
            }
        }

        $labels = $this->countyLabels(array_values($countyIds));
        $outlines = [];
        foreach ($countyRings as $geoId => $rings) {
            $outlines[] = ['geoid' => (string) $geoId, 'label' => $labels[$geoId] ?? "County {$geoId}", 'paths' => MapProjection::paths($rings, $project)];
        }

        return ['project' => $project, 'outlines' => $outlines, 'town_paths' => $townPaths, 'shaped' => count($townPaths)];
    }

    /**
     * "Warren County, NJ" per GEOID, from the county registry, else the Census name that came with the
     * outline plus the state read off the GEOID.
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
        foreach (JobCounty::query()->withoutGlobalScope(SiteScope::class)->whereIn('county_geoid', $geoids)->get() as $county) {
            $name = trim((string) $county->name);
            $name .= preg_match('/county|parish|borough|census area|municipio/i', $name) ? '' : ' County';
            $labels[(string) $county->county_geoid] = $name.($county->state !== null && $county->state !== '' ? ', '.$county->state : '');
        }

        return $labels;
    }

    /**
     * One town's detail for the side panel: both modes' ranks + who sits above us, the page state, the
     * map-pack rank, and the suggested actions.
     *
     * @return array<string, mixed>|null
     */
    public function town(Site $site, string $keywordId, string $coverageAreaId): ?array
    {
        $keyword = $this->resolveKeyword($site, $keywordId);
        if ($keyword === null) {
            return null;
        }
        $data = $this->report->forKeyword($site, $keyword);
        $row = null;
        foreach ($data['rows'] as $r) {
            if ($r['coverage_area_id'] === $coverageAreaId) {
                $row = $r;
                break;
            }
        }
        if ($row === null) {
            return null;
        }

        $host = TownRankScanner::host($site->domain_url);
        $modes = [];
        foreach (TownRankScan::MODES as $mode) {
            $prefix = $mode === TownRankScan::MODE_LOCAL ? 'local' : 'town';
            $point = $this->latestPoint($site, $keyword, $mode, $coverageAreaId);
            $rank = $row["{$prefix}_rank"] !== null ? (int) $row["{$prefix}_rank"] : null;
            $results = is_array($point?->top_results) ? $point->top_results : [];
            $competitors = [];
            foreach ($results as $item) {
                $domain = strtolower(preg_replace('/^www\./i', '', $item['domain']) ?? $item['domain']);
                if ($domain === '' || $domain === $host) {
                    continue;
                }
                if ($rank !== null && $item['position'] > $rank) {
                    continue;   // only who is ABOVE us
                }
                $competitors[] = ['position' => $item['position'], 'domain' => $domain, 'url' => $item['url']];
            }
            $modes[$mode] = [
                'rank' => $rank,
                'state' => (string) $row["{$prefix}_state"],
                'url' => $row["{$prefix}_url"],
                'query' => $point?->query,
                'prev_rank' => $row["{$prefix}_prev_rank"] !== null ? (int) $row["{$prefix}_prev_rank"] : null,
                'change' => is_string($row["{$prefix}_change"]) ? $row["{$prefix}_change"] : null,
                'competitors' => $competitors,
            ];
        }

        $mapScanned = $this->mapScanned($site, $keyword);
        $detail = [
            'id' => $coverageAreaId,
            'label' => $row['label'].($row['state'] !== null ? ', '.$row['state'] : ''),
            'population' => $row['population'],
            'page_url' => $row['page_url'],
            'page_state' => match ($row['page_match']) {
                'geoid' => 'anchored',
                'slug' => 'slug',
                default => 'none',
            },
            'local' => $modes[TownRankScan::MODE_LOCAL],
            'town_query' => $modes[TownRankScan::MODE_TOWN_QUERY],
            'map_rank' => $row['map_rank'],
            'map_scanned' => $mapScanned,
        ];
        $detail['actions'] = TownDiagnosis::for($detail);

        return $detail;
    }

    private function resolveKeyword(Site $site, ?string $keywordId): ?Keyword
    {
        $id = $keywordId;
        if ($id === null) {
            $id = TownRankScan::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->orderByDesc('scanned_at')->value('keyword_id');
        }
        if ($id === null) {
            return null;
        }

        return Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($id)->first();
    }

    private function latestPoint(Site $site, Keyword $keyword, string $mode, string $coverageAreaId): ?TownRankPoint
    {
        $scanId = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', $mode)
            ->orderByDesc('scanned_at')->value('id');
        if ($scanId === null) {
            return null;
        }

        // Linked, not keyed: after a coverage rebuild the stored row id no longer matches this town.
        return TownPointLinks::byTown($this->points->forSite($site), TownRankPoint::withoutGlobalScope(SiteScope::class)
            ->where('scan_id', $scanId)->get())->get($coverageAreaId);
    }

    private function mapScanned(Site $site, Keyword $keyword): bool
    {
        return GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('keyword_id', $keyword->id)->where('mode', 'coverage')
            ->whereIn('status', ['complete', 'partial'])
            ->exists();
    }

    /** @return array<string, array{lat: float, lng: float}> */
    private function coords(Site $site): array
    {
        $out = [];
        foreach ($this->points->forSite($site) as $town) {
            $out[$town['coverage_area_id']] = ['lat' => $town['lat'], 'lng' => $town['lng']];
        }

        return $out;
    }
}
