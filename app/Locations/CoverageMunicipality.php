<?php

namespace App\Locations;

use App\Enums\MunicipalityType;
use App\Integrations\Census\Municipality;

/**
 * A municipality in the coverage set: which base locations reach it (the union may merge
 * several), whether it was an owner-directed manual add, and its ACS population (for the
 * Large/Medium/Small grouping; null when unknown).
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
        public readonly bool $manual = false,
        public readonly ?int $population = null,
    ) {}

    /**
     * @param  array{large?: int, medium?: int}  $thresholds
     */
    public function bucket(array $thresholds = []): PopulationBucket
    {
        return PopulationBucket::for($this->population, $thresholds);
    }

    public function withPopulation(?int $population): self
    {
        return new self(
            $this->geoId, $this->name, $this->type, $this->state, $this->lat, $this->lng,
            $this->distanceMiles, $this->sourceLocationIds, $this->manual, $population,
        );
    }

    public static function fromMunicipality(Municipality $m, float $distanceMiles, string $sourceLocationId, bool $manual = false): self
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
            manual: $manual,
        );
    }

    /**
     * Union dedupe: keep the nearest distance, merge the reaching base locations, and
     * sticky-OR the manual flag (a town that is both radius and manual stays manual →
     * priority page candidate).
     */
    public function mergedWith(string $sourceLocationId, float $distanceMiles, bool $manual = false): self
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
            manual: $this->manual || $manual,
            population: $this->population,
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
            'manual' => $this->manual,
            'population' => $this->population,
            'bucket' => $this->bucket()->value,
        ];
    }
}
