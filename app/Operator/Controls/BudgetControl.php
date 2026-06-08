<?php

namespace App\Operator\Controls;

use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * §5 per-tenant budget control: set the sampling budget ceiling (which degrades
 * coverage/low tiers first) and surface usage-against-budget. Metered billing is
 * deferred (§9 #6), so usage is **read-only/advisory** — the position-sample
 * volume this period stands in for spend.
 */
class BudgetControl
{
    public function setCeiling(Site $site, ?int $ceiling): Site
    {
        $site->forceFill(['budget_ceiling' => $ceiling])->save();

        return $site;
    }

    public function ceiling(Site $site): ?int
    {
        return $site->budget_ceiling;
    }

    /**
     * Read-only usage this period (position samples captured this month).
     */
    public function usage(Site $site): int
    {
        return PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('captured_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function remaining(Site $site): ?int
    {
        $ceiling = $site->budget_ceiling;

        return $ceiling === null ? null : max(0, $ceiling - $this->usage($site));
    }

    public function overBudget(Site $site): bool
    {
        $ceiling = $site->budget_ceiling;

        return $ceiling !== null && $this->usage($site) > $ceiling;
    }
}
