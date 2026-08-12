<?php

namespace App\JobCapture\Geography;

/**
 * Privacy jitter for a job's PUBLIC coordinates (§4). Displaces the true capture point by a random offset
 * sampled UNIFORMLY IN THE DISC of radius {@see $radiusMiles} (~0.5 mile):
 *
 *  - the radius is drawn as `radiusMiles · √U`, not `radiusMiles · U` — a naive uniform radius clumps
 *    points near the centre, which would leak the true location's rough position over many jobs;
 *  - the longitude offset is divided by `cos(latitude)` so the displacement disc stays circular on the
 *    ground instead of stretching east–west (a degree of longitude shrinks toward the poles).
 *
 * The result is computed ONCE at capture and stored on the job — never recalculated per render — and the
 * public map draws a 1-mile circle (larger than this 0.5-mile jitter) so the true address is always well
 * inside the circle, never near its edge. The true street address and exact point are never published.
 */
final class Jitter
{
    private const MILES_PER_DEGREE_LAT = 69.0;

    public function __construct(private readonly float $radiusMiles = 0.5) {}

    /**
     * The jittered public point for a true (lat, lng).
     *
     * @return array{lat: float, lng: float}
     */
    public function apply(float $lat, float $lng): array
    {
        $radius = $this->radiusMiles * sqrt($this->unitFraction());
        $theta = 2 * M_PI * $this->unitFraction();

        $deltaLat = ($radius / self::MILES_PER_DEGREE_LAT) * sin($theta);

        $cosLat = cos(deg2rad($lat));
        $milesPerDegreeLng = self::MILES_PER_DEGREE_LAT * (abs($cosLat) < 1e-9 ? 1e-9 : $cosLat);
        $deltaLng = ($radius / $milesPerDegreeLng) * cos($theta);

        return ['lat' => $lat + $deltaLat, 'lng' => $lng + $deltaLng];
    }

    /** A uniform fraction in [0, 1). Isolated so a test can substitute a deterministic sequence. */
    protected function unitFraction(): float
    {
        return random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
    }
}
