<?php

namespace App\Integrations\Census;

/**
 * Test/default-offline geocoder: returns a fixed point for any non-empty address (and
 * null for empty or an explicitly "unmatchable" address), so CI makes no live call.
 */
final class MockCensusGeocoder implements Geocoder
{
    /**
     * @param  list<string>  $unmatchable  addresses that resolve to null (no match)
     */
    public function __construct(
        private readonly float $lat = 40.7357,
        private readonly float $lng = -74.1724,
        private readonly array $unmatchable = [],
    ) {}

    public function geocode(string $address): ?GeocodeResult
    {
        $address = trim($address);
        if ($address === '' || in_array($address, $this->unmatchable, true)) {
            return null;
        }

        return new GeocodeResult($this->lat, $this->lng, $address);
    }
}
