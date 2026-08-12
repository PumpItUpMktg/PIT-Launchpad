<?php

namespace App\JobCapture\Geography;

use App\Enums\MunicipalityType;
use App\Enums\SizeTier;
use App\Integrations\Census\CensusPopulation;
use App\Integrations\Census\County;
use App\Integrations\Census\Municipality;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\JobCounty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Resolves a captured job's TRUE coordinates to canonical geography (§4). Given the internal-only
 * lat/lng, it asks the Census gazetteer for the containing county and place/MCD, upserts the GLOBAL
 * FIPS-keyed registry ({@see JobCounty}/{@see JobCity} — one row per FIPS, shared across tenants),
 * enriches the city with ACS population + size tier, links the job to both, and stamps the stored
 * privacy {@see Jitter}.
 *
 * Idempotent and safe to re-run (the capture flow and the Joby ingest both drive it): registry rows are
 * matched on their FIPS identity so attributes refresh without duplicating, and the jitter is computed
 * ONLY ONCE — a job that already carries jittered coordinates is never re-jittered (the privacy point
 * must be stable across every render). A job with no true point is a no-op.
 */
final class GeographyResolver
{
    public function __construct(
        private readonly MunicipalityGazetteer $gazetteer,
        private readonly CensusPopulation $population,
        private readonly Jitter $jitter,
    ) {}

    public function resolve(Job $job): void
    {
        if ($job->lat_true === null || $job->lng_true === null) {
            return;
        }

        $lat = (float) $job->lat_true;
        $lng = (float) $job->lng_true;

        // Resolve the place first so the county can borrow its state abbreviation for a clean slug.
        $place = $this->gazetteer->placeAt($lat, $lng);
        $county = $this->gazetteer->countyAt($lat, $lng);

        $jobCounty = $county !== null ? $this->upsertCounty($county, $place?->state) : null;
        $jobCity = $place !== null ? $this->upsertCity($place, $jobCounty) : null;

        $job->job_county_id = $jobCounty?->id;
        $job->job_city_id = $jobCity?->id;

        // Jitter is computed once and stored — never recalculated (privacy contract §4).
        if ($job->lat_jittered === null || $job->lng_jittered === null) {
            $jittered = $this->jitter->apply($lat, $lng);
            $job->lat_jittered = $jittered['lat'];
            $job->lng_jittered = $jittered['lng'];
        }

        $job->save();
    }

    private function upsertCounty(County $county, ?string $stateAbbr): JobCounty
    {
        $row = JobCounty::firstOrNew(['county_geoid' => $county->geoId]);
        $row->state_fips = $county->stateFips;
        $row->name = $county->name;
        $row->state = $stateAbbr;
        if (blank($row->slug)) {
            // The Census county NAME already carries its legal suffix ("Somerset County", "Acadia Parish",
            // "Aleutians East Borough"), so the slug is just the slugified name + state — no "-county".
            $row->slug = $this->uniqueSlug(JobCounty::class, Str::slug($county->name), $stateAbbr, 'county_geoid', $county->geoId);
        }
        $row->save();

        return $row;
    }

    private function upsertCity(Municipality $place, ?JobCounty $county): JobCity
    {
        $row = JobCity::firstOrNew(['place_geoid' => $place->geoId]);
        $row->job_county_id = $county?->id;
        $row->name = $place->name;
        $row->state = $place->state;
        $row->type = $place->type;
        $row->lat = $place->lat;
        $row->lng = $place->lng;

        $population = $this->populationFor($place);
        if ($population !== null) {
            $row->population = $population;
            $row->size_tier = SizeTier::forPopulation($population);
        }

        if (blank($row->slug)) {
            $row->slug = $this->uniqueSlug(JobCity::class, Str::slug($place->name), $place->state, 'place_geoid', $place->geoId);
        }
        $row->save();

        return $row;
    }

    /**
     * ACS5 total population for a county-subdivision place, keyed by its 10-digit GEOID (the granularity
     * {@see CensusPopulation} exposes). Incorporated Places (7-digit) aren't covered by the subdivision
     * join, so they resolve to null population (no tier) rather than a wrong number.
     */
    private function populationFor(Municipality $place): ?int
    {
        if ($place->type !== MunicipalityType::CountySubdivision || strlen($place->geoId) < 5) {
            return null;
        }

        $byGeoid = $this->population->forCounty(substr($place->geoId, 0, 2), substr($place->geoId, 2, 3));

        return $byGeoid[$place->geoId] ?? null;
    }

    /**
     * A registry-unique slug: `base` (+ `-state`), then `-2`, `-3`… only if a DIFFERENT row already holds
     * it (two same-named municipalities in one state — e.g. NJ's many Washington Townships). Matching a
     * row that shares this FIPS identity keeps a re-run stable.
     *
     * @param  class-string<Model>  $model
     */
    private function uniqueSlug(string $model, string $base, ?string $stateAbbr, string $identityColumn, string $identity): string
    {
        $slug = $base.(filled($stateAbbr) ? '-'.strtolower((string) $stateAbbr) : '');

        $candidate = $slug;
        $n = 1;
        while ($model::query()->where('slug', $candidate)->where($identityColumn, '!=', $identity)->exists()) {
            $candidate = $slug.'-'.++$n;
        }

        return $candidate;
    }
}
