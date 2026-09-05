<?php

namespace App\Locations;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Publishing\DeleteFromWordpress;
use App\Publishing\Links\LinkPlanCommitter;
use App\Publishing\PublishContentService;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single set/release path for a location PUBLISH-hold (location-integrity relay), and the read-side
 * that reports the honest state a held location can be in: **held with pages still live**.
 *
 * A hold blocks publishing NEW pages and defers IndexNow discovery ({@see Content::isPublishHeld()} +
 * the gates in {@see PublishContentService} / {@see LinkPlanCommitter}).
 * It does NOT unpublish what is already live — so a location held after it went live keeps its live pages
 * until an operator takes them down deliberately. {@see liveCount()} is what the surfaces show as
 * "held · N pages still live" so that state is visible, not a silent inconsistency.
 *
 * "Market" in the operator UI is this Location record; the advisory `markets.on_hold` is a different,
 * unrelated flag.
 */
class LocationPublishHold
{
    public function __construct(private readonly DeleteFromWordpress $takedown) {}

    /** Put a location on publish-hold — new pages won't publish and its URLs won't be announced. */
    public function hold(Location $location): Location
    {
        $location->forceFill(['publish_held' => true])->save();

        return $location;
    }

    /** Release a location — its reviewed pages can publish again. */
    public function release(Location $location): Location
    {
        $location->forceFill(['publish_held' => false])->save();

        return $location;
    }

    /**
     * The number of this location's pages that are STILL LIVE on WordPress (published with a wp_post_id) —
     * counting both its own hub page (`location_id`) and the town pages nested under it
     * (`parent_location_id`). Soft-deleted content is excluded by the model's default scope, so this is
     * live-only. For a held location, a non-zero count is the "held · N pages still live" signal.
     */
    public function liveCount(Location $location): int
    {
        return $this->livePages($location)->count();
    }

    /**
     * Take DOWN every live page of this location from WordPress — the deliberate, operator-invoked bulk
     * action (never automatic on hold). Each page routes through §2's {@see DeleteFromWordpress} (the same
     * mechanism as the per-page Live-board take-down), which removes the WP post and returns the row to a
     * republishable state. Returns the number taken down.
     */
    public function takeDownLivePages(Location $location): int
    {
        $taken = 0;
        foreach ($this->livePages($location)->get() as $page) {
            $this->takedown->delete($page);
            $taken++;
        }

        return $taken;
    }

    /**
     * This location's live pages (published + a wp_post_id) — its own hub (`location_id`) and the towns
     * nested under it (`parent_location_id`). Soft-deleted content is excluded by the default scope.
     *
     * @return Builder<Content>
     */
    private function livePages(Location $location): Builder
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->where(fn ($q) => $q->where('location_id', $location->id)->orWhere('parent_location_id', $location->id));
    }
}
