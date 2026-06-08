<?php

namespace App\KeywordGenerator\Scoring;

use App\Enums\MarketTier;
use App\Models\Market;
use App\Models\Silo;

/**
 * Resolves a keyword's BusinessValue from the silo/service mapping it inherits:
 * revenue-tied (most-profitable + want-to-grow) plus market priority. This is
 * what ties every target back to revenue.
 */
class BusinessValueResolver
{
    public function resolve(?Silo $silo, ?Market $market = null): float
    {
        $value = 0.2;

        if ($silo !== null) {
            $services = $silo->relationLoaded('services') ? $silo->services : $silo->services()->get();

            if ($services->contains(fn ($service) => (bool) $service->is_most_profitable)) {
                $value += 0.4;
            }
            if ($services->contains(fn ($service) => (bool) $service->is_growth_priority)) {
                $value += 0.25;
            }
        }

        $value += match ($market?->tier) {
            MarketTier::Priority => 0.15,
            MarketTier::Coverage => 0.05,
            default => 0.0,
        };

        return max(0.0, min(1.0, $value));
    }
}
