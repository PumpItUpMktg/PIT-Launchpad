<?php

namespace App\GeoGrid;

use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Collection;

/**
 * Assembles the operator geo-grid "small multiples" view for one location: one card per grid keyword, each
 * carrying the latest scan's heat-map matrix plus its delta against the previous scan. Pure read-model — it
 * reads stored {@see GeoGridScan}s and their {@see GeoGridPoint}s (the source of truth) and
 * derives nothing that a rescan would be needed for; aggregates come straight off the scan row.
 *
 * The matrix is emitted NORTH-UP: geometry row 0 is the southernmost cell ({@see GeoGridGeometry}), so it is
 * flipped to display row (grid_size − 1 − geoRow) here, matching how a Local Falcon grid reads. Both absolute
 * and delta colors are attached per cell (via {@see GeoGridPalette}) so the view can toggle without a round
 * trip — absolute is the operator default, delta is pre-wired for the eventual client view.
 *
 * Operator context crosses tenants, so every query drops {@see SiteScope} and filters on site_id explicitly.
 */
final class GeoGridBoard
{
    /**
     * @return array{
     *     location_id: string,
     *     keyword_count: int,
     *     cards: list<array{
     *         keyword_id: string, keyword: string,
     *         scan_id: string, status: string, scanned_at: ?string, grid_size: int, depth_cap: int,
     *         atrp: ?float, arp: ?float, solv: ?float, found_rate: ?float,
     *         delta_atrp: ?float, prev_scanned_at: ?string,
     *         matrix: list<list<array{row:int, col:int, rank:?int, lat:float, lng:float,
     *             competitors: list<array{name:string, place_id:?string, rank:?int}>,
     *             absolute_color:string, delta_color:string, move:?int}>>
     *     }>
     * }
     */
    public function for(Location $location): array
    {
        $scans = GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('location_id', $location->id)
            ->where('mode', 'grid')   // the square small-multiples view; coverage-mode scans render elsewhere
            ->with('points')
            ->orderByDesc('scanned_at')
            ->get();

        $labels = $this->keywordLabels($location, $scans);

        $cards = $scans->groupBy('keyword_id')
            ->map(function (Collection $forKeyword) use ($labels): array {
                /** @var GeoGridScan $latest */
                $latest = $forKeyword->first();
                $previous = $forKeyword->skip(1)->first();

                return $this->card($latest, $previous, (string) ($labels[$latest->keyword_id] ?? '—'));
            })
            // Worst ATRP first — the operator wants the weakest keyword's grid to catch the eye.
            ->sortByDesc(fn (array $card): float => $card['atrp'] ?? -1)
            ->values()
            ->all();

        return [
            'location_id' => (string) $location->id,
            'keyword_count' => count($cards),
            'cards' => $cards,
        ];
    }

    /**
     * @return array{
     *     keyword_id: string, keyword: string, scan_id: string, status: string, scanned_at: ?string,
     *     grid_size: int, depth_cap: int, atrp: ?float, arp: ?float, solv: ?float, found_rate: ?float,
     *     delta_atrp: ?float, prev_scanned_at: ?string, matrix: list<list<array<string, mixed>>>
     * }
     */
    private function card(GeoGridScan $latest, ?GeoGridScan $previous, string $keyword): array
    {
        $gridSize = (int) $latest->grid_size;
        $depthCap = (int) $latest->depth_cap;
        $prevByCell = $this->rankByCell($previous);

        // Build the display matrix north-up: display row 0 = northernmost = highest geometry row.
        $matrix = [];
        for ($displayRow = 0; $displayRow < $gridSize; $displayRow++) {
            $geoRow = $gridSize - 1 - $displayRow;
            $row = [];
            for ($col = 0; $col < $gridSize; $col++) {
                $row[] = $this->cell($latest, $geoRow, $col, $prevByCell);
            }
            $matrix[] = $row;
        }

        return [
            'keyword_id' => (string) $latest->keyword_id,
            'keyword' => $keyword,
            'scan_id' => (string) $latest->id,
            'status' => (string) $latest->status,
            'scanned_at' => $latest->scanned_at?->toDateTimeString(),
            'grid_size' => $gridSize,
            'depth_cap' => $depthCap,
            'atrp' => $this->num($latest->atrp),
            'arp' => $this->num($latest->arp),
            'solv' => $this->num($latest->solv),
            'found_rate' => $this->num($latest->found_rate),
            'delta_atrp' => $this->deltaAtrp($latest, $previous),
            'prev_scanned_at' => $previous?->scanned_at?->toDateTimeString(),
            'matrix' => $matrix,
        ];
    }

    /**
     * @param  array<string, ?int>  $prevByCell  "row:col" => previous rank
     * @return array{row:int, col:int, rank:?int, lat:float, lng:float, competitors:list<array<string,mixed>>,
     *     absolute_color:string, delta_color:string, move:?int}
     */
    private function cell(GeoGridScan $latest, int $geoRow, int $col, array $prevByCell): array
    {
        $point = $latest->points->first(
            fn ($p): bool => (int) $p->row === $geoRow && (int) $p->col === $col
        );

        $rank = $point?->rank !== null ? (int) $point->rank : null;
        $prevRank = $prevByCell["{$geoRow}:{$col}"] ?? null;

        return [
            'row' => $geoRow,
            'col' => $col,
            'rank' => $rank,
            'lat' => (float) ($point->lat ?? 0),
            'lng' => (float) ($point->lng ?? 0),
            'competitors' => is_array($point?->competitors) ? $point->competitors : [],
            'absolute_color' => GeoGridPalette::absolute($rank),
            'delta_color' => GeoGridPalette::delta($rank, $prevRank),
            'move' => GeoGridPalette::move($rank, $prevRank),
        ];
    }

    /**
     * Previous scan's rank keyed by "row:col", for the per-point delta overlay.
     *
     * @return array<string, ?int>
     */
    private function rankByCell(?GeoGridScan $scan): array
    {
        if ($scan === null) {
            return [];
        }

        $out = [];
        foreach ($scan->points as $p) {
            $out[((int) $p->row).':'.((int) $p->col)] = $p->rank !== null ? (int) $p->rank : null;
        }

        return $out;
    }

    /** ATRP change latest − previous (negative = improved, since lower rank is better), or null. */
    private function deltaAtrp(GeoGridScan $latest, ?GeoGridScan $previous): ?float
    {
        if ($previous === null || $latest->atrp === null || $previous->atrp === null) {
            return null;
        }

        return round((float) $latest->atrp - (float) $previous->atrp, 2);
    }

    /**
     * Keyword query text for every keyword_id present in the scans, tenant-scope dropped.
     *
     * @param  Collection<int, GeoGridScan>  $scans
     * @return array<string, string>
     */
    private function keywordLabels(Location $location, Collection $scans): array
    {
        $ids = $scans->pluck('keyword_id')->unique()->all();
        if ($ids === []) {
            return [];
        }

        return Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->whereIn('id', $ids)
            ->pluck('query', 'id')
            ->all();
    }

    private function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
