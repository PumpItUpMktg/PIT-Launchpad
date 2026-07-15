<?php

namespace App\Operate;

use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * The Operate physical-locations directory read model: one card per base Location with the
 * territory it serves, built ENTIRELY from persisted rows (no gazetteer/network on render).
 *
 * Two guarantees the surface makes visible:
 *  - OVERLAP: a town reached by two locations (source_location_ids > 1) is flagged on every
 *    card involved, naming the other location(s) — the goal state is zero overlap.
 *  - THE HOME-COUNTY SOFT RULE: a location should serve the county it sits in and that county's
 *    towns. Advisory only — a good starting point, never a wall. Checkable offline because a
 *    county-subdivision GEOID's first five digits ARE its county GEOID.
 */
class PhysicalLocations
{
    /**
     * @return array{summary: array<string, int>, cards: list<array<string, mixed>>}
     */
    public function build(Site $site): array
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('name')
            ->get();

        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();

        $names = $locations->pluck('name', 'id');

        $cards = $locations
            ->map(fn (Location $location) => $this->card($location, $areas, $names))
            ->values()
            ->all();

        return [
            'summary' => [
                'locations' => $locations->count(),
                'towns_covered' => $areas->count(),
                'towns_selected' => $areas->where('page_selected', true)->count(),
                'overlaps' => $areas->filter(fn (CoverageArea $a) => count($this->sources($a)) > 1)->count(),
            ],
            'cards' => $cards,
        ];
    }

    /**
     * @param  Collection<int, CoverageArea>  $areas
     * @param  Collection<int|string, string>  $names
     * @return array<string, mixed>
     */
    private function card(Location $location, Collection $areas, Collection $names): array
    {
        $own = $areas->filter(fn (CoverageArea $a) => in_array($location->id, $this->sources($a), true))->values();

        // Overlap: every town this location shares with another, naming the other location(s).
        $overlaps = $own
            ->filter(fn (CoverageArea $a) => count($this->sources($a)) > 1)
            ->map(fn (CoverageArea $a) => [
                'town' => trim((string) $a->name).($a->state ? ", {$a->state}" : ''),
                'with' => collect($this->sources($a))
                    ->reject(fn ($id) => (string) $id === (string) $location->id)
                    ->map(fn ($id) => (string) ($names[$id] ?? 'unknown location'))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        $located = $location->lat !== null && $location->lng !== null;
        $home = trim((string) $location->home_county_geoid);
        $countiesServed = collect(is_array($location->county_geoids) ? $location->county_geoids : [])
            ->map(fn ($g) => trim((string) $g))->filter()->values();

        // The soft rule, from persisted data only. County-subdivision GEOIDs prefix with their
        // county GEOID; 'place' GEOIDs don't carry a county, so the town check is best-effort.
        $servesHomeCounty = $home !== '' && $countiesServed->contains($home);
        $homeCountyTowns = $home === '' ? null : $own
            ->filter(fn (CoverageArea $a) => str_starts_with((string) $a->geo_id, $home))
            ->count();

        $advisories = [];
        if (! $located) {
            $advisories[] = 'Not located yet — locate it in Setup → Locations (or Settings → Service area) so its home county can resolve.';
        } elseif ($home === '') {
            $advisories[] = 'Home county not resolved yet — recompute the service area.';
        } elseif (! $servesHomeCounty) {
            $advisories[] = 'Doesn\'t serve the county it sits in — a good starting point is to add its home county (soft rule, your call).';
        } elseif ($homeCountyTowns === 0) {
            $advisories[] = 'Home county is ticked but no towns are computed yet — run "Update service area".';
        }

        return [
            'id' => (string) $location->id,
            'name' => trim((string) $location->name),
            'address' => $location->address,
            'phone' => $location->phone,
            'storefront' => (bool) $location->is_storefront,
            'located' => $located,
            'gbp_url' => $location->gbp_url,
            'gbp_linked' => trim((string) $location->place_id) !== '' || trim((string) $location->gbp_url) !== '',
            'serves_home_county' => $servesHomeCounty,
            'home_resolved' => $home !== '',
            'counties_served' => $countiesServed->count(),
            'home_county_towns' => $homeCountyTowns,
            'towns_covered' => $own->count(),
            'towns_selected' => $own->where('page_selected', true)->count(),
            'town_sample' => $own->sortByDesc('population')
                ->take(12)
                ->map(fn (CoverageArea $a) => trim((string) $a->name))
                ->values()
                ->all(),
            'overlaps' => $overlaps,
            'advisories' => $advisories,
        ];
    }

    /**
     * @return list<string>
     */
    private function sources(CoverageArea $area): array
    {
        return is_array($area->source_location_ids)
            ? array_map('strval', $area->source_location_ids)
            : [];
    }
}
