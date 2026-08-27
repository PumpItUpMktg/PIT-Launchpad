<?php

namespace App\GeoGrid;

/**
 * The geo-grid heat-map color functions — the single source of truth for how a point's rank (absolute) and a
 * point's movement-since-last-scan (delta) map to a color. Kept as its own class from day one per the build
 * spec: absolute rank is the operator default now, but when the grid eventually goes client-facing the delta
 * view becomes the default, and having both functions already in the codebase costs nothing today.
 *
 * Colors follow the platform's established heat language (green = strong, amber = partial, red = weak, grey =
 * absent/untested) so the grid reads the same as the AI-coverage and geo-activity boards.
 */
final class GeoGridPalette
{
    /** Absent point (business didn't rank within depth_cap) — faint grey. */
    public const ABSENT = '#9ca3af';

    /**
     * Background color for a point's absolute rank. rank null = not found within depth_cap.
     * Bucketed 1–3 / 4–7 / 8–10 / 11–15 / 16+ so a thumbnail reads at a glance.
     */
    public static function absolute(?int $rank): string
    {
        return match (true) {
            $rank === null => self::ABSENT,
            $rank <= 3 => '#15803d',   // top-3 — strong local visibility (SoLV)
            $rank <= 7 => '#65a30d',   // page-one-ish
            $rank <= 10 => '#ca8a04',  // amber
            $rank <= 15 => '#c2410c',  // weak
            default => '#c0392b',      // deep in the pack
        };
    }

    /**
     * Background color for a point's change since the previous scan. Lower rank is better, so a drop in the
     * numeric rank is an improvement (green). New = newly ranking, Lost = fell out of depth_cap entirely.
     */
    public static function delta(?int $current, ?int $previous): string
    {
        if ($current === null && $previous === null) {
            return self::ABSENT;                      // never ranked here
        }
        if ($previous === null) {
            return '#2563eb';                         // newly ranking (blue)
        }
        if ($current === null) {
            return '#7f1d1d';                         // lost the point entirely (dark red)
        }
        $move = $previous - $current;                 // positive = moved up (improved)

        return match (true) {
            $move > 0 => '#15803d',                   // improved
            $move < 0 => '#c0392b',                   // slipped
            default => self::ABSENT,                  // unchanged
        };
    }

    /**
     * Signed rank movement, previous − current (positive = improved), or null when either endpoint is
     * unranked (a categorical new/lost rather than a numeric move).
     */
    public static function move(?int $current, ?int $previous): ?int
    {
        if ($current === null || $previous === null) {
            return null;
        }

        return $previous - $current;
    }
}
