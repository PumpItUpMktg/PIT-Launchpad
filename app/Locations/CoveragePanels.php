<?php

namespace App\Locations;

use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * The tabbed page-selection view-model: reads the PERSISTED coverage_areas (the single source
 * of truth — so the hero, tab badges, and bottom bar can never disagree) and shapes them into
 * site totals + one panel per location, with towns grouped by roll-out BAND ({@see CoverageBand}: size
 * tiers for a county-drawn territory, distance rings for a proximity one). The page is thin over
 * this; selection toggles write straight to coverage_areas and this re-reads.
 *
 * Each panel also carries the {@see TierGate} lock state per tier band (5g), so a locked tier reads as
 * locked in the Service-area editor — not only on the Tier-progression board. Advisory: the lock gates
 * BUILDING (a locked tier's selected towns don't materialize until it unlocks), never the operator's
 * selection, so the badge is informational.
 */
final class CoveragePanels
{
    /** The county-mode band order; 'ungrouped' (size_tier null) always last. */
    public const TIERS = CoverageBand::COUNTY_CHAIN;

    public function __construct(private readonly TierGate $gate) {}

    /**
     * @param  Collection<int, Location>  $locations
     *                                                `band_meta` is the label + swatch for every band any of these locations builds through (the hero
     *                                                legend); each panel's `chain` is ITS ordered bands (the town groups render in that order).
     * @return array{
     *     totals: array{covered: int, selected: int, overlap: int, tiers: array<string, int>},
     *     band_meta: array<string, array{label: string, color: string}>,
     *     panels: array<string, array{town_count: int, selected_count: int, chain: list<string>, proximity: bool, tiers: array<string, int>, groups: array<string, list<array<string, mixed>>>, tier_locks: array<string, array{locked: bool, reason: string}>}>
     * }
     */
    public function build(Site $site, Collection $locations): array
    {
        /** @var Collection<int, CoverageArea> $areas */
        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();

        $meta = [];
        foreach ($locations as $location) {
            $meta += CoverageBand::meta(CoverageBand::chain($location));
        }
        if ($meta === []) {
            $meta = CoverageBand::meta(self::TIERS);
        }
        $allBands = array_keys($meta);

        $totals = [
            'covered' => $areas->count(),
            'selected' => $areas->where('page_selected', true)->count(),
            'overlap' => $areas->filter(fn (CoverageArea $a) => is_array($a->source_location_ids) && count($a->source_location_ids) > 1)->count(),
            'tiers' => $this->tierCounts($areas, $allBands),
        ];

        $panels = [];
        foreach ($locations as $location) {
            $own = $areas->filter(
                fn (CoverageArea $a) => is_array($a->source_location_ids) && in_array($location->id, $a->source_location_ids, true)
            )->values();
            $chain = CoverageBand::chain($location);

            $panels[$location->id] = [
                'town_count' => $own->count(),
                'selected_count' => $own->where('page_selected', true)->count(),
                'chain' => $chain,
                'proximity' => $location->isProximity(),
                'tiers' => $this->tierCounts($own, $chain),
                'groups' => $this->groups($own, $chain),
                'tier_locks' => $this->tierLocks($site, (string) $location->id, $chain),
            ];
        }

        return ['totals' => $totals, 'band_meta' => $meta, 'panels' => $panels];
    }

    /**
     * The tiered-rollout lock state for each band of one location (its towns' market = itself).
     *
     * @param  list<string>  $chain
     * @return array<string, array{locked: bool, reason: string}>
     */
    private function tierLocks(Site $site, string $locationId, array $chain): array
    {
        $locks = [];
        foreach ($chain as $band) {
            $status = $this->gate->status($site, $locationId, $band);
            $locks[$band] = ['locked' => ! $status->buildable, 'reason' => $status->reason];
        }

        return $locks;
    }

    /**
     * @param  Collection<int, CoverageArea>  $areas
     * @param  list<string>  $bands
     * @return array<string, int>
     */
    private function tierCounts(Collection $areas, array $bands): array
    {
        $counts = array_fill_keys($bands, 0);
        foreach ($areas as $a) {
            $counts[$this->tierKey($a)] = ($counts[$this->tierKey($a)] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, CoverageArea>  $areas
     * @param  list<string>  $bands
     * @return array<string, list<array<string, mixed>>>
     */
    private function groups(Collection $areas, array $bands): array
    {
        $groups = array_fill_keys($bands, []);
        // Canonical town order — the SINGLE comparator used everywhere: population descending
        // (no-population sinks last), name as the tiebreak. Identical on initial render and after
        // any toggle, so a click never moves a town.
        $sorted = $areas->sort(function (CoverageArea $a, CoverageArea $b) {
            $popDelta = ($b->population ?? -1) <=> ($a->population ?? -1);

            return $popDelta !== 0 ? $popDelta : strcmp($a->name, $b->name);
        })->values();

        foreach ($sorted as $a) {
            $groups[$this->tierKey($a)][] = [
                'geo_id' => $a->geo_id,
                'name' => $a->name,
                'population' => $a->population,
                'distance_miles' => $a->distance_miles === null ? null : (float) $a->distance_miles,
                'page_selected' => (bool) $a->page_selected,
                'manual' => $a->source === 'manual',
                'tier' => $a->size_tier,
                'band' => $this->tierKey($a),
            ];
        }

        // A distance ring reads nearest-first — the order the shop's customers actually come from.
        foreach ($groups as $band => $towns) {
            if (CoverageBand::isRing((string) $band)) {
                usort($towns, fn (array $a, array $b): int => [$a['distance_miles'] ?? PHP_FLOAT_MAX, $a['name']] <=> [$b['distance_miles'] ?? PHP_FLOAT_MAX, $b['name']]);
                $groups[$band] = $towns;
            }
        }

        return $groups;
    }

    /** The row's band; a row with none is ungrouped. */
    private function tierKey(CoverageArea $a): string
    {
        return is_string($a->band) && $a->band !== '' ? $a->band : CoverageBand::UNGROUPED;
    }
}
