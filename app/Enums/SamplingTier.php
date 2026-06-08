<?php

namespace App\Enums;

/**
 * Sampling priority tier driving cadence and budget degradation order.
 */
enum SamplingTier: string
{
    case A = 'a';
    case B = 'b';
    case C = 'c';

    /**
     * Degradation order under a budget ceiling: C is dropped first, then B.
     */
    public function degradationRank(): int
    {
        return match ($this) {
            self::C => 0,
            self::B => 1,
            self::A => 2,
        };
    }
}
