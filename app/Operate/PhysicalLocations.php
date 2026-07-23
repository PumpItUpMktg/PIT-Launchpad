<?php

namespace App\Operate;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
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

        // The landing/hub page that IS each base location (page_type=Location, pinned location_id),
        // keyed by location — so each card can surface its page's build state and lifecycle actions.
        $landings = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('location_id')
            ->get()
            ->keyBy(fn (Content $c): string => (string) $c->location_id);

        $cards = $locations
            ->map(fn (Location $location) => $this->card($location, $areas, $names, $landings->get((string) $location->id)))
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
    private function card(Location $location, Collection $areas, Collection $names, ?Content $landing): array
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
            'page' => $this->pageState($landing),
        ];
    }

    /**
     * The landing/hub page's build state for the card's lifecycle controls — reusing Content's own
     * single-source-of-truth state machine so the badge + button gating match every other surface.
     *
     * @return array<string, mixed>
     */
    private function pageState(?Content $landing): array
    {
        if ($landing === null) {
            return [
                'content_id' => null,
                'label' => 'Not created yet',
                'state' => 'none',
                'drafted' => false,
                'published' => false,
                'can_generate' => true,   // "Generate" find-or-creates the landing page, then drafts it
                'can_review' => false,    // no page yet — nothing to open in the proof editor
                'can_publish' => false,
                'can_repush' => false,
                'can_takedown' => false,
            ];
        }

        $state = $landing->generationState();          // awaiting | generating | failed | drafted
        $published = $landing->status === ContentStatus::Published;
        $drafted = $landing->hasDraft();

        return [
            'content_id' => (string) $landing->id,
            'label' => $landing->buildStateLabel(),
            'state' => $state,
            'drafted' => $drafted,
            'published' => $published,
            'can_generate' => $state !== 'generating',
            // Review opens the proof editor for any drafted page (the same target as the core board).
            'can_review' => $drafted,
            'can_publish' => $drafted && ! $published && $state !== 'generating',
            'can_repush' => $published,
            // Take down only makes sense once the page is live on WordPress.
            'can_takedown' => $published,
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
