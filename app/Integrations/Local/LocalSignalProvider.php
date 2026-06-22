<?php

namespace App\Integrations\Local;

/**
 * Capability role: resolve per-business local signals for one covered town. This is the seam the
 * "numerous Google APIs" bind behind — Places (competitor density), Air Quality / Pollen (trade
 * relevant), GBP reviews, etc. — each adapter filling the {@see LocalSignals} fields it can. The
 * default binding is {@see MockLocalSignalProvider} (deterministic, but seeded per site so no two
 * businesses get the same numbers); real adapters add as keys come online, with no change to the
 * relevance scoring that consumes this.
 */
interface LocalSignalProvider
{
    /**
     * Resolve the normalized local signals for a single town within one site's context.
     *
     * @param  string  $siteId  the tenant — signals are per-business, never shared across sites
     * @param  string  $geoId  the town's Census GEOID
     * @param  string  $trade  the site's trade (some signals are trade-specific)
     * @param  int|null  $population  the Census population anchor, carried through
     */
    public function forTown(string $siteId, string $geoId, string $trade, ?int $population): LocalSignals;
}
