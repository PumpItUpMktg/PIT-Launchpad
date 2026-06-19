<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangeFlagType;
use App\Enums\ArrangementSource;
use App\Models\ArrangementFlag;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;

/**
 * Resolves an operator's accept/dismiss on a persisted {@see ArrangementFlag} (increment 4b),
 * under the eager-apply model: the flagged change is already applied as the default (except a
 * sub-hub demotion, which Pass C only recommends).
 *
 *   - accept: apply the recommendation (demote, for a sub-hub) and mark it confirmed.
 *   - dismiss: leave the current structure as-is and mark it confirmed too, so it won't re-flag.
 *
 * The only flag where accept and dismiss diverge is the sub-hub demotion (accept demotes;
 * dismiss leaves the two silos separate). For every eagerly-applied flag both simply confirm
 * the current state — the operator reshapes further via the prune's existing edit tools. A
 * confirmed decision is preserved across re-runs (the §10 twin); resolving deletes the flag row.
 */
final class FlagResolver
{
    public function __construct(private readonly SubHubDemoter $demoter) {}

    public function accept(Site $site, ArrangementFlag $flag): bool
    {
        $ok = match ($flag->type) {
            ArrangeFlagType::SubHubDemotion => $this->applyDemotion($site, $flag),
            ArrangeFlagType::KeywordCollision, ArrangeFlagType::SubHubKeywordCollision => $this->confirmKeyword($site, $flag->spoke_id),
            default => $this->confirmStructure($site, $flag->spoke_id),
        };

        if ($ok) {
            $flag->delete();
        }

        return $ok;
    }

    public function dismiss(Site $site, ArrangementFlag $flag): bool
    {
        // Leave the current structure as-is, just lock it so it won't re-flag. For a sub-hub
        // demotion that means confirming the pillar in place (the two silos stay separate).
        $ok = match ($flag->type) {
            ArrangeFlagType::KeywordCollision, ArrangeFlagType::SubHubKeywordCollision => $this->confirmKeyword($site, $flag->spoke_id),
            default => $this->confirmStructure($site, $flag->spoke_id),
        };

        if ($ok) {
            $flag->delete();
        }

        return $ok;
    }

    private function applyDemotion(Site $site, ArrangementFlag $flag): bool
    {
        $pillar = $this->spoke($site, $flag->spoke_id);
        $target = $flag->candidates[0]['name'] ?? null;
        if ($pillar === null || ! is_string($target)) {
            return false;
        }

        return $this->demoter->demote($site, (string) $pillar->silo, $target, ArrangementSource::Confirmed);
    }

    /** Lock a spoke's structural placement (and any section folded into it). */
    private function confirmStructure(Site $site, ?string $spokeId): bool
    {
        $spoke = $this->spoke($site, $spokeId);
        if ($spoke === null) {
            return false;
        }

        $spoke->update(['arrangement_source' => ArrangementSource::Confirmed]);
        Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('fold_into_id', $spoke->id)
            ->update(['arrangement_source' => ArrangementSource::Confirmed]);

        return true;
    }

    private function confirmKeyword(Site $site, ?string $spokeId): bool
    {
        $spoke = $this->spoke($site, $spokeId);
        if ($spoke === null) {
            return false;
        }

        $spoke->update(['keyword_source' => ArrangementSource::Confirmed]);

        return true;
    }

    private function spoke(Site $site, ?string $spokeId): ?Spoke
    {
        if ($spokeId === null) {
            return null;
        }

        return Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereKey($spokeId)
            ->first();
    }
}
