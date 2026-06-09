<?php

namespace App\ContentEngine\Feeds;

use App\Enums\FeedStatus;
use App\Models\Source;
use Illuminate\Support\Carbon;

/**
 * Derives a feed's surfaced status from the enabled toggle + fetch telemetry —
 * the "0 items for N days / repeated failure" signal the News Sources panel
 * shows. Advisory only: an unhealthy feed is flagged, never auto-disabled.
 */
class FeedHealth
{
    public function __construct(private readonly int $unhealthyAfterDays = 21) {}

    public function status(Source $feed): FeedStatus
    {
        if (! $feed->enabled) {
            return FeedStatus::Paused;
        }

        // Newly added and not yet polled — give it the benefit of the doubt.
        if ($feed->last_fetched_at === null) {
            return FeedStatus::Active;
        }

        // The most recent fetch failed (consent page, HTTP error, unreachable).
        if (filled($feed->last_error)) {
            return FeedStatus::Unhealthy;
        }

        // Reachable but silent: no item in the freshness window.
        $cutoff = Carbon::now()->subDays($this->unhealthyAfterDays);
        if ($feed->last_item_at === null || $feed->last_item_at->lt($cutoff)) {
            return FeedStatus::Unhealthy;
        }

        return FeedStatus::Active;
    }
}
