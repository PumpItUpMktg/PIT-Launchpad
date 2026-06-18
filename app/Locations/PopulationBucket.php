<?php

namespace App\Locations;

/**
 * Groups a town by population for the coverage display: Large / Medium / Small. Thresholds
 * are configurable (config/launchpad.php → locations.population_buckets); a town with no
 * known population (ACS miss / no key) is `unknown`.
 */
enum PopulationBucket: string
{
    case Large = 'large';
    case Medium = 'medium';
    case Small = 'small';
    case Unknown = 'unknown';

    /**
     * @param  array{large?: int, medium?: int}  $thresholds
     */
    public static function for(?int $population, array $thresholds = []): self
    {
        if ($population === null) {
            return self::Unknown;
        }

        $large = $thresholds['large'] ?? 25000;
        $medium = $thresholds['medium'] ?? 15000;

        return match (true) {
            $population > $large => self::Large,
            $population >= $medium => self::Medium,
            default => self::Small,
        };
    }
}
