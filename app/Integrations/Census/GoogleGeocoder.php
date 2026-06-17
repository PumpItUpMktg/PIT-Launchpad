<?php

namespace App\Integrations\Census;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Address → point via the Google Geocoding API. Unlike the Census geocoder, Google
 * resolves unincorporated communities and edge addresses ("2753 W Main St, Trooper PA" →
 * near Norristown) — common for home-services bases that Census silently misses.
 * Geocoding is per-location and the result is persisted on the Location, so cost is
 * trivial (not per-page-load like map tiles, which stay on free OSM/CARTO).
 *
 * Falls back to the injected geocoder (Census, no key) when no key is set or Google can't
 * resolve / errors — so the seam degrades instead of dead-ending. Tests Http::fake this.
 */
final class GoogleGeocoder implements Geocoder
{
    public function __construct(
        private readonly Http $http,
        private readonly string $apiKey,
        private readonly string $endpoint = 'https://maps.googleapis.com/maps/api/geocode/json',
        private readonly ?Geocoder $fallback = null,
        private readonly int $timeout = 15,
    ) {}

    public function geocode(string $address): ?GeocodeResult
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        if ($this->apiKey === '') {
            return $this->fallback?->geocode($address);
        }

        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->acceptJson()
                ->get($this->endpoint, ['address' => $address, 'key' => $this->apiKey]);
        } catch (Throwable $e) {
            Log::warning('Geocoder: Google call failed, falling back to Census.', ['error' => $e->getMessage()]);

            return $this->fallback?->geocode($address);
        }

        $status = $response->json('status');
        $result = $response->successful() && $status === 'OK' ? $response->json('results.0') : null;

        if (! is_array($result)) {
            // status REQUEST_DENIED = Geocoding API not enabled on the key — never silent.
            Log::warning('Geocoder: Google did not resolve, falling back to Census.', [
                'status' => $status,
                'error_message' => $response->json('error_message'),
            ]);

            return $this->fallback?->geocode($address);
        }

        $lat = $result['geometry']['location']['lat'] ?? null;
        $lng = $result['geometry']['location']['lng'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return $this->fallback?->geocode($address);
        }

        return new GeocodeResult((float) $lat, (float) $lng, (string) ($result['formatted_address'] ?? $address));
    }
}
