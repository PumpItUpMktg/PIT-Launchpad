<?php

namespace App\Locations;

/**
 * The municipalities a single base location reaches within its radius (before the
 * cross-base union) — the per-base breakdown the CLI prints.
 */
final class BaseCoverage
{
    /**
     * @param  list<CoverageMunicipality>  $municipalities
     */
    public function __construct(
        public readonly string $locationId,
        public readonly string $locationName,
        public readonly float $radiusMiles,
        public readonly array $municipalities,
    ) {}
}
