<?php

namespace App\GeoGrid;

use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\TownRank\TownPointLinks;
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
    public function __construct(private readonly TownAreaMap $map) {}

    public function for(Location $location): array
    {
        $scans = GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('location_id', $location->id)
            // Town centres, not a lattice: one Maps search per served town, from that town's own coordinates.
            ->where('mode', 'coverage')
            ->with('points')
            ->orderByDesc('scanned_at')
            ->get();

        $labels = $this->keywordLabels($location, $scans);
        // The area's drawing frame, built once and shared by every card: county outline, each town's own
        // boundary, and the projector the website's town map uses — so the same town sits on the same spot.
        $frame = $this->map->for($location);

        $cards = $scans->groupBy('keyword_id')
            ->map(function (Collection $forKeyword) use ($labels, $frame): array {
                /** @var GeoGridScan $latest */
                $latest = $forKeyword->first();
                $previous = $forKeyword->skip(1)->first();

                return $this->card($latest, $previous, (string) ($labels[$latest->keyword_id] ?? '—'), $frame);
            })
            // Worst ATRP first — the operator wants the weakest keyword to catch the eye.
            ->sortByDesc(fn (array $card): float => $card['atrp'] ?? -1)
            ->values()
            ->all();

        return [
            'location_id' => (string) $location->id,
            'keyword_count' => count($cards),
            'towns' => count($frame['towns']),
            'outlines' => $frame['outlines'],
            'counties' => $frame['counties'],
            'town_paths' => $frame['town_paths'],
            'cards' => $cards,
        ];
    }

    /**
     * @param  array<string, mixed>  $frame
     * @return array{
     *     keyword_id: string, keyword: string, scan_id: string, status: string, scanned_at: ?string,
     *     depth_cap: int, atrp: ?float, arp: ?float, solv: ?float, found_rate: ?float, pop_found_rate: ?float,
     *     pop_solv: ?float, delta_atrp: ?float, prev_scanned_at: ?string, summary: array<string, int>,
     *     towns: list<array<string, mixed>>
     * }
     */
    private function card(GeoGridScan $latest, ?GeoGridScan $previous, string $keyword, array $frame): array
    {
        $previousRanks = $previous === null ? [] : $this->ranksByTown($previous, $frame);
        $towns = [];
        $summary = ['top3' => 0, 'top7' => 0, 'top10' => 0, 'beyond' => 0, 'absent' => 0, 'unreadable' => 0, 'pending' => 0];

        foreach (TownPointLinks::byTown($frame['towns'], $latest->points) as $townId => $point) {
            $id = (string) $townId;
            $coords = $frame['coords'][$id] ?? null;
            if ($coords === null) {
                continue;
            }
            $rank = $point->rank !== null ? (int) $point->rank : null;
            $pending = $point->collected_at === null && $latest->status === 'pending';
            $unreadable = $point->read_error !== null;
            [$x, $y] = $frame['project']($coords['lat'], $coords['lng']);
            $prevRank = $previousRanks[$id] ?? null;
            $town = collect($frame['towns'])->firstWhere('coverage_area_id', $id);

            $summary[match (true) {
                $pending => 'pending',
                $unreadable => 'unreadable',
                $rank === null => 'absent',
                $rank <= 3 => 'top3',
                $rank <= 7 => 'top7',
                $rank <= 10 => 'top10',
                default => 'beyond',
            }]++;

            $towns[] = [
                'id' => $id,
                'label' => (string) ($town['label'] ?? $point->label ?? '—'),
                'x' => $x,
                'y' => $y,
                'rank' => $rank,
                'prev_rank' => $prevRank,
                'pending' => $pending,
                'competitors' => is_array($point->competitors) ? $point->competitors : [],
                'unreadable' => $unreadable,
                'color' => $unreadable ? GeoGridPalette::UNREADABLE : GeoGridPalette::absolute($rank),
                'delta_color' => $unreadable ? GeoGridPalette::UNREADABLE : GeoGridPalette::delta($rank, $prevRank),
                'move' => GeoGridPalette::move($rank, $prevRank),
                'population' => (int) ($town['population'] ?? 0),
            ];
        }

        // Best first: the pack positions an operator reads down the list.
        usort($towns, fn (array $a, array $b): int => [$a['rank'] === null, $a['rank'] ?? 0] <=> [$b['rank'] === null, $b['rank'] ?? 0]);

        return [
            'keyword_id' => (string) $latest->keyword_id,
            'keyword' => $keyword,
            'scan_id' => (string) $latest->id,
            'status' => (string) $latest->status,
            'scanned_at' => $latest->scanned_at?->toDateTimeString(),
            'depth_cap' => (int) $latest->depth_cap,
            'atrp' => $this->num($latest->atrp),
            'arp' => $this->num($latest->arp),
            'solv' => $this->num($latest->solv),
            'found_rate' => $this->num($latest->found_rate),
            'pop_found_rate' => $this->num($latest->pop_found_rate),
            'pop_solv' => $this->num($latest->pop_solv),
            'delta_atrp' => $this->deltaAtrp($latest, $previous),
            'prev_scanned_at' => $previous?->scanned_at?->toDateTimeString(),
            'summary' => $summary,
            'towns' => $towns,
        ];
    }

    /**
     * The previous scan's rank per CURRENT town, for the movement colouring.
     *
     * @param  array<string, mixed>  $frame
     * @return array<string, int|null>
     */
    private function ranksByTown(GeoGridScan $scan, array $frame): array
    {
        $out = [];
        foreach (TownPointLinks::byTown($frame['towns'], $scan->points) as $townId => $point) {
            $out[(string) $townId] = $point->rank !== null ? (int) $point->rank : null;
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
