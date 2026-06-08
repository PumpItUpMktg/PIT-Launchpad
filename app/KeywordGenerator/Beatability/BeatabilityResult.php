<?php

namespace App\KeywordGenerator\Beatability;

use App\Enums\BeatabilityLane;

/**
 * The beatability assessment: a 0..1 opportunity multiplier, the lane tag that
 * routes the target, a rationale, and the floor/parking outcome.
 */
final class BeatabilityResult
{
    public function __construct(
        public readonly float $score,
        public readonly BeatabilityLane $lane,
        public readonly string $rationale,
        public readonly bool $parked = false,
        public readonly bool $longPlay = false,
        public readonly ?string $marketId = null,
    ) {}
}
