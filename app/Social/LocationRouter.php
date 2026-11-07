<?php

namespace App\Social;

use App\Models\CoverageArea;
use App\Models\Job;
use App\Models\Location;
use App\Models\Scopes\SiteScope;

/**
 * Which physical location a job belongs to, for its GBP share: the location whose service area covers
 * the job's town (coverage_areas.geo_id = the town's GEOID → source_location_ids), else the nearest
 * location to the job's true coordinates, else the site's only location. Null when the site has none.
 */
final class LocationRouter
{
    public function forJob(Job $job): ?Location
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $job->site_id)
            ->whereNull('merged_into_id')
            ->get();
        if ($locations->isEmpty()) {
            return null;
        }
        if ($locations->count() === 1) {
            return $locations->first();
        }

        $city = $job->job_city_id !== null ? $job->city : null;
        $geoid = $city?->place_geoid;
        if (is_string($geoid) && $geoid !== '') {
            $area = CoverageArea::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $job->site_id)
                ->where('geo_id', $geoid)
                ->first();
            foreach ((array) ($area->source_location_ids ?? []) as $id) {
                $hit = $locations->firstWhere('id', $id);
                if ($hit !== null) {
                    return $hit;
                }
            }
            $town = strtolower(trim((string) $city->name));
            foreach ($locations as $location) {
                foreach ((array) ($location->served_towns ?? []) as $served) {
                    if (strtolower(trim((string) ($served['name'] ?? ''))) === $town && $town !== '') {
                        return $location;
                    }
                }
            }
        }

        $lat = $job->lat_true ?? $job->lat_jittered;
        $lng = $job->lng_true ?? $job->lng_jittered;
        if ($lat === null || $lng === null) {
            return $locations->first();
        }
        $best = null;
        $bestMiles = INF;
        foreach ($locations as $location) {
            if ($location->lat === null || $location->lng === null) {
                continue;
            }
            $miles = $this->haversineMiles((float) $lat, (float) $lng, (float) $location->lat, (float) $location->lng);
            if ($miles < $bestMiles) {
                $bestMiles = $miles;
                $best = $location;
            }
        }

        return $best ?? $locations->first();
    }

    private function haversineMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 3958.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
