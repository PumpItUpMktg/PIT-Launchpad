<?php

namespace App\Locations;

use App\Build\BuildManifestAssembler;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The set of cities a site's physical Locations OWN — each Location's GBP locality (its
 * {@see Location::cityState()} city, or the location name as a fallback), keyed lowercased → the
 * states that city appears in ('' when unknown).
 *
 * A brick-and-mortar location already gets ONE landing page titled for its own city
 * (LocationLandingFactory / LocationLandingSync). So a covered town that IS a location's own city must
 * never also be selected or planned as a separate TOWN page — that produces two pages competing for the
 * same term (the "/hoboken-nj/" landing vs a "/hoboken-nj/hoboken-nj/" town). This is the single source
 * both the selection guard ({@see LocalRelevance}) and the manifest guard
 * ({@see BuildManifestAssembler}) consult, so the two can't drift.
 */
final class PhysicalLocationCities
{
    /** @return array<string, list<string>> lowercased city => list of states ('' when unknown) */
    public function forSite(Site $site): array
    {
        $keys = [];
        foreach (Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get() as $location) {
            ['city' => $city, 'state' => $state] = $location->cityState();
            $city = trim($city) !== '' ? trim($city) : trim((string) $location->name);
            if ($city === '') {
                continue;
            }
            $keys[mb_strtolower($city)][] = strtoupper(trim($state));
        }

        return $keys;
    }

    /**
     * Is this coverage town a physical location's own city? Match on the normalized name; when both
     * sides carry a state they must agree (so a same-named town in another state still gets a page), but
     * an unknown state on either side is treated as a match (the common single-footprint case).
     *
     * @param  array<string, list<string>>  $set  the map from {@see forSite()}
     */
    public function matches(string $name, ?string $state, array $set): bool
    {
        $key = mb_strtolower(trim($name));
        if (! isset($set[$key])) {
            return false;
        }

        $townState = strtoupper(trim((string) $state));
        foreach ($set[$key] as $locationState) {
            if ($locationState === '' || $townState === '' || $locationState === $townState) {
                return true;
            }
        }

        return false;
    }

    /** Convenience: does this site have a physical location whose own city is this town? */
    public function isLocationCity(Site $site, string $name, ?string $state): bool
    {
        return $this->matches($name, $state, $this->forSite($site));
    }
}
