<?php

namespace App\ContentEngine\Drafting;

use App\Enums\MunicipalityType;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;

/**
 * Decides the ONE county-scoped local angle a draft may carry ({@see LocalAngle}). Injection is
 * reserved for the reactive lane flagged locally relevant — directed/evergreen content stays
 * town-agnostic. When allowed, the angle is a brick-and-mortar ANCHOR (a storefront location's town)
 * plus, only when a genuinely-near serving city shares that storefront's COUNTY, one optional STORY
 * town. The shared county is the anti-drift guardrail; the anchor rotates across a tenant's storefronts
 * so local relevance is spread over all of them, not piled on one.
 */
class LocalInjectionPolicy
{
    public function angleFor(DraftRequest $request): LocalAngle
    {
        if (! $request->allowsLocalInjection()) {
            return LocalAngle::none();
        }

        $storefronts = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $request->siteId)
            ->where('is_storefront', true)
            ->orderBy('id') // stable order for deterministic rotation
            ->get()
            ->values();
        if ($storefronts->isEmpty()) {
            return LocalAngle::none();
        }

        // Rotate the anchor across storefronts, deterministic per request so a given post is stable.
        $seed = (string) ($request->sourceUrl ?? $request->title ?? $request->siloId ?? '');
        $anchor = $storefronts[(int) (crc32($seed) % $storefronts->count())];

        $anchorTown = trim($anchor->cityState()['city']);
        if ($anchorTown === '') {
            return LocalAngle::none(); // no usable brick-and-mortar town
        }

        $county = trim((string) $anchor->home_county_geoid);
        $storyTown = $county !== '' ? $this->nearestServingCity($request->siteId, $anchor, $county, $anchorTown) : null;

        return new LocalAngle($anchorTown, $this->countyName($anchor), $storyTown);
    }

    /**
     * Back-compat: the flat town list the grounding/linking still consumes (story town, then anchor).
     *
     * @return list<string>
     */
    public function townsFor(DraftRequest $request): array
    {
        return $this->angleFor($request)->towns();
    }

    /** The nearest serving city IN THE ANCHOR'S COUNTY (excluding the anchor town itself), or null. */
    private function nearestServingCity(string $siteId, Location $anchor, string $county, string $anchorTown): ?string
    {
        $anchorLc = mb_strtolower($anchorTown);
        $best = null;
        $bestDistance = INF;

        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('type', MunicipalityType::CountySubdivision->value)
            ->get(['name', 'geo_id', 'lat', 'lng', 'distance_miles']);

        foreach ($areas as $area) {
            $geoId = (string) $area->geo_id;
            if (strlen($geoId) < 5 || substr($geoId, 0, 5) !== $county) {
                continue; // different county ⇒ would drift; never eligible
            }
            $name = trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim((string) $area->name)));
            if ($name === '' || mb_strtolower($name) === $anchorLc) {
                continue;
            }
            $distance = $this->distance($anchor, $area);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $name;
            }
        }

        return $best;
    }

    /** Squared planar distance from the anchor (fine for a nearest pick), falling back to miles-to-base. */
    private function distance(Location $anchor, CoverageArea $area): float
    {
        if ($anchor->lat !== null && $anchor->lng !== null && $area->lat !== null && $area->lng !== null) {
            return (((float) $area->lat - (float) $anchor->lat) ** 2) + (((float) $area->lng - (float) $anchor->lng) ** 2);
        }

        return $area->distance_miles !== null ? (float) $area->distance_miles : INF;
    }

    /** The anchor's county name from its geocoded address (administrative_area_level_2), or null. */
    private function countyName(Location $anchor): ?string
    {
        foreach ($anchor->address_components ?? [] as $component) {
            if (in_array('administrative_area_level_2', $component['types'] ?? [], true)) {
                $name = trim((string) ($component['long_name'] ?? ''));

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }
}
