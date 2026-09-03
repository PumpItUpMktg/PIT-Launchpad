<?php

namespace App\Operate;

use App\Enums\LinkPlanItemStatus;
use App\Enums\LinkPlanStatus;
use App\Models\LinkPlan;
use App\Models\LinkPlanItem;
use App\Publishing\Links\LinkPlanCommitter;

/**
 * The operator's gate over a {@see LinkPlan}: approve / reject proposed items, then apply. UI-agnostic and
 * testable; the Filament surface is thin over this. Nothing writes a live page until {@see apply()} runs on
 * an operator's action — approving an item only marks intent.
 */
class LinkPlanActions
{
    public function __construct(private readonly LinkPlanCommitter $committer) {}

    public function approve(LinkPlanItem $item): void
    {
        $this->setItem($item, LinkPlanItemStatus::Approved);
    }

    public function reject(LinkPlanItem $item): void
    {
        $this->setItem($item, LinkPlanItemStatus::Rejected);
    }

    /** Approve every still-proposed item in the plan. */
    public function approveAll(LinkPlan $plan): int
    {
        return $plan->items()
            ->where('status', LinkPlanItemStatus::Proposed->value)
            ->update(['status' => LinkPlanItemStatus::Approved->value]);
    }

    /**
     * Commit the plan — write the approved links, re-publish sources, guard orphans, submit IndexNow. No-op
     * (returns the empty shape) if the plan is already applied.
     *
     * @return array{applied: int, republished: int, submitted: list<string>, orphaned: list<string>}
     */
    public function apply(LinkPlan $plan, ?string $actorId = null): array
    {
        if ($plan->status === LinkPlanStatus::Applied) {
            return ['applied' => 0, 'republished' => 0, 'submitted' => [], 'orphaned' => []];
        }

        return $this->committer->apply($plan, $actorId);
    }

    private function setItem(LinkPlanItem $item, LinkPlanItemStatus $status): void
    {
        // Only a still-proposed item can change; an applied item is settled.
        if ($item->status === LinkPlanItemStatus::Proposed) {
            $item->forceFill(['status' => $status])->save();
        }
    }
}
