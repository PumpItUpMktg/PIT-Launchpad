<?php

namespace App\Integrations\Local;

/**
 * Default {@see LocalSignalProvider} — deterministic synthetic signals until real adapters bind.
 * Crucially the values are seeded off the **site id** (plus geo + trade), so the same town scores
 * differently for two different businesses: no two sites get identical local data, which is the
 * whole point of the per-business layer. Deterministic so tests and the drip are stable, and so a
 * site's ordering doesn't churn between runs.
 *
 * Overrides can be injected for tests via {@see set()}.
 */
final class MockLocalSignalProvider implements LocalSignalProvider
{
    /** @var array<string, LocalSignals> keyed by "siteId:geoId" */
    private array $overrides = [];

    public function set(string $siteId, string $geoId, LocalSignals $signals): static
    {
        $this->overrides[$siteId.':'.$geoId] = $signals;

        return $this;
    }

    public function forTown(string $siteId, string $geoId, string $trade, ?int $population): LocalSignals
    {
        if (isset($this->overrides[$siteId.':'.$geoId])) {
            return $this->overrides[$siteId.':'.$geoId];
        }

        return new LocalSignals(
            geoId: $geoId,
            population: $population,
            competitorDensity: $this->unit($siteId, $geoId, $trade, 'competitor'),
            marketReviewIndex: $this->unit($siteId, $geoId, $trade, 'review'),
            demandIndex: $this->unit($siteId, $geoId, $trade, 'demand'),
        );
    }

    /**
     * A stable 0–1 value seeded per site + town + trade + dimension. Uses the FULL 32-bit hash space
     * (~4.3B buckets) so two different sites practically never land on the same value — the old `% 1000`
     * left only 1,000 buckets, which collided ~0.1% of the time and made the per-business distinctness
     * flaky. crc32 can be negative on 32-bit PHP, so mask to unsigned before scaling.
     */
    private function unit(string $siteId, string $geoId, string $trade, string $dimension): float
    {
        $hash = crc32($siteId.'|'.$geoId.'|'.$trade.'|'.$dimension) & 0xFFFFFFFF;

        return $hash / 0x100000000;
    }
}
