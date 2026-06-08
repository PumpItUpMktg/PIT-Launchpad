<?php

namespace App\KeywordGenerator\Cadence;

use App\Enums\SampleType;
use App\Enums\SamplingTier;

/**
 * One scheduled sampling task: what to sample, how often, what it costs, and
 * whether it is a forced event-trigger that the budget can never drop.
 */
final class SamplingTask
{
    public function __construct(
        public readonly string $ref,
        public readonly SampleType $sampleType,
        public readonly SamplingTier $tier,
        public readonly int $cadenceDays,
        public readonly float $costUnits,
        public readonly ?string $marketId = null,
        public readonly bool $isCoverageMarket = false,
        public readonly bool $forced = false,
    ) {}
}
