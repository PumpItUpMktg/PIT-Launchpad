<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangeFlagType;
use App\Enums\ArrangementSource;
use App\Enums\SpokeGranularity;
use App\Interview\Prune\PruneEngine;
use App\Models\ArrangementFlag;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;

/**
 * Resolves an operator's accept/dismiss on a persisted {@see ArrangementFlag} (increment 4b).
 * Every pass auto-applies its best pick and flags the judgment calls; **accept confirms the
 * applied pick, dismiss applies the persisted alternative** — and both clear the flag so it
 * won't re-surface (a confirmed decision is preserved across re-runs by the §10 twin):
 *
 *   - dedup ambiguous → accept keeps the winner home; dismiss re-homes onto the runner-up.
 *   - sub-hub demotion → accept keeps the demotion; dismiss un-demotes (keep separate).
 *   - nest low-confidence → accept folds into the best core; dismiss keeps it on the pillar.
 *   - keyword collision → accept keeps the keyword; dismiss reassigns the umbrella fallback.
 *   - dead silo → accept folds it into the most-clustered sibling; dismiss keeps it standalone.
 *
 * Resolving deletes the flag row and clears the spoke's `flagged` once it carries no other flag.
 */
final class FlagResolver
{
    public function __construct(
        private readonly SubHubDemoter $demoter,
        private readonly PruneEngine $prune,
    ) {}

    public function accept(Site $site, ArrangementFlag $flag): bool
    {
        $ok = match ($flag->type) {
            ArrangeFlagType::DedupAmbiguous, ArrangeFlagType::NestLowConfidence => $this->confirmStructure($site, $flag->spoke_id),
            ArrangeFlagType::SubHubDemotion => $this->confirmStructure($site, $flag->spoke_id),
            ArrangeFlagType::KeywordCollision, ArrangeFlagType::SubHubKeywordCollision => $this->confirmKeyword($site, $flag->spoke_id),
            ArrangeFlagType::DeadSilo => $this->foldDeadSilo($site, $flag),
        };

        // Nest accept is the alternative (fold into best core); the others above confirm in place.
        if ($ok && $flag->type === ArrangeFlagType::NestLowConfidence) {
            $ok = $this->nestInto($site, $flag->spoke_id, $this->altSpokeId($flag));
        }

        return $ok && $this->finish($site, $flag);
    }

    public function dismiss(Site $site, ArrangementFlag $flag): bool
    {
        $ok = match ($flag->type) {
            ArrangeFlagType::DedupAmbiguous => $this->rehome($site, $flag->spoke_id, $this->altSpokeId($flag)),
            ArrangeFlagType::SubHubDemotion => $this->demoter->promote($site, $this->siloOf($site, $flag->spoke_id) ?? ''),
            ArrangeFlagType::NestLowConfidence => $this->confirmStructure($site, $flag->spoke_id), // keep on pillar
            ArrangeFlagType::KeywordCollision, ArrangeFlagType::SubHubKeywordCollision => $this->reassignKeyword($site, $flag->spoke_id, $this->altKeyword($flag)),
            ArrangeFlagType::DeadSilo => $this->confirmStructure($site, $flag->spoke_id), // keep standalone
        };

        return $ok && $this->finish($site, $flag);
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
            ->where('site_id', $site->id)->where('fold_into_id', $spoke->id)
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

    private function reassignKeyword(Site $site, ?string $spokeId, ?string $keyword): bool
    {
        $spoke = $this->spoke($site, $spokeId);
        if ($spoke === null || $keyword === null) {
            return false;
        }
        $spoke->update(['primary_keyword' => $keyword, 'keyword_source' => ArrangementSource::Confirmed]);

        return true;
    }

    /** Nest accept: fold the spoke into the best below-floor core (the alternative), confirmed. */
    private function nestInto(Site $site, ?string $spokeId, ?string $coreId): bool
    {
        $spoke = $this->spoke($site, $spokeId);
        $core = $this->spoke($site, $coreId);
        if ($spoke === null || $core === null) {
            return false;
        }
        $spoke->update([
            'granularity' => SpokeGranularity::Folded,
            'fold_into_id' => $core->id,
            'arrangement_source' => ArrangementSource::Confirmed,
        ]);

        return true;
    }

    /** Dedup dismiss: make the runner-up the home — the winner + its sections fold onto it. */
    private function rehome(Site $site, ?string $winnerId, ?string $runnerUpId): bool
    {
        $winner = $this->spoke($site, $winnerId);
        $runnerUp = $this->spoke($site, $runnerUpId);
        if ($winner === null || $runnerUp === null) {
            return false;
        }

        $runnerUp->update([
            'granularity' => SpokeGranularity::OwnPage,
            'fold_into_id' => null,
            'arrangement_source' => ArrangementSource::Confirmed,
        ]);
        // The winner's existing sections re-point to the new home, then the winner folds in too.
        Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('fold_into_id', $winner->id)
            ->update(['silo' => $runnerUp->silo, 'fold_into_id' => $runnerUp->id, 'arrangement_source' => ArrangementSource::Confirmed]);
        $winner->update([
            'silo' => $runnerUp->silo,
            'granularity' => SpokeGranularity::Folded,
            'fold_into_id' => $runnerUp->id,
            'arrangement_source' => ArrangementSource::Confirmed,
        ]);

        return true;
    }

    /** Dead-silo accept: fold the thin silo into the most-clustered sibling (the alternative). */
    private function foldDeadSilo(Site $site, ArrangementFlag $flag): bool
    {
        $silo = $this->siloOf($site, $flag->spoke_id);
        $into = is_string($flag->alternative['silo'] ?? null) ? $flag->alternative['silo'] : null;
        if ($silo === null || $into === null) {
            return false;
        }
        $this->prune->foldSilo($site, $silo, $into);
        // Confirm the former pillar so it isn't re-flagged.
        $this->spoke($site, $flag->spoke_id)?->update(['arrangement_source' => ArrangementSource::Confirmed]);

        return true;
    }

    /** Delete the flag row; clear the spoke's flagged once no other flag references it. */
    private function finish(Site $site, ArrangementFlag $flag): bool
    {
        $spokeId = $flag->spoke_id;
        $flag->delete();

        if ($spokeId !== null
            && ! ArrangementFlag::query()->where('site_id', $site->id)->where('spoke_id', $spokeId)->exists()) {
            $this->spoke($site, $spokeId)?->update(['flagged' => false]);
        }

        return true;
    }

    private function altSpokeId(ArrangementFlag $flag): ?string
    {
        $id = $flag->alternative['spoke_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    private function altKeyword(ArrangementFlag $flag): ?string
    {
        $kw = $flag->alternative['keyword'] ?? null;

        return is_string($kw) ? $kw : null;
    }

    private function siloOf(Site $site, ?string $spokeId): ?string
    {
        $spoke = $this->spoke($site, $spokeId);

        return $spoke === null ? null : (string) $spoke->silo;
    }

    private function spoke(Site $site, ?string $spokeId): ?Spoke
    {
        if ($spokeId === null) {
            return null;
        }

        return Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereKey($spokeId)->first();
    }
}
