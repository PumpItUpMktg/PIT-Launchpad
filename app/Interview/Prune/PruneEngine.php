<?php

namespace App\Interview\Prune;

use App\Enums\PruneOutcome;
use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 — the prune. The headless engine the owner-facing conversational surface
 * (PR-B) sits on: it takes the volume-grounded candidate tree and applies owner
 * decisions — per-spoke (confirm / route / re-tag / granularity) AND silo-level
 * (fold / rename / confirm structure) — then confirms the blueprint only once every
 * non-fringe candidate has a decision (the hard gate — the default for an un-reviewed
 * candidate is *not built*, no fabricated coverage). Fringe spokes are the Routing-layer
 * handoff and are excluded from the gate.
 *
 * Re-tagging is first-class: the owner can promote a spoke (e.g. a mis-tagged
 * `connecting` they actually offer → `core`), self-correcting the expander's tag misses
 * at confirm time, independent of any upstream prompt fix.
 */
final class PruneEngine
{
    public function plan(Site $site): PrunePlan
    {
        $rows = $this->spokes($site)
            ->map(fn (Spoke $s) => PruneRow::fromSpoke($s))
            ->all();

        return new PrunePlan($rows, $this->blueprint($site)?->confirmed_at !== null);
    }

    /**
     * Apply a full decision-set in a single transaction. Order is deterministic: spoke
     * decisions first (keyed by stable name/id, so a later silo rename can't unmatch
     * them), then silo renames → folds → confirms.
     *
     * @param  array{spokes?: array<string, mixed>, silos?: array<string, mixed>}  $set
     * @return array{spokes_applied: int, spokes_unmatched: list<string>, silos_renamed: int, silos_folded: int, silos_confirmed: int}
     */
    public function applyDecisionSet(Site $site, array $set): array
    {
        $spokeResult = ['applied' => 0, 'unmatched' => []];
        $renamed = 0;
        $folded = 0;
        $confirmed = 0;

        DB::transaction(function () use ($site, $set, &$spokeResult, &$renamed, &$folded, &$confirmed): void {
            if (is_array($set['spokes'] ?? null)) {
                $spokeResult = $this->applySpokes($site, $set['spokes']);
            }

            $silos = is_array($set['silos'] ?? null) ? $set['silos'] : [];

            foreach ($silos as $silo => $decision) {
                if (is_array($decision) && (string) ($decision['rename'] ?? '') !== '') {
                    $renamed += $this->renameSilo($site, (string) $silo, (string) $decision['rename']) > 0 ? 1 : 0;
                }
            }
            foreach ($silos as $silo => $decision) {
                if (is_array($decision) && (string) ($decision['fold_into'] ?? '') !== '') {
                    $folded += $this->foldSilo($site, (string) $silo, (string) $decision['fold_into']) > 0 ? 1 : 0;
                }
            }
            foreach ($silos as $silo => $decision) {
                if (is_array($decision) && ($decision['confirm'] ?? false)) {
                    $this->confirmSilo($site, (string) $silo);
                    $confirmed++;
                }
            }
        });

        return [
            'spokes_applied' => $spokeResult['applied'],
            'spokes_unmatched' => $spokeResult['unmatched'],
            'silos_renamed' => $renamed,
            'silos_folded' => $folded,
            'silos_confirmed' => $confirmed,
        ];
    }

    /**
     * Apply per-spoke decisions keyed by spoke id OR name (name applies to all matches).
     * Each decision is `{outcome, tag?, granularity?}` — re-tag + granularity override are
     * applied alongside the routing transition. A bare string is treated as the outcome.
     *
     * @param  array<string, mixed>  $decisions
     * @return array{applied: int, unmatched: list<string>}
     */
    public function applySpokes(Site $site, array $decisions): array
    {
        $spokes = $this->spokes($site);
        $byId = $spokes->keyBy('id');

        $applied = 0;
        $unmatched = [];

        foreach ($decisions as $key => $raw) {
            $decision = is_array($raw) ? $raw : ['outcome' => $raw];

            $outcome = ($decision['outcome'] ?? null) instanceof PruneOutcome
                ? $decision['outcome']
                : PruneOutcome::tryFrom((string) ($decision['outcome'] ?? ''));
            if ($outcome === null) {
                $unmatched[] = (string) $key.' (bad outcome)';

                continue;
            }

            $targets = $byId->has($key) ? collect([$byId->get($key)]) : $spokes->where('name', $key)->values();
            if ($targets->isEmpty()) {
                $unmatched[] = (string) $key;

                continue;
            }

            $tag = isset($decision['tag']) ? SpokeTag::tryFrom((string) $decision['tag']) : null;
            $granularity = isset($decision['granularity']) ? SpokeGranularity::tryFrom((string) $decision['granularity']) : null;

            foreach ($targets as $spoke) {
                $this->route($spoke, $outcome, $tag, $granularity);
                $applied++;
            }
        }

        return ['applied' => $applied, 'unmatched' => $unmatched];
    }

    /**
     * Confirm the core offerings in bulk: each undecided non-fringe `core` spoke is
     * offered (or kept as a content guide when its page type is content).
     */
    public function acceptCore(Site $site): int
    {
        return $this->offerCore($this->spokes($site));
    }

    /** Batch-confirm the core spokes of a single silo (the silo-level "confirm"). */
    public function confirmSilo(Site $site, string $silo): int
    {
        return $this->offerCore($this->spokes($site)->where('silo', $silo)->values());
    }

    /**
     * Fold one silo into another: its spokes move under the target pillar and its own
     * pillar becomes a regular member there. Collapses thin silos (e.g. sewage / grinder
     * under a single pumps pillar) when volume shows them too light to stand alone.
     */
    public function foldSilo(Site $site, string $from, string $into): int
    {
        $moved = 0;
        foreach ($this->spokes($site)->where('silo', $from) as $spoke) {
            $spoke->update(['silo' => $into, 'is_pillar' => false]);
            $moved++;
        }

        return $moved;
    }

    /** Rename a silo (its grouping + its pillar spoke's name). */
    public function renameSilo(Site $site, string $from, string $to): int
    {
        $changed = 0;
        foreach ($this->spokes($site)->where('silo', $from) as $spoke) {
            $attributes = ['silo' => $to];
            if ($spoke->is_pillar && $spoke->name === $from) {
                $attributes['name'] = $to;
            }
            $spoke->update($attributes);
            $changed++;
        }

        return $changed;
    }

    /**
     * Commit the prune (the UI's Finalize): apply the assembled decision-set, then
     * explicitly skip every still-undecided non-fringe candidate — the hard gate's
     * "un-reviewed = not built" made concrete (pending → dropped) — confirm the
     * blueprint, and clear the draft.
     *
     * @param  array{spokes?: array<string, mixed>, silos?: array<string, mixed>}  $set
     * @return array{built: int, skipped: int, confirmed: bool}
     */
    public function finalize(Site $site, array $set): array
    {
        return DB::transaction(function () use ($site, $set): array {
            $this->applyDecisionSet($site, $set);

            foreach ($this->spokes($site) as $spoke) {
                if ($spoke->tag !== SpokeTag::Fringe && $spoke->status === SpokeStatus::Candidate) {
                    $this->route($spoke, PruneOutcome::Skip); // pending → dropped
                }
            }

            $confirmed = $this->confirm($site)['confirmed'];

            $blueprint = $this->blueprint($site);
            if ($blueprint !== null) {
                $blueprint->forceFill(['prune_draft' => null])->save();
            }

            $rows = $this->plan($site)->decidable();

            return [
                'built' => count(array_filter($rows, fn (PruneRow $r) => in_array($r->status, [SpokeStatus::Offered, SpokeStatus::Future, SpokeStatus::Content], true))),
                'skipped' => count(array_filter($rows, fn (PruneRow $r) => $r->status === SpokeStatus::Skipped)),
                'confirmed' => $confirmed,
            ];
        });
    }

    /**
     * Persist the operator's in-progress decision-set (draft/resume).
     *
     * @param  array<string, mixed>  $draft
     */
    public function saveDraft(Site $site, array $draft): void
    {
        $this->blueprint($site)?->forceFill(['prune_draft' => $draft])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function loadDraft(Site $site): array
    {
        $draft = $this->blueprint($site)?->prune_draft;

        return is_array($draft) ? $draft : [];
    }

    /**
     * Confirm the blueprint iff every non-fringe candidate has a decision (the hard
     * gate). Stamps confirmed_at; returns the gate result.
     *
     * @return array{confirmed: bool, pending: int}
     */
    public function confirm(Site $site): array
    {
        $pending = count($this->plan($site)->pending());
        if ($pending > 0) {
            return ['confirmed' => false, 'pending' => $pending];
        }

        $blueprint = $this->blueprint($site);
        if ($blueprint !== null && $blueprint->confirmed_at === null) {
            $blueprint->forceFill(['confirmed_at' => now()])->save();
        }

        return ['confirmed' => true, 'pending' => 0];
    }

    /**
     * @param  Collection<int, Spoke>  $spokes
     */
    private function offerCore(Collection $spokes): int
    {
        $applied = 0;
        foreach ($spokes as $spoke) {
            if ($spoke->tag !== SpokeTag::Core || $spoke->status !== SpokeStatus::Candidate) {
                continue;
            }
            $this->route($spoke, $spoke->page_type === SpokePageType::Content ? PruneOutcome::Capture : PruneOutcome::Offer);
            $applied++;
        }

        return $applied;
    }

    private function route(Spoke $spoke, PruneOutcome $outcome, ?SpokeTag $tag = null, ?SpokeGranularity $granularity = null): void
    {
        $attributes = ['status' => $outcome->status()];
        if ($outcome->pageType() !== null) {
            $attributes['page_type'] = $outcome->pageType();
        }
        if ($tag !== null) {
            $attributes['tag'] = $tag;
        }
        if ($granularity !== null) {
            $attributes['granularity'] = $granularity;
        }

        $spoke->update($attributes);
    }

    /**
     * @return Collection<int, Spoke>
     */
    private function spokes(Site $site): Collection
    {
        return Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('silo')
            ->orderByDesc('is_pillar')
            ->orderBy('name')
            ->get();
    }

    private function blueprint(Site $site): ?SiloBlueprint
    {
        return SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->first();
    }
}
