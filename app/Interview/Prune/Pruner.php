<?php

namespace App\Interview\Prune;

use App\Enums\PruneOutcome;
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
 * Phase 4 — the prune. The headless engine the owner-facing conversational surface will
 * sit on: it reads the volume-grounded candidate tree, applies owner decisions to each
 * spoke per the routing table ({@see PruneOutcome}), and confirms the blueprint only
 * once every non-fringe candidate has a decision (the hard gate — no fabricated
 * coverage). Fringe spokes are the Routing-layer handoff and are not decided here.
 */
final class Pruner
{
    public function plan(Site $site): PrunePlan
    {
        $rows = $this->spokes($site)
            ->map(fn (Spoke $s) => PruneRow::fromSpoke($s))
            ->all();

        return new PrunePlan($rows, $this->blueprint($site)?->confirmed_at !== null);
    }

    /**
     * Apply owner decisions keyed by spoke id OR name (name applies to all matches).
     *
     * @param  array<string, PruneOutcome|string>  $decisions
     * @return array{applied: int, unmatched: list<string>}
     */
    public function apply(Site $site, array $decisions): array
    {
        $spokes = $this->spokes($site);
        $byId = $spokes->keyBy('id');

        $applied = 0;
        $unmatched = [];

        DB::transaction(function () use ($decisions, $spokes, $byId, &$applied, &$unmatched): void {
            foreach ($decisions as $key => $decision) {
                $outcome = $decision instanceof PruneOutcome ? $decision : PruneOutcome::tryFrom((string) $decision);
                if ($outcome === null) {
                    $unmatched[] = (string) $key.' (bad outcome)';

                    continue;
                }

                $targets = $byId->has($key)
                    ? collect([$byId->get($key)])
                    : $spokes->where('name', $key)->values();

                if ($targets->isEmpty()) {
                    $unmatched[] = (string) $key;

                    continue;
                }

                foreach ($targets as $spoke) {
                    $this->route($spoke, $outcome);
                    $applied++;
                }
            }
        });

        return ['applied' => $applied, 'unmatched' => $unmatched];
    }

    /**
     * Confirm the core offerings in bulk: each undecided non-fringe `core` spoke is
     * offered (or kept as a content guide when its page type is content). The owner then
     * only decides the adjacent / connecting / content lean-ins.
     */
    public function acceptCore(Site $site): int
    {
        $applied = 0;

        DB::transaction(function () use ($site, &$applied): void {
            foreach ($this->spokes($site) as $spoke) {
                if ($spoke->tag !== SpokeTag::Core || $spoke->status !== SpokeStatus::Candidate) {
                    continue;
                }

                $this->route($spoke, $spoke->page_type === SpokePageType::Content ? PruneOutcome::Capture : PruneOutcome::Offer);
                $applied++;
            }
        });

        return $applied;
    }

    /**
     * Confirm the blueprint iff every non-fringe candidate has a decision (the hard
     * gate). Stamps confirmed_at; returns the gate result.
     *
     * @return array{confirmed: bool, pending: int}
     */
    public function confirm(Site $site): array
    {
        $plan = $this->plan($site);
        $pending = count($plan->pending());

        if ($pending > 0) {
            return ['confirmed' => false, 'pending' => $pending];
        }

        $blueprint = $this->blueprint($site);
        if ($blueprint !== null && $blueprint->confirmed_at === null) {
            $blueprint->forceFill(['confirmed_at' => now()])->save();
        }

        return ['confirmed' => true, 'pending' => 0];
    }

    private function route(Spoke $spoke, PruneOutcome $outcome): void
    {
        $attributes = ['status' => $outcome->status()];
        if ($outcome->pageType() !== null) {
            $attributes['page_type'] = $outcome->pageType();
        }

        $spoke->update($attributes);
    }

    /**
     * @return Collection<int, Spoke>
     */
    private function spokes(Site $site)
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
