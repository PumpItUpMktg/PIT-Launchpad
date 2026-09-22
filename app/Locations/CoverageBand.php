<?php

namespace App\Locations;

use App\Enums\SizeTier;
use App\Models\Location;

/**
 * The roll-out BAND a covered town belongs to, and the ordered chain of bands a location builds through.
 *
 * Two territory modes, one gate:
 *  - **County** (the default): bands are the size tiers — major → large → medium → small → ungrouped. The
 *    largest towns build first; each smaller tier unlocks once the one above is indexed.
 *  - **Proximity**: bands are distance rings from the location — `ring5` → `ring10` → `ring15` (the
 *    configured ring steps up to the location's outer reach). The NEAREST towns build first; each further
 *    ring unlocks once the closer one is indexed. Town size plays no part — an auto shop's customers come
 *    from the next town over, not the biggest city in the county.
 *
 * The band is persisted on `coverage_areas.band` at write time (like `size_tier`) so every reader — the
 * tier gate, the drip seed, the panels, the progression board — keys on the same value.
 */
final class CoverageBand
{
    public const UNGROUPED = 'ungrouped';

    public const COUNTY_CHAIN = ['major', 'large', 'medium', 'small', self::UNGROUPED];

    /** The teal ramp, by position in the chain (index 4 = the ungrouped grey). */
    private const RAMP = ['#0A4F4F', '#0E6B6B', '#4E9A98', '#A6CFCD', SizeTier::UNGROUPED_COLOR];

    public const DEFAULT_RADIUS = 15;

    /** @return list<int> the configured ring steps (miles), ascending */
    public static function rings(): array
    {
        $rings = array_map(intval(...), (array) config('launchpad.locations.proximity_rings', [5, 10, 15, 25]));
        $rings = array_values(array_unique(array_filter($rings, fn (int $r): bool => $r > 0)));
        sort($rings);

        return $rings;
    }

    public static function ringKey(int $miles): string
    {
        return "ring{$miles}";
    }

    public static function isRing(string $band): bool
    {
        return str_starts_with($band, 'ring');
    }

    public static function ringMiles(string $band): ?int
    {
        return self::isRing($band) ? (int) substr($band, 4) : null;
    }

    /**
     * The ordered band chain for a location: the ring steps within its outer reach (the reach itself
     * closes the chain when it falls between steps — reach 12 → ring5, ring10, ring12), or the county
     * tiers. A null location (a town serving no market) reads as county.
     *
     * @return list<string>
     */
    public static function chain(?Location $location): array
    {
        if ($location === null || ! $location->isProximity()) {
            return self::COUNTY_CHAIN;
        }

        $reach = $location->coverageRadiusMiles();
        $steps = array_values(array_filter(self::rings(), fn (int $r): bool => $r < $reach));
        $steps[] = $reach;

        return array_map(self::ringKey(...), $steps);
    }

    /** The band a town at `$distance` miles falls in — the first ring that reaches it (never beyond the chain). */
    public static function ringFor(float $distance, array $chain): string
    {
        foreach ($chain as $band) {
            $miles = self::ringMiles($band);
            if ($miles !== null && $distance <= $miles) {
                return $band;
            }
        }

        return $chain[count($chain) - 1] ?? self::UNGROUPED;
    }

    /**
     * The band to persist for one covered town: its distance ring for a proximity market, else its size
     * tier (null = ungrouped) for a county market.
     */
    public static function forArea(?Location $location, float $distanceMiles, ?string $sizeTier): ?string
    {
        if ($location === null || ! $location->isProximity()) {
            return $sizeTier;
        }

        return self::ringFor($distanceMiles, self::chain($location));
    }

    /**
     * The bands that build immediately on first setup, per market: the configured size tiers (major +
     * large) for a county market; ONLY the innermost ring for a proximity market — the further rings wait
     * on the gate. Larger towns get no head start by distance.
     *
     * @return list<string>
     */
    public static function autoSelect(?Location $location): array
    {
        if ($location === null || ! $location->isProximity()) {
            return array_values(array_map(strval(...), (array) config('launchpad.drip.auto_select_tiers', ['major', 'large'])));
        }

        $chain = self::chain($location);

        return [$chain[0]];
    }

    /** @param  list<string>  $chain */
    public static function label(string $band, array $chain = self::COUNTY_CHAIN): string
    {
        $miles = self::ringMiles($band);
        if ($miles === null) {
            return ucfirst($band);
        }

        $idx = array_search($band, $chain, true);
        $prev = $idx !== false && $idx > 0 ? self::ringMiles($chain[$idx - 1]) : null;

        return $prev === null ? "Within {$miles} mi" : "{$prev}–{$miles} mi";
    }

    /**
     * Display metadata (label + swatch) for every band of a chain, in chain order.
     *
     * @param  list<string>  $chain
     * @return array<string, array{label: string, color: string}>
     */
    public static function meta(array $chain): array
    {
        $meta = [];
        foreach ($chain as $i => $band) {
            $color = $band === self::UNGROUPED
                ? SizeTier::UNGROUPED_COLOR
                : (SizeTier::tryFrom($band)?->color() ?? self::RAMP[min($i, count(self::RAMP) - 2)]);
            $meta[$band] = ['label' => self::label($band, $chain), 'color' => $color];
        }

        return $meta;
    }
}
