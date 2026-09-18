<?php

namespace App\Integrations\Usgs;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Ground elevation for a point, from the USGS National Map's elevation point query service.
 *
 * Keyless, free, federal, and 1-metre resolution over the US — verified live before this was written
 * (Doylestown returns 416.97 ft in about 0.9s). It is the honest source for "how high is this town":
 * Google's Elevation API would do it too, but that needs a key and a bill for a number nobody disputes.
 *
 * Open-Meteo's elevation endpoint was the other keyless candidate and was rejected on evidence: its free
 * tier is rate-limited per IP, and this control plane calls it from ONE address for every tenant — the
 * cap is already reachable (its archive API answered "Daily API request limit exceeded" while this was
 * being built).
 *
 * One point per request, so the caller batches, caps and resumes. Any failure yields null and the town
 * is simply left unfetched.
 */
class Elevation
{
    private const URL = 'https://epqs.nationalmap.gov/v1/json';

    public function __construct(
        private readonly Http $http,
        private readonly int $timeout = 15,
    ) {}

    /** Feet above sea level, or null when the service has no value for the point. */
    public function forPoint(float $lat, float $lng): ?float
    {
        try {
            $response = $this->http->timeout($this->timeout)->acceptJson()->get(self::URL, [
                'x' => $lng,
                'y' => $lat,
                'units' => 'Feet',
                'wkid' => 4326,
                'includeDate' => 'false',
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $value = $response->json('value');

        // The service answers with a large negative sentinel where it has no data — a town is not 3,000
        // feet below sea level, and storing that would put it in the copy.
        return is_numeric($value) && (float) $value > -1000.0 ? round((float) $value, 1) : null;
    }
}
