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
 * The anchorable entity (the town page) can be on EITHER side of the join, so there are two resolve modes:
 *  - {@see resolve()} — PAGE-DRIVEN: the page carries the geo_id and the coverage set is the index.
 *  - {@see resolveByCoverage()} — COVERAGE-DRIVEN: the coverage carries the geo_id and the anchorable page
 *    is the counterpart being looked up. `geo_miss` keeps ONE meaning across both — "the anchored side has
 *    a key the other doesn't recognise" — which is why coverage-driven needs a THIRD count to stay honest.
 *
 * Three fallback reasons, counted apart because they mean different things:
 *  - `geo_null`  — page-driven only: the page has no geo_id yet (pre-anchor). Expected; shrinks as the anchor runs.
 *  - `counterpart_unanchored` — coverage-driven only: a page for this name exists but isn't anchored yet
 *                  (pre-anchor). The honest transitional state; shrinks to zero as the anchor runs.
 *  - `geo_miss`  — either direction: the ANCHORED side has a geo_id the other side doesn't recognise
 *                  (page-driven: the page's geo_id is in no coverage entry; coverage-driven: a same-named
 *                  page is anchored to a DIFFERENT geo than the driver). A real disagreement, not a
 *                  transitional state — logged as its own warning, never buried in the aggregate.
 */
final class TownGeoFallback
{
    private int $total = 0;

    private int $byGeo = 0;

    private int $geoNull = 0;

    private int $counterpartUnanchored = 0;

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
     * COVERAGE-DRIVEN resolve: the driver (a coverage area) carries the geo_id; the anchorable COUNTERPART
     * (the town page) is what's indexed. Prefer the counterpart found by the driver's geo_id, else the name
     * key. The fallback reason is coded from the counterpart's OWN anchor state so `geo_miss` keeps its one
     * meaning:
     *  - name-matched counterpart that is NOT anchored → `counterpart_unanchored` (expected, shrinks as the
     *    anchor runs);
     *  - name-matched counterpart that IS anchored (necessarily to a different geo, since the geo lookup
     *    missed) → `geo_miss` (a real disagreement: same name, different geo);
     *  - no counterpart by either key → null, UNCOUNTED (a driver with no page is a legitimate absence, not
     *    a fallback — e.g. a served town that simply has no page yet).
     *
     * @template TValue
     *
     * @param  array<string, TValue>  $byGeo  counterpart indexed by its geo_id (anchored counterparts only)
     * @param  array<string, array{value: TValue, anchored: bool}>  $byName  counterpart by name key, carrying whether it is anchored
     * @return TValue|null
     */
    public function resolveByCoverage(?string $driverGeoId, string $nameKey, array $byGeo, array $byName): mixed
    {
        if ($driverGeoId !== null && $driverGeoId !== '' && array_key_exists($driverGeoId, $byGeo)) {
            $this->total++;
            $this->byGeo++;

            return $byGeo[$driverGeoId];
        }

        $named = $byName[$nameKey] ?? null;
        if ($named === null) {
            return null; // no counterpart at all — a legitimate absence, not a fallback (uncounted)
        }

        $this->total++;
        if ($named['anchored']) {
            $this->geoMiss++; // a same-named page is anchored to a different geo — a real disagreement
        } else {
            $this->counterpartUnanchored++;
        }

        return $named['value'];
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

        $byName = $this->geoNull + $this->counterpartUnanchored + $this->geoMiss;

        Log::info('towngeo.fallback', [
            'consumer' => $this->consumer,
            'site_id' => $this->siteId,
            'total' => $this->total,
            'by_geo' => $this->byGeo,
            'by_name' => $byName,
            'geo_null' => $this->geoNull,
            'counterpart_unanchored' => $this->counterpartUnanchored,
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
     * @return array{total: int, by_geo: int, by_name: int, geo_null: int, counterpart_unanchored: int, geo_miss: int}
     */
    public function counts(): array
    {
        return [
            'total' => $this->total,
            'by_geo' => $this->byGeo,
            'by_name' => $this->geoNull + $this->counterpartUnanchored + $this->geoMiss,
            'geo_null' => $this->geoNull,
            'counterpart_unanchored' => $this->counterpartUnanchored,
            'geo_miss' => $this->geoMiss,
        ];
    }
}
