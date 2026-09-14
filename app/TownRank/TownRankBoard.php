<?php

namespace App\TownRank;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\GeoGrid\GeoGridPalette;
use App\Models\Content;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use Illuminate\Support\Str;

/**
 * The operator Town Rank board read-model (§ Town Rank, PR 2): one page per keyword — the site's whole covered
 * footprint as a town scatter coloured by the website's organic rank (in either query mode), the bucket
 * summary, the town table, and a per-town detail with the suggested actions ({@see TownDiagnosis}). Built on
 * {@see TownRankReport}; adds the map geometry (all towns share one bounding box, north-up), the keyword
 * selector (keywords that have been scanned), and the town detail (competitors above us, page state incl.
 * "published but un-anchored", map-pack rank).
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
     *     summary: array{top3: int, page1: int, page2: int, beyond: int, not_found: int, pending: int},
     *     markers: list<array{id: string, x: float, y: float, rank: int|null, color: string, label: string, population: int, page: bool}>,
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
        $bbox = $this->boundingBox($coords);
        $latSpan = $bbox['maxLat'] - $bbox['minLat'];
        $lngSpan = $bbox['maxLng'] - $bbox['minLng'];

        $markers = [];
        foreach ($data['rows'] as $row) {
            $c = $coords[$row['coverage_area_id']] ?? null;
            if ($c === null) {
                continue;
            }
            $rank = $row["{$prefix}_rank"] !== null ? (int) $row["{$prefix}_rank"] : null;
            // 6..94 padding so edge dots aren't clipped; a single-town span collapses to the centre.
            $x = $lngSpan > 0 ? 6 + (($c['lng'] - $bbox['minLng']) / $lngSpan) * 88 : 50.0;
            $y = $latSpan > 0 ? 6 + (($bbox['maxLat'] - $c['lat']) / $latSpan) * 88 : 50.0;
            $markers[] = [
                'id' => $row['coverage_area_id'],
                'x' => round($x, 2),
                'y' => round($y, 2),
                'rank' => $rank,
                'color' => GeoGridPalette::absolute($rank),
                'label' => $row['label'].($row['state'] !== null ? ', '.$row['state'] : ''),
                'population' => $row['population'],
                'page' => $row['page_url'] !== null,
            ];
        }

        return [
            'keyword_id' => (string) $keyword->id,
            'keyword' => $data['keyword'],
            'mode' => $mode,
            'scan' => $data['scans'][$mode],
            'summary' => $data['summary'][$mode],
            'markers' => $markers,
            'rows' => $data['rows'],
        ];
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
                $domain = strtolower(preg_replace('/^www\./i', '', (string) ($item['domain'] ?? '')) ?? '');
                if ($domain === '' || $domain === $host) {
                    continue;
                }
                if ($rank !== null && (int) ($item['position'] ?? 0) > $rank) {
                    continue;   // only who is ABOVE us
                }
                $competitors[] = ['position' => (int) ($item['position'] ?? 0), 'domain' => $domain, 'url' => (string) ($item['url'] ?? '')];
            }
            $modes[$mode] = [
                'rank' => $rank,
                'state' => (string) $row["{$prefix}_state"],
                'url' => $row["{$prefix}_url"],
                'query' => $point?->query,
                'competitors' => $competitors,
            ];
        }

        $mapScanned = $this->mapScanned($site, $keyword);
        $detail = [
            'id' => $coverageAreaId,
            'label' => $row['label'].($row['state'] !== null ? ', '.$row['state'] : ''),
            'population' => $row['population'],
            'page_url' => $row['page_url'],
            'page_state' => $this->pageState($site, $row),
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

    /**
     * anchored (a published page joined by GEOID) | unanchored (a published location page whose slug is this
     * town's slug, but no GEOID join — the report can't see it) | none.
     *
     * @param  array<string, mixed>  $row
     */
    private function pageState(Site $site, array $row): string
    {
        if ($row['page_url'] !== null) {
            return 'anchored';
        }
        $slug = Str::slug((string) $row['label']).($row['state'] !== null ? '-'.strtolower((string) $row['state']) : '');
        $exists = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->where('slug', $slug)
            ->exists();

        return $exists ? 'unanchored' : 'none';
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
