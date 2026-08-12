<?php

use App\JobCapture\Geography\Jitter;

/**
 * The jitter's max displacement is the configured 0.5 mile PLUS a small slack: Jitter uses a flat-earth
 * conversion (69 mi/°) while this test measures true great-circle distance (~69.09 mi/°), a ~0.1%
 * discrepancy. The jitter is an approximate 0.5-mile displacement by design — the public map draws a wider
 * 1-mile circle precisely so the true point always sits comfortably inside.
 */
const MAX_JITTER_MILES = 0.5 * 1.01;

/** Great-circle distance in miles between two points. */
function haversineMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earth = 3958.8; // miles
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    return $earth * 2 * asin(min(1.0, sqrt($a)));
}

test('jitter offsets the point within the disc for a deterministic fraction', function () {
    // √0.5 radius, θ = π → moves due west, well inside the 0.5-mile disc.
    $jitter = new class(0.5) extends Jitter
    {
        protected function unitFraction(): float
        {
            return 0.5;
        }
    };

    ['lat' => $jLat, 'lng' => $jLng] = $jitter->apply(40.0, -74.0);

    expect(haversineMiles(40.0, -74.0, $jLat, $jLng))
        ->toBeGreaterThan(0.0)
        ->toBeLessThanOrEqual(MAX_JITTER_MILES);
});

test('random jitter always lands inside the configured radius', function () {
    $jitter = new Jitter(0.5);

    for ($i = 0; $i < 300; $i++) {
        ['lat' => $jLat, 'lng' => $jLng] = $jitter->apply(40.5, -74.4);
        expect(haversineMiles(40.5, -74.4, $jLat, $jLng))->toBeLessThanOrEqual(MAX_JITTER_MILES);
    }
});
