<?php

namespace App\Locations;

use App\Enums\MunicipalityType;

/**
 * The coverage engine's output: the per-base breakdown plus the deduplicated union (the
 * authoritative coverage set). Reports the MCD/place split so the NJ/PA correctness
 * check is one glance.
 */
final class CoverageResult
{
    /**
     * @param  list<BaseCoverage>  $perBase
     * @param  list<CoverageMunicipality>  $union
     */
    public function __construct(
        public readonly array $perBase,
        public readonly array $union,
    ) {}

    public function unionCount(): int
    {
        return count($this->union);
    }

    public function mcdCount(): int
    {
        return count(array_filter($this->union, fn (CoverageMunicipality $m) => $m->type === MunicipalityType::CountySubdivision));
    }

    public function placeCount(): int
    {
        return count(array_filter($this->union, fn (CoverageMunicipality $m) => $m->type === MunicipalityType::Place));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'union' => array_map(fn (CoverageMunicipality $m) => $m->toArray(), $this->union),
            'per_base' => array_map(fn (BaseCoverage $b) => [
                'location_id' => $b->locationId,
                'location_name' => $b->locationName,
                'radius_miles' => $b->radiusMiles,
                'municipalities' => array_map(fn (CoverageMunicipality $m) => $m->toArray(), $b->municipalities),
            ], $this->perBase),
        ];
    }
}
