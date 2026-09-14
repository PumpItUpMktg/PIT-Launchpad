<?php

namespace App\TownRank;

use App\GeoGrid\GeoGridPalette;
use App\Models\GeoGridScan;
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
    ) {}

    /**
     * Keywords with at least one town-rank scan, most recently scanned first.
     *
     * @return list<array{keyword_id: string, query: string, scanned_at: string|null}>
     */
    public function keywords(Site $site): array
    {
        $latest = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByDesc('scanned_at')
            ->get(['keyword_id', 'scanned_at'])
            ->unique('keyword_id');
        if ($latest->isEmpty()) {
            return [];
        }

        $queries = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('id', $latest->pluck('keyword_id')->all())
            ->pluck('query', 'id')
            ->all();

        $out = [];
        foreach ($latest as $scan) {
            $out[] = [
                'keyword_id' => (string) $scan->keyword_id,
                'query' => (string) ($queries[$scan->keyword_id] ?? '—'),
                'scanned_at' => $scan->scanned_at?->toDateTimeString(),
            ];
        }

        return $out;
    }

    /**
     * The board for one keyword (the given id, else the most recently scanned) in one mode.
     *
     * @return array{
     *     keyword_id: string, keyword: string, mode: string,
     *     scan: array{id: string, status: string, scanned_at: string|null, points: int, found: int}|null,
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

        return [
            'keyword_id' => (string) $keyword->id,
            'keyword' => $data['keyword'],
            'mode' => $mode,
            'scan' => $data['scans'][$mode],
            'summary' => $data['summary'][$mode],
            'has_previous' => $data['scans'][$mode] !== null && $data['scans'][$mode]['previous_scanned_at'] !== null,
            'markers' => $this->markers($data['rows'], $prefix, $coords),
            'rows' => $data['rows'],
        ];
    }

    /**
     * The card wall: one card per scanned keyword — both modes' buckets, movement, when it was scanned, and a
     * thumbnail of the town map (coloured by the town-search rank when that mode is scanned, else the local
     * one). Click-through opens {@see for()} for the keyword.
     *
     * @return list<array{
     *     keyword_id: string, query: string, scanned_at: string|null, thumbnail_mode: string, has_previous: bool,
     *     modes: array<string, array{top3: int, page1: int, page2: int, beyond: int, not_found: int, pending: int, up: int, down: int, new: int, lost: int, same: int}|null>,
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

        $cards = [];
        foreach ($keywords as $entry) {
            $keyword = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($entry['keyword_id'])->first();
            if ($keyword === null) {
                continue;
            }
            $data = $this->report->forKeyword($site, $keyword);
            $thumbMode = $data['scans'][TownRankScan::MODE_TOWN_QUERY] !== null ? TownRankScan::MODE_TOWN_QUERY : TownRankScan::MODE_LOCAL;
            $modes = [];
            $hasPrevious = false;
            foreach (TownRankScan::MODES as $mode) {
                $modes[$mode] = $data['scans'][$mode] === null ? null : $data['summary'][$mode];
                $hasPrevious = $hasPrevious || ($data['scans'][$mode] !== null && $data['scans'][$mode]['previous_scanned_at'] !== null);
            }
            $cards[] = [
                'keyword_id' => (string) $keyword->id,
                'query' => $data['keyword'],
                'scanned_at' => $entry['scanned_at'],
                'thumbnail_mode' => $thumbMode,
                'has_previous' => $hasPrevious,
                'modes' => $modes,
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
    private function markers(array $rows, string $prefix, array $coords): array
    {
        $bbox = $this->boundingBox($coords);
        $latSpan = $bbox['maxLat'] - $bbox['minLat'];
        $lngSpan = $bbox['maxLng'] - $bbox['minLng'];

        $markers = [];
        foreach ($rows as $row) {
            $c = $coords[$row['coverage_area_id']] ?? null;
            if ($c === null) {
                continue;
            }
            $rank = $row["{$prefix}_rank"] !== null ? (int) $row["{$prefix}_rank"] : null;
            $prev = $row["{$prefix}_prev_rank"] !== null ? (int) $row["{$prefix}_prev_rank"] : null;
            $change = $row["{$prefix}_change"];
            $x = $lngSpan > 0 ? 6 + (($c['lng'] - $bbox['minLng']) / $lngSpan) * 88 : 50.0;
            $y = $latSpan > 0 ? 6 + (($bbox['maxLat'] - $c['lat']) / $latSpan) * 88 : 50.0;
            $markers[] = [
                'id' => (string) $row['coverage_area_id'],
                'x' => round($x, 2),
                'y' => round($y, 2),
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

        return TownRankPoint::withoutGlobalScope(SiteScope::class)
            ->where('scan_id', $scanId)->where('coverage_area_id', $coverageAreaId)->first();
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

    /**
     * One bounding box over every covered town, so a town sits in the same spot whichever mode is shown.
     *
     * @param  array<string, array{lat: float, lng: float}>  $coords
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    private function boundingBox(array $coords): array
    {
        $lats = [];
        $lngs = [];
        foreach ($coords as $c) {
            $lats[] = $c['lat'];
            $lngs[] = $c['lng'];
        }
        if ($lats === []) {
            return ['minLat' => 0.0, 'maxLat' => 0.0, 'minLng' => 0.0, 'maxLng' => 0.0];
        }

        return ['minLat' => min($lats), 'maxLat' => max($lats), 'minLng' => min($lngs), 'maxLng' => max($lngs)];
    }
}
