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
 * site totals + one panel per location, with towns grouped by size tier. The page is thin over
 * this; selection toggles write straight to coverage_areas and this re-reads.
 */
final class CoveragePanels
{
    /** Tier display order; 'ungrouped' (size_tier null) always last. */
    public const TIERS = ['major', 'large', 'medium', 'small', 'ungrouped'];

    /**
     * @param  Collection<int, Location>  $locations
     * @return array{
     *     totals: array{covered: int, selected: int, overlap: int, tiers: array<string, int>},
     *     panels: array<string, array{town_count: int, selected_count: int, tiers: array<string, int>, groups: array<string, list<array<string, mixed>>>}>
     * }
     */
    public function build(Site $site, Collection $locations): array
    {
        /** @var Collection<int, CoverageArea> $areas */
        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();

        $totals = [
            'covered' => $areas->count(),
            'selected' => $areas->where('page_selected', true)->count(),
            'overlap' => $areas->filter(fn (CoverageArea $a) => is_array($a->source_location_ids) && count($a->source_location_ids) > 1)->count(),
            'tiers' => $this->tierCounts($areas),
        ];

        $panels = [];
        foreach ($locations as $location) {
            $own = $areas->filter(
                fn (CoverageArea $a) => is_array($a->source_location_ids) && in_array($location->id, $a->source_location_ids, true)
            )->values();

            $panels[$location->id] = [
                'town_count' => $own->count(),
                'selected_count' => $own->where('page_selected', true)->count(),
                'tiers' => $this->tierCounts($own),
                'groups' => $this->groups($own),
            ];
        }

        return ['totals' => $totals, 'panels' => $panels];
    }

    /**
     * @param  Collection<int, CoverageArea>  $areas
     * @return array<string, int>
     */
    private function tierCounts(Collection $areas): array
    {
        $counts = array_fill_keys(self::TIERS, 0);
        foreach ($areas as $a) {
            $counts[$this->tierKey($a)]++;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, CoverageArea>  $areas
     * @return array<string, list<array<string, mixed>>>
     */
    private function groups(Collection $areas): array
    {
        $groups = array_fill_keys(self::TIERS, []);
        $sorted = $areas->sortBy([
            fn (CoverageArea $a) => -1 * (int) $a->population, // population desc (nulls → 0, sink within their tier)
            fn (CoverageArea $a) => $a->name,
        ]);

        foreach ($sorted as $a) {
            $groups[$this->tierKey($a)][] = [
                'geo_id' => $a->geo_id,
                'name' => $a->name,
                'population' => $a->population,
                'page_selected' => (bool) $a->page_selected,
                'manual' => $a->source === 'manual',
                'tier' => $a->size_tier,
            ];
        }

        return $groups;
    }

    private function tierKey(CoverageArea $a): string
    {
        return $a->size_tier ?? 'ungrouped';
    }
}
