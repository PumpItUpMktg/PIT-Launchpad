<?php

namespace App\Operator\Coverage;

use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The §7b coverage/targeting workspace's keyword views: coverage **gaps**
 * (uncovered keywords, §5-opportunity-sorted) and the **target queue** (what is
 * lined up for the §6 directed lane). The operator promotes/demotes priority;
 * ties break on the §5 opportunity_score.
 */
class TargetQueue
{
    /**
     * Coverage gaps: keywords with no content covering them yet, highest
     * opportunity first.
     *
     * @return Collection<int, Keyword>
     */
    public function gaps(?string $siteId = null): Collection
    {
        return $this->base($siteId)
            ->whereNull('target_content_id')
            ->orderByDesc('opportunity_score')
            ->get();
    }

    /**
     * The prioritized target queue: operator priority first, then opportunity.
     *
     * @return Collection<int, Keyword>
     */
    public function queue(?string $siteId = null): Collection
    {
        return $this->base($siteId)
            ->orderByDesc('priority')
            ->orderByDesc('opportunity_score')
            ->get();
    }

    public function promote(Keyword $keyword): Keyword
    {
        $keyword->forceFill(['priority' => (int) $keyword->priority + 1])->save();

        return $keyword;
    }

    public function demote(Keyword $keyword): Keyword
    {
        $keyword->forceFill(['priority' => (int) $keyword->priority - 1])->save();

        return $keyword;
    }

    /**
     * @return Builder<Keyword>
     */
    private function base(?string $siteId): Builder
    {
        return Keyword::withoutGlobalScope(SiteScope::class)
            ->when($siteId !== null, fn (Builder $q) => $q->where('site_id', $siteId));
    }
}
