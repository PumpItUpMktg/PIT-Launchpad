<?php

namespace App\Reviews\Approval;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\ReviewStatus;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Review;
use App\Models\Scopes\SiteScope;

/**
 * The testable orchestration behind the operator review queue (Review Capture §9) — the Filament resource is a
 * thin surface over this. Approving publishes: it flips the review to `published` and re-enqueues §2's
 * idempotent `PublishContent` for the review's location page AND its tagged service pages, so their gated
 * reviews section re-renders with the new review. A `needs_location` review can't be approved until it's
 * reassigned (there's no page to publish it to). Nothing here touches a protected file.
 */
final class ReviewApprovalActions
{
    /** Only a review with a resolved location can be approved (it needs a page to publish into). */
    public function canApprove(Review $review): bool
    {
        return ! $review->needs_location && $review->location_id !== null;
    }

    public function approve(Review $review, ?string $actorId = null): bool
    {
        if (! $this->canApprove($review)) {
            return false;
        }

        $review->forceFill([
            'status' => ReviewStatus::Published,
            'approved_at' => now(),
            'approved_by' => $actorId,
            'published_at' => now(),
        ])->save();

        $this->publishAffectedPages($review, $actorId);

        return true;
    }

    public function reject(Review $review): void
    {
        $review->forceFill(['status' => ReviewStatus::Rejected])->save();
    }

    /** Pull an approved review back out of the live section (and re-render the pages without it). */
    public function unpublish(Review $review, ?string $actorId = null): void
    {
        $review->forceFill(['status' => ReviewStatus::Approved, 'published_at' => null])->save();
        $this->publishAffectedPages($review, $actorId);
    }

    public function saveBody(Review $review, string $body): void
    {
        $review->forceFill(['body' => trim($body)])->save();
    }

    /** Assign (or reassign) the owning Location — the required step to clear a needs_location review. */
    public function reassignLocation(Review $review, string $locationId): void
    {
        $review->forceFill(['location_id' => $locationId, 'needs_location' => false])->save();
    }

    /** @param list<string> $serviceIds */
    public function setServices(Review $review, array $serviceIds): void
    {
        $review->services()->sync(array_slice($serviceIds, 0, Review::MAX_SERVICES));
    }

    /** Re-enqueue publish for the location + service pages this review appears in. */
    public function publishAffectedPages(Review $review, ?string $actorId = null): void
    {
        if ($review->location_id === null) {
            return;
        }
        $siteId = (string) $review->site_id;

        $pageIds = Content::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('kind', ContentKind::Page)
            ->where('page_type', PageType::Location)
            ->where('location_id', $review->location_id)
            ->pluck('id');

        $serviceIds = $review->services()->pluck('services.id')->all();
        if ($serviceIds !== []) {
            $servicePages = Content::query()->withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)
                ->where('kind', ContentKind::Page)
                ->where('page_type', PageType::Service)
                ->whereIn('primary_service_id', $serviceIds)
                ->pluck('id');
            $pageIds = $pageIds->merge($servicePages);
        }

        foreach ($pageIds->unique() as $id) {
            PublishContent::dispatch((string) $id, $actorId);
        }
    }
}
