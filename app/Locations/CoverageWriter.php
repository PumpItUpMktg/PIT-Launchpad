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
            // Dedup the incoming computed towns by GEOID (a town reached via multiple
            // counties/locations is ONE row) — guards the (site_id, geo_id) unique index
            // against in-batch dupes. Manual rows are owned by ManualCoverage, not rewritten.
            $rows = [];
            foreach ($result->union as $m) {
                if ($m->manual) {
                    continue;
                }
                $rows[$m->geoId] = $m;
            }

            // Replace the whole auto-derived set: drop EVERY non-manual row — stale radius-era
            // rows AND prior county rows. The unique index is (site_id, geo_id) regardless of
            // `source`, so leaving stale radius rows behind collides on insert (the 500). NEVER
            // delete manual rows — owner-added priority-page towns must survive a recompute.
            CoverageArea::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('source', '!=', 'manual')
                ->delete();

            foreach ($rows as $m) {
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
                    'population' => $m->population,
                    'source' => 'county',
                ]);
            }

            return count($rows);
        });
    }
}
