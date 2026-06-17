<?php

namespace App\Integrations\Census;

/**
 * Capability role: address → point. The Locations coverage engine needs a geocoded
 * lat/lng per base; this resolves it from a street address. The default binding is the
 * keyless, US-only Census geocoder (consistent with the TIGERweb coverage source).
 * Returns null when the address can't be matched (the caller surfaces it).
 */
interface Geocoder
{
    public function geocode(string $address): ?GeocodeResult;
}
