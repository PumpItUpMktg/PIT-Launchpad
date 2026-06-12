<?php

namespace App\Integrations\Places;

/**
 * Normalized Place Details — the fields the location form autofills. `hours` is
 * already mapped to the stored per-day shape; `addressComponents` is the raw
 * structured breakdown; `address` stays the formatted display string.
 */
final class PlaceDetails
{
    /**
     * @param  array<int, array<string, mixed>>  $addressComponents
     * @param  array<string, mixed>  $hours
     */
    public function __construct(
        public readonly string $placeId,
        public readonly string $name,
        public readonly string $address,
        public readonly array $addressComponents,
        public readonly ?string $phone,
        public readonly array $hours,
        public readonly ?float $lat,
        public readonly ?float $lng,
        public readonly ?string $gbpUrl,
        public readonly ?string $website,
    ) {}
}
