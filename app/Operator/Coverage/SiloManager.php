<?php

namespace App\Operator\Coverage;

use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\SiloCreator\ViabilityGuard;

/**
 * Silo-management support for the §7b workspace: surfaces §4's ViabilityGuard so
 * the operator never stands up (or keeps) a silo too thin to ever hold more than
 * one page — the same thin-page guard §4 proposes silos under.
 */
class SiloManager
{
    public function __construct(
        private readonly ViabilityGuard $guard,
    ) {}

    /**
     * How many keyword targets back this silo — its viability support.
     */
    public function supportCount(Silo $silo): int
    {
        return $silo->keywords()->withoutGlobalScope(SiteScope::class)->count();
    }

    public function isViable(Silo $silo): bool
    {
        return $this->guard->isViable($this->supportCount($silo));
    }

    public function threshold(): int
    {
        return $this->guard->threshold();
    }

    /**
     * The operator-facing reason a silo is below the viability floor, or null
     * when it clears it.
     */
    public function viabilityWarning(Silo $silo): ?string
    {
        $support = $this->supportCount($silo);

        if ($support >= $this->threshold()) {
            return null;
        }

        return "Thin silo: {$support} keyword target(s), below the viability floor of {$this->threshold()}.";
    }
}
