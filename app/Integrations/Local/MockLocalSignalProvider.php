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

    /** A stable 0–1 value seeded per site + town + trade + dimension. */
    private function unit(string $siteId, string $geoId, string $trade, string $dimension): float
    {
        $hash = crc32($siteId.'|'.$geoId.'|'.$trade.'|'.$dimension);

        return ($hash % 1000) / 1000.0;
    }
}
