<?php

namespace App\Integrations\Census;

use App\Enums\MunicipalityType;

/**
 * A normalized municipality record returned by a {@see MunicipalityGazetteer}: a Census
 * place or county subdivision with its GEOID, name, state, and centroid. The coverage
 * engine distance-filters and unions these into the authoritative coverage set.
 */
final class Municipality
{
    public function __construct(
        public readonly string $geoId,
        public readonly string $name,
        public readonly MunicipalityType $type,
        public readonly ?string $state = null,
        public readonly ?float $lat = null,
        public readonly ?float $lng = null,
    ) {}
}
