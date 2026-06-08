<?php

namespace App\Operator\Controls;

use App\Enums\SamplingTier;
use App\Models\Site;

/**
 * §5 sampling-cadence control (read view). The sampling tiers (A/B/C) and their
 * budget-degradation order are §5-owned; the operator's per-tenant knob is the
 * budget ceiling (BudgetControl), which drives how far cadence degrades — C
 * tiers are dropped first, then B, before A.
 */
class CadenceControl
{
    /**
     * The sampling tiers in degradation order (dropped-first → kept-last).
     *
     * @return list<array{tier: string, degradation_rank: int}>
     */
    public function tiers(): array
    {
        $tiers = array_map(
            fn (SamplingTier $t): array => ['tier' => $t->value, 'degradation_rank' => $t->degradationRank()],
            SamplingTier::cases(),
        );

        usort($tiers, fn (array $a, array $b) => $a['degradation_rank'] <=> $b['degradation_rank']);

        return $tiers;
    }

    public function budgetCeiling(Site $site): ?int
    {
        return $site->budget_ceiling;
    }
}
