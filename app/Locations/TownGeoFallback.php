<?php

namespace App\Locations;

use Illuminate\Support\Facades\Log;

/**
 * The ONE transitional dual-path resolver for consumers migrating off the town-page NAME match onto the
 * GEOID join (`contents.geo_id`). Prefer the GEOID when the page carries one; fall back to the old name
 * key when it doesn't — so a consumer keeps working on un-anchored pages the moment it ships, instead of
 * breaking until `launchpad:anchor-town-pages --execute` has run on prod.
 *
 * It is deliberately a TEMPORARY seam built to be deleted: it counts, per run, how often each path was
 * taken and logs one aggregate line per (consumer, site) — `by_name=42 of 285`. As the anchor is run the
 * name path trends toward its floor; when it reaches zero across runs the dual path (and this class) can
 * be removed in one cleanup, not hunted across five call sites.
 *
 * Two fallback reasons are counted apart because they mean different things:
 *  - `geo_null`  — the page has no geo_id yet (pre-anchor). Expected; shrinks as the anchor runs.
 *  - `geo_miss`  — the page HAS a geo_id but it matches no entry in the geo index. That means the anchor
 *                  produced a key the consumer's coverage set doesn't contain — anchor and consumer
 *                  DISAGREE about the key — which is a real defect, not a transitional state, so it is
 *                  logged as a warning of its own rather than buried in the aggregate.
 */
final class TownGeoFallback
{
    private int $total = 0;

    private int $byGeo = 0;

    private int $geoNull = 0;

    private int $geoMiss = 0;

    public function __construct(
        private readonly string $consumer,
        private readonly string $siteId,
    ) {}

    /**
     * Resolve a value for a town page: the GEOID entry when the page is anchored and present in $byGeo,
     * else the name-key entry ($byName). Returns null when neither resolves (the caller's existing
     * "unmatched" path). Counts the path taken for {@see report()}.
     *
     * @template TValue
     *
     * @param  array<string, TValue>  $byGeo  target indexed by census geo_id (e.g. coverage_areas.geo_id)
     * @param  array<string, TValue>  $byName  target indexed by the legacy town-name key (TownName::key)
     * @return TValue|null
     */
    public function resolve(?string $geoId, string $nameKey, array $byGeo, array $byName): mixed
    {
        $this->total++;

        if ($geoId !== null && $geoId !== '') {
            if (array_key_exists($geoId, $byGeo)) {
                $this->byGeo++;

                return $byGeo[$geoId];
            }
            // Anchored, yet the key is in no coverage entry — anchor/consumer disagreement, not transitional.
            $this->geoMiss++;

            return $byName[$nameKey] ?? null;
        }

        // Not anchored yet — the expected transitional path.
        $this->geoNull++;

        return $byName[$nameKey] ?? null;
    }

    /**
     * Log the per-run tripwire: ONE aggregate line per (consumer, site), with the total so the fallback
     * count is read in proportion. Call once at the end of a run. A non-zero `geo_miss` also raises its own
     * warning — that path should never fire once the anchor and the consumer agree on the key.
     */
    public function report(): void
    {
        if ($this->total === 0) {
            return;
        }

        $byName = $this->geoNull + $this->geoMiss;

        Log::info('towngeo.fallback', [
            'consumer' => $this->consumer,
            'site_id' => $this->siteId,
            'total' => $this->total,
            'by_geo' => $this->byGeo,
            'by_name' => $byName,
            'geo_null' => $this->geoNull,
            'geo_miss' => $this->geoMiss,
        ]);

        if ($this->geoMiss > 0) {
            Log::warning('towngeo.geo_miss — anchored pages whose geo_id matched no coverage entry (anchor/consumer disagree)', [
                'consumer' => $this->consumer,
                'site_id' => $this->siteId,
                'geo_miss' => $this->geoMiss,
            ]);
        }
    }

    /**
     * The per-run counts (for tests and callers that want the numbers without the log).
     *
     * @return array{total: int, by_geo: int, by_name: int, geo_null: int, geo_miss: int}
     */
    public function counts(): array
    {
        return [
            'total' => $this->total,
            'by_geo' => $this->byGeo,
            'by_name' => $this->geoNull + $this->geoMiss,
            'geo_null' => $this->geoNull,
            'geo_miss' => $this->geoMiss,
        ];
    }
}
