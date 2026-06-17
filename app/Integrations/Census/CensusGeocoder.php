<?php

namespace App\Integrations\Census;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Real geocoding via the US Census Geocoder one-line-address endpoint — no API key,
 * US-only, the same authority as the TIGERweb coverage enumeration (so the point and the
 * municipality union come from one consistent source). Returns the first address match's
 * coordinates (`x` = lng, `y` = lat). Any transport/parse failure resolves to null
 * rather than throwing — geocoding is operator-driven and retryable. Tests Http::fake this.
 */
final class CensusGeocoder implements Geocoder
{
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly string $benchmark = 'Public_AR_Current',
        private readonly int $timeout = 15,
    ) {}

    public function geocode(string $address): ?GeocodeResult
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->acceptJson()
                ->get(rtrim($this->baseUrl, '/').'/locations/onelineaddress', [
                    'address' => $address,
                    'benchmark' => $this->benchmark,
                    'format' => 'json',
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $match = $response->json('result.addressMatches.0');
        if (! is_array($match)) {
            return null;
        }

        $lng = $match['coordinates']['x'] ?? null;
        $lat = $match['coordinates']['y'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return new GeocodeResult((float) $lat, (float) $lng, (string) ($match['matchedAddress'] ?? $address));
    }
}
