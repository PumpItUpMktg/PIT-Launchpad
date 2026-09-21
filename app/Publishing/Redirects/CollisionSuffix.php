<?php

namespace App\Publishing\Redirects;

/**
 * Whether a trailing `-N` on a slug is WordPress's collision suffix, or part of the title.
 *
 * WordPress appends `-2`, `-3`, `-10` when a slug it is asked to create already exists, and the chain is
 * CONTIGUOUS from 2 — the tenth copy of a post is only ever `-10` because two through nine were taken
 * first. So a large number is almost never a collision: `/sump-pump-maintenance-101` is "Maintenance 101",
 * `/best-pumps-2025` is a year, and treating either as a duplicate of its base retires a page that was
 * never a copy of anything. Sump Pump Gurus had exactly that — 7,073 impressions routed away from
 * "Maintenance 101" because `-101` matched a regex.
 *
 * The test is a ceiling on the number rather than a search for the rest of the chain, because the caller
 * that needs this decides one URL at a time and does not hold the set. A site with more than
 * `max_collision_suffix` genuine copies of one post loses only the members above the ceiling — every
 * lower one still collapses correctly — which is a far cheaper failure than retiring a real article.
 */
final class CollisionSuffix
{
    /** The default ceiling. Chains this long are already pathological; beyond it, a number is a title. */
    public const DEFAULT_MAX = 30;

    /**
     * The slug with its collision suffix removed, or null when the trailing number is not one.
     *
     * A suffix of `-0` or `-1` is not a collision either: WordPress starts at 2, and a slug ending in `-1`
     * is virtually always part of the name.
     */
    public static function strip(string $slug): ?string
    {
        if (! preg_match('/^(.*[^-])-(\d+)$/', $slug, $m)) {
            return null;
        }

        // The pattern requires a non-hyphen before the dash, so the base is non-empty by construction.
        $n = (int) $m[2];

        return $n >= 2 && $n <= self::max() ? $m[1] : null;
    }

    /** Whether this slug carries a plausible collision suffix. */
    public static function has(string $slug): bool
    {
        return self::strip($slug) !== null;
    }

    private static function max(): int
    {
        $configured = config('launchpad.legacy_redirect.max_collision_suffix', self::DEFAULT_MAX);

        return is_numeric($configured) && (int) $configured >= 2 ? (int) $configured : self::DEFAULT_MAX;
    }
}
