<?php

namespace App\KeywordGenerator\Scoring;

/**
 * Per-tenant tunable opportunity weights. BusinessValue carries the heaviest
 * weight by design — the differentiator from generic keyword tools.
 */
final class ScoringWeights
{
    public function __construct(
        public readonly float $demand = 0.35,
        public readonly float $intent = 0.25,
        public readonly float $value = 0.45,
    ) {}
}
