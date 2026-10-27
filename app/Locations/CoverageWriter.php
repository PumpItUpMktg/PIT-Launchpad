<?php

namespace App\Locations;

use App\Enums\SizeTier;
use App\Locations\Sources\TownSourceResolver;
use App\Models\CoverageArea;
use App\Models\Location;
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
 *  - **Resolved local sources survive recompute** the same way: a town's official sources
 *    (municipality, county, flood map — {@see TownSourceResolver}) are
 *    keyed by GEOID across the rebuild, so a recompute never costs the searches again or blanks
 *    the citations on the town and job pages.
 *  - **size_tier is (re)derived** from population + the tenant's current thresholds on every
 *    write — for the freshly written county rows AND, in a cheap pass, the manual rows.
 *  - **band is (re)derived** the same way ({@see CoverageBand}): the size tier for a town whose
 *    market is a county-mode location, its distance ring for a proximity-mode one.
 */
final class CoverageWriter
{
    public function write(Site $site, CoverageResult $result): int
    {
        return DB::transaction(function () use ($site, $result): int {
            $thresholds = $site->coverageThresholds();
            $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('id');

            // Snapshot the drip-pool selection AND the resolved local sources by GEOID so they survive the rebuild.
            /** @var array<string, bool> $selectedByGeoId */
            $selectedByGeoId = [];
            /** @var array<string, array{sources: mixed, at: mixed}> $sourcesByGeoId */
            $sourcesByGeoId = [];
            foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get(['geo_id', 'page_selected', 'local_sources', 'local_sources_resolved_at']) as $prior) {
                $selectedByGeoId[(string) $prior->geo_id] = (bool) $prior->page_selected;
                if ($prior->local_sources_resolved_at !== null) {
                    $sourcesByGeoId[(string) $prior->geo_id] = ['sources' => $prior->local_sources, 'at' => $prior->local_sources_resolved_at];
                }
            }

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
                $tier = SizeTier::forPopulation($m->population, $thresholds)?->value;
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
                    'size_tier' => $tier,
                    'band' => CoverageBand::forArea($locations[$m->sourceLocationIds[0] ?? ''] ?? null, $m->distanceMiles, $tier),
                    'page_selected' => $selectedByGeoId[$m->geoId] ?? false,
                    'local_sources' => $sourcesByGeoId[$m->geoId]['sources'] ?? null,
                    'local_sources_resolved_at' => $sourcesByGeoId[$m->geoId]['at'] ?? null,
                    'source' => 'county',
                ]);
            }

            // Cheap re-tier of the manual rows (their selection is untouched).
            foreach (CoverageArea::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('source', 'manual')
                ->get() as $manual) {
                $tier = SizeTier::forPopulation($manual->population, $thresholds)?->value;
                $ids = is_array($manual->source_location_ids) ? $manual->source_location_ids : [];
                $band = CoverageBand::forArea($locations[$ids[0] ?? ''] ?? null, (float) ($manual->distance_miles ?? 0), $tier);
                if ($manual->size_tier !== $tier || $manual->band !== $band) {
                    $manual->forceFill(['size_tier' => $tier, 'band' => $band])->save();
                }
            }

            return count($rows);
        });
    }
}
