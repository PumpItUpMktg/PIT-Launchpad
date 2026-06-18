<?php

namespace App\Enums;

use App\Models\Site;

/**
 * The 4-tier population grouping for covered towns (the page-selection drip pool view):
 * Major / Large / Medium / Small. A town with no known population (ACS miss / no key) has
 * NO tier (null → "ungrouped"). Thresholds are per-tenant (see {@see Site::coverageThresholds()});
 * defaults major ≥ 50k, large ≥ 30k, medium ≥ 15k, else small. Persisted as
 * `coverage_areas.size_tier`, derived at write time — never user-edited.
 */
enum SizeTier: string
{
    case Major = 'major';
    case Large = 'large';
    case Medium = 'medium';
    case Small = 'small';

    /**
     * @param  array{major?: int, large?: int, medium?: int}  $thresholds
     */
    public static function forPopulation(?int $population, array $thresholds = []): ?self
    {
        if ($population === null) {
            return null; // ungrouped — no population
        }

        $major = $thresholds['major'] ?? 50000;
        $large = $thresholds['large'] ?? 30000;
        $medium = $thresholds['medium'] ?? 15000;

        return match (true) {
            $population >= $major => self::Major,
            $population >= $large => self::Large,
            $population >= $medium => self::Medium,
            default => self::Small,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** The mockup-v3 ramp swatch for this tier. */
    public function color(): string
    {
        return match ($this) {
            self::Major => '#0A4F4F',
            self::Large => '#0E6B6B',
            self::Medium => '#4E9A98',
            self::Small => '#A6CFCD',
        };
    }

    /** The swatch for towns with no tier (no population). */
    public const UNGROUPED_COLOR = '#C3CCD6';
}
