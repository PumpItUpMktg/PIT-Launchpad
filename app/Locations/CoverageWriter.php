<?php

namespace App\Locations;

use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Persists a coverage union as the site's CoverageArea set (the authoritative service
 * areas). Re-running replaces the prior set in one transaction. Returns the row count.
 */
final class CoverageWriter
{
    public function write(Site $site, CoverageResult $result): int
    {
        return DB::transaction(function () use ($site, $result): int {
            CoverageArea::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->delete();

            foreach ($result->union as $m) {
                CoverageArea::create([
                    'site_id' => $site->id, // explicit: no current-site scope in console/job context
                    'geo_id' => $m->geoId,
                    'name' => $m->name,
                    'type' => $m->type,
                    'state' => $m->state,
                    'lat' => $m->lat,
                    'lng' => $m->lng,
                    'distance_miles' => $m->distanceMiles,
                    'source_location_ids' => $m->sourceLocationIds,
                ]);
            }

            return $result->unionCount();
        });
    }
}
