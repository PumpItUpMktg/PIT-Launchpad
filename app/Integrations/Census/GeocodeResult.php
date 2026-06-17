<?php

namespace App\Integrations\Census;

/**
 * A resolved geocode: the point plus the address the geocoder matched (so the operator
 * can confirm it resolved to the right place).
 */
final class GeocodeResult
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
        public readonly string $matchedAddress,
    ) {}
}
