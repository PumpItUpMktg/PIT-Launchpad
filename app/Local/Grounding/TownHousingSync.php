<?php

namespace App\Local\Grounding;

use App\Integrations\Census\HousingStats;
use App\Models\CensusHousing;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fill {@see CensusHousing} for the towns a site covers.
 *
 * Requests are grouped by the geography the ACS actually serves, not by town: every county subdivision in
 * a county comes back in ONE call, every place in a state in one more. A 54-town territory inside two
 * counties is two requests, cached for a month.
 *
 * Rows already held are skipped unless `$force`, and a town whose GEOID the ACS does not return is left
 * without a row — no placeholder, so the grounding stays honest-by-omission rather than inventing a town
 * with unknown housing.
 */
final class TownHousingSync
{
    public function __construct(private readonly HousingStats $acs) {}

    /**
     * @return array{towns: int, fetched: int, written: int, missing: int, requests: int}
     */
    public function forSite(Site $site, bool $force = false): array
    {
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get(['id', 'geo_id', 'name', 'state']);

        $wanted = [];
        foreach ($towns as $town) {
            $geoId = trim((string) $town->geo_id);
            if ($geoId !== '') {
                $wanted[$geoId] = $town;
            }
        }
        if ($wanted === []) {
            return ['towns' => 0, 'fetched' => 0, 'written' => 0, 'missing' => 0, 'requests' => 0];
        }

        $held = $force ? [] : CensusHousing::query()->whereIn('geo_id', array_keys($wanted))->pluck('geo_id')->flip()->all();
        $todo = array_diff_key($wanted, $held);

        // Group the outstanding GEOIDs into the fewest ACS calls: subdivisions by county, places by state.
        $counties = [];
        $states = [];
        foreach (array_keys($todo) as $geoId) {
            if (strlen($geoId) === 10) {
                $counties[substr($geoId, 0, 5)] = true;
            } elseif (strlen($geoId) === 7) {
                $states[substr($geoId, 0, 2)] = true;
            }
        }

        $stats = [];
        $requests = 0;
        foreach (array_keys($counties) as $countyGeoid) {
            $stats += $this->acs->forCountySubdivisions(substr($countyGeoid, 0, 2), substr($countyGeoid, 2, 3));
            $requests++;
        }
        foreach (array_keys($states) as $stateFips) {
            $stats += $this->acs->forPlaces($stateFips);
            $requests++;
        }

        $written = 0;
        $missing = 0;
        foreach ($todo as $geoId => $town) {
            $row = $stats[$geoId] ?? null;
            if ($row === null) {
                $missing++;

                continue;
            }
            CensusHousing::query()->updateOrCreate(['geo_id' => (string) $geoId], [
                // The town's own name, not the ACS's "Warrington township, Bucks County, Pennsylvania".
                'name' => (string) $town->name,
                'state' => $town->state,
                'county_geoid' => strlen((string) $geoId) === 10 ? substr((string) $geoId, 0, 5) : null,
                'acs_year' => $this->acs->year(),
                'median_year_built' => $row['median_year_built'],
                'occupied_units' => $row['occupied_units'],
                'owner_occupied_units' => $row['owner_occupied_units'],
                'total_units' => $row['total_units'],
                'single_family_units' => $row['single_family_units'],
                'pre_1960_units' => $row['pre_1960_units'],
                'fetched_at' => Carbon::now(),
            ]);
            $written++;
        }

        Log::info('Town housing: ACS sync complete.', [
            'site_id' => (string) $site->id, 'towns' => count($wanted), 'requests' => $requests,
            'written' => $written, 'missing' => $missing,
        ]);

        return [
            'towns' => count($wanted),
            'fetched' => count($todo),
            'written' => $written,
            'missing' => $missing,
            'requests' => $requests,
        ];
    }
}
