<?php

namespace App\Reviews\Publish;

use App\Enums\ReviewStatus;
use App\Local\Proof\LocalReview;
use App\Local\Proof\LocalReviewProvider;
use App\Local\Proof\NullLocalReviews;
use App\Models\Location;
use App\Models\Review;
use App\Models\Scopes\SiteScope;

/**
 * The real local-reviews source for a location page (Review Capture §8) — replaces {@see NullLocalReviews}.
 * Returns only PUBLISHED reviews tagged to this location, mapped to the shipped {@see LocalReview} DTO the block
 * composer renders. The reviews are already location-tagged at capture, so no town/radius fallback is needed;
 * town is the location's own city. Empty ⇒ the gated section omits entirely. Cross-tenant read (the location
 * carries its own tenant); display only — NO rating/review structured data is emitted (§8).
 */
final class DbLocalReviewProvider implements LocalReviewProvider
{
    /** @return list<LocalReview> */
    public function for(Location $location): array
    {
        $town = $location->cityState()['city'];

        return Review::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)
            ->where('status', ReviewStatus::Published)
            ->with('services')
            ->orderByDesc('reviewed_at')
            ->get()
            ->map(fn (Review $review): LocalReview => new LocalReview(
                authorFirst: (string) $review->customer_name,
                rating: (int) $review->rating,
                text: (string) $review->body,
                town: $town,
                service: $review->services->first()?->name,
                date: $review->reviewed_at->format('Y-m-d'),
            ))
            ->all();
    }
}
