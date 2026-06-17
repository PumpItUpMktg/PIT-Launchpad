<?php

namespace App\Locations;

use App\Enums\MunicipalityType;
use App\Integrations\Census\Municipality;

/**
 * A municipality in the coverage set with its distance to the nearest reaching base
 * location and the set of base locations that reach it (the union may merge several).
 */
final class CoverageMunicipality
{
    /**
     * @param  list<string>  $sourceLocationIds
     */
    public function __construct(
        public readonly string $geoId,
        public readonly string $name,
        public readonly MunicipalityType $type,
        public readonly ?string $state,
        public readonly ?float $lat,
        public readonly ?float $lng,
        public readonly float $distanceMiles,
        public readonly array $sourceLocationIds,
    ) {}

    public static function fromMunicipality(Municipality $m, float $distanceMiles, string $sourceLocationId): self
    {
        return new self(
            geoId: $m->geoId,
            name: $m->name,
            type: $m->type,
            state: $m->state,
            lat: $m->lat,
            lng: $m->lng,
            distanceMiles: round($distanceMiles, 2),
            sourceLocationIds: [$sourceLocationId],
        );
    }

    /**
     * Union dedupe: keep the nearest distance and merge the reaching base locations.
     */
    public function mergedWith(string $sourceLocationId, float $distanceMiles): self
    {
        return new self(
            geoId: $this->geoId,
            name: $this->name,
            type: $this->type,
            state: $this->state,
            lat: $this->lat,
            lng: $this->lng,
            distanceMiles: min($this->distanceMiles, round($distanceMiles, 2)),
            sourceLocationIds: array_values(array_unique([...$this->sourceLocationIds, $sourceLocationId])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'geo_id' => $this->geoId,
            'name' => $this->name,
            'type' => $this->type->value,
            'state' => $this->state,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'distance_miles' => $this->distanceMiles,
            'source_location_ids' => $this->sourceLocationIds,
        ];
    }
}
