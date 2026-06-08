<?php

namespace App\KeywordGenerator\Scoring;

use App\Enums\IntentLevel;

/**
 * Opportunity = (w_d·Demand + w_i·Intent + w_v·BusinessValue) × Beatability.
 * Demand is log-scaled volume; beatability is a multiplier so an unwinnable
 * SERP crushes the score regardless of volume. A vanity guard down-weights
 * high-volume / no-revenue informational keywords. Quick-win build priority
 * ≈ Opportunity × (1 − Difficulty).
 */
class OpportunityScorer
{
    private const VOLUME_REFERENCE = 10000;

    private const VANITY_PENALTY = 0.25;

    public function __construct(private readonly ScoringWeights $weights = new ScoringWeights) {}

    public function score(int $volume, int $difficulty, IntentLevel $intent, float $businessValue, float $beatability): ScoreResult
    {
        $demand = $this->demand($volume);
        $intentWeight = $intent->weight();

        $weighted = $this->weights->demand * $demand
            + $this->weights->intent * $intentWeight
            + $this->weights->value * $businessValue;

        $opportunity = $weighted * $beatability;

        $vanity = $demand > 0.6 && $businessValue < 0.25 && $intent->isInformational();
        if ($vanity) {
            $opportunity *= self::VANITY_PENALTY;
        }

        $quickWin = $opportunity * (1 - max(0, min(100, $difficulty)) / 100);

        return new ScoreResult($opportunity, $quickWin, $demand, $intentWeight, $businessValue, $beatability, $vanity);
    }

    private function demand(int $volume): float
    {
        return max(0.0, min(1.0, log10(max(0, $volume) + 1) / log10(self::VOLUME_REFERENCE)));
    }
}
