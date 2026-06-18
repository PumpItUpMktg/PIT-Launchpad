<?php

namespace App\Locations;

use App\Enums\SizeTier;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Persists a coverage union as the site's CoverageArea set (the authoritative service
 * areas). Re-running replaces the auto-derived set in one transaction. Returns the row
 * count.
 *
 * Two invariants beyond the replace:
 *  - **Selection survives recompute.** The owner's page_selected drip-pool flags are
 *    snapshotted by GEOID before the delete and re-applied to the surviving towns. New
 *    towns default unselected; a town whose county was removed drops out (its selection
 *    goes with it — correct).
 *  - **size_tier is (re)derived** from population + the tenant's current thresholds on every
 *    write — for the freshly written county rows AND, in a cheap pass, the manual rows.
 */
final class CoverageWriter
{
    public function write(Site $site, CoverageResult $result): int
    {
        return DB::transaction(function () use ($site, $result): int {
            $thresholds = $site->coverageThresholds();

            // Snapshot the drip-pool selection by GEOID so it survives the rebuild.
            /** @var array<string, bool> $selectedByGeoId */
            $selectedByGeoId = CoverageArea::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->pluck('page_selected', 'geo_id')
                ->map(fn ($v) => (bool) $v)
                ->all();

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
            // `source`, so leaving stale rows behind collides on insert. NEVER delete manual
            // rows — owner-added priority-page towns must survive a recompute.
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
                    'size_tier' => SizeTier::forPopulation($m->population, $thresholds)?->value,
                    'page_selected' => $selectedByGeoId[$m->geoId] ?? false,
                    'source' => 'county',
                ]);
            }

            // Cheap re-tier of the manual rows (their selection is untouched).
            foreach (CoverageArea::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('source', 'manual')
                ->get() as $manual) {
                $tier = SizeTier::forPopulation($manual->population, $thresholds)?->value;
                if ($manual->size_tier !== $tier) {
                    $manual->forceFill(['size_tier' => $tier])->save();
                }
            }

            return count($rows);
        });
    }
}
