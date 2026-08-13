<?php

namespace App\JobCapture\Review;

use App\Models\Job;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Collection;

/**
 * Resolves which of a site's storefront locations "owns" a job — the storefront whose territory (the
 * counties it serves, from the §7b Where-You-Work step) covers the job's county. Surfaced as a pill on the
 * job cards so an operator sees, at a glance, the physical location a job is attributed to. Batched: load a
 * site's storefronts once, then resolve many jobs against that set (no per-card query).
 */
final class JobStorefrontResolver
{
    /**
     * The site's storefront locations (is_storefront), scoped to the site regardless of the ambient tenant.
     *
     * @return Collection<int, Location>
     */
    public function storefronts(string $siteId): Collection
    {
        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('is_storefront', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * The name of the storefront serving this job's county, or null when the job has no county or no
     * storefront covers it. A storefront covers a county when the county's GEOID is in its served list
     * (`county_geoids`) or is the storefront's own home county (`home_county_geoid`).
     *
     * @param  Collection<int, Location>  $storefronts
     */
    public function resolve(Job $job, Collection $storefronts): ?string
    {
        if ($job->job_county_id === null) {
            return null;
        }

        $geoid = (string) $job->county->county_geoid;
        if ($geoid === '') {
            return null;
        }

        $match = $storefronts->first(function (Location $location) use ($geoid): bool {
            $served = is_array($location->county_geoids) ? $location->county_geoids : [];

            return in_array($geoid, $served, true) || (string) $location->home_county_geoid === $geoid;
        });

        return $match instanceof Location ? (string) $match->name : null;
    }
}
