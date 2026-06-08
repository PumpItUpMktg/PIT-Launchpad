<?php

namespace App\KeywordGenerator\Scoring;

/**
 * The scored outcome for a keyword: the opportunity (value-heavy weighted sum ×
 * beatability multiplier) and the quick-win priority (opportunity × ease).
 */
final class ScoreResult
{
    public function __construct(
        public readonly float $opportunity,
        public readonly float $quickWin,
        public readonly float $demand,
        public readonly float $intentWeight,
        public readonly float $businessValue,
        public readonly float $beatability,
        public readonly bool $vanityPenalized = false,
    ) {}
}
