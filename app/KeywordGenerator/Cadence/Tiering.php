<?php

namespace App\KeywordGenerator\Cadence;

use App\Enums\MarketTier;
use App\Enums\SampleType;
use App\Enums\SamplingTier;
use App\Enums\TargetLifecycle;

/**
 * Assigns a sampling tier from business value + market priority + lifecycle,
 * with an adaptive volatility bump. Drives cadence and budget degradation order.
 */
class Tiering
{
    public function tierFor(
        float $businessValue,
        ?MarketTier $marketTier = null,
        TargetLifecycle $lifecycle = TargetLifecycle::Active,
        bool $volatilityBump = false,
    ): SamplingTier {
        if ($lifecycle === TargetLifecycle::Parked) {
            return SamplingTier::C;
        }

        $rank = match (true) {
            $businessValue >= 0.6 => 2,
            $businessValue >= 0.35 => 1,
            default => 0,
        };

        if ($marketTier === MarketTier::Priority) {
            $rank++;
        }
        if ($lifecycle === TargetLifecycle::Stable) {
            $rank--;
        }
        if ($volatilityBump) {
            $rank++;
        }

        return match (max(0, min(2, $rank))) {
            2 => SamplingTier::A,
            1 => SamplingTier::B,
            default => SamplingTier::C,
        };
    }

    /**
     * Cadence in days per sample type for a tier.
     */
    public function cadenceDays(SamplingTier $tier, SampleType $type): int
    {
        return match ($tier) {
            SamplingTier::A => match ($type) {
                SampleType::Positions => 7,
                SampleType::Serp => 30,
                SampleType::Keywords => 30,
            },
            SamplingTier::B => match ($type) {
                SampleType::Positions => 30,
                SampleType::Serp => 90,
                SampleType::Keywords => 90,
            },
            SamplingTier::C => match ($type) {
                SampleType::Positions => 90,
                SampleType::Serp => 180,
                SampleType::Keywords => 180,
            },
        };
    }
}
