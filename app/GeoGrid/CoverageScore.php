<?php

namespace App\GeoGrid;

/**
 * The single 0–100 "Local Visibility Score" for a coverage scan — one number for "where does this GBP rank
 * across the towns we target?" It is population-weighted rank-decay: each town earns credit for how well the
 * business ranks there (rank 1 = full credit, fading toward 0 by the scan depth, not-found = 0), and each
 * town's credit is weighted by its population, so being #1 in a big town counts for more than in a hamlet.
 *
 *   Score = Σ(population · credit(rank)) / Σ(population) × 100
 *
 *   100 = ranked #1 in every town, weighted by population.   0 = invisible everywhere.
 *
 * Deliberately simple and tunable — the decay curve is one line. Towns with unknown population still count
 * (weight 1) so coverage is never silently dropped.
 */
final class CoverageScore
{
    /**
     * @param  iterable<array{rank: int|null, population: int}>  $towns
     */
    public function compute(iterable $towns, int $depthCap): ?float
    {
        $depthCap = max(1, $depthCap);
        $weightedCredit = 0.0;
        $weight = 0.0;
        foreach ($towns as $town) {
            $w = (float) max(1, (int) $town['population']);   // unknown pop still counts (weight 1)
            $weight += $w;
            $weightedCredit += $w * self::credit($town['rank'], $depthCap);
        }

        return $weight > 0 ? round($weightedCredit / $weight * 100, 1) : null;
    }

    /**
     * Per-town rank credit in [0, 1]: rank 1 → 1.0, decaying linearly to ~0 at the scan depth; not found → 0.
     * At depth 20: #1 = 1.00, #3 = 0.90, #10 = 0.55, #20 = 0.05.
     */
    public static function credit(?int $rank, int $depthCap): float
    {
        if ($rank === null || $rank < 1) {
            return 0.0;
        }

        return max(0.0, min(1.0, ($depthCap + 1 - $rank) / $depthCap));
    }
}
