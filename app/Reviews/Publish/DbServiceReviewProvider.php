<?php

namespace App\Reviews\Publish;

use App\Enums\ReviewStatus;
use App\Local\Proof\LocalReview;
use App\Local\Proof\NullServiceReviews;
use App\Local\Proof\ServiceReviewProvider;
use App\Models\Review;
use App\Models\Scopes\SiteScope;
use App\Models\Service;

/**
 * The real service-scoped reviews source for a spoke/hub page (Review Capture §8) — replaces
 * {@see NullServiceReviews}. Returns only PUBLISHED reviews tagged to this service (via the
 * review_service pivot), mapped to the shipped {@see LocalReview} DTO. Town is each review's own location city.
 * Empty ⇒ the section omits. Display only — NO rating/review structured data is emitted (§8).
 */
final class DbServiceReviewProvider implements ServiceReviewProvider
{
    /** @return list<LocalReview> */
    public function for(Service $service): array
    {
        return Review::query()->withoutGlobalScope(SiteScope::class)
            ->where('status', ReviewStatus::Published)
            ->whereHas('services', fn ($q) => $q->where('services.id', $service->id))
            ->with('location')
            ->orderByDesc('reviewed_at')
            ->get()
            ->map(fn (Review $review): LocalReview => new LocalReview(
                authorFirst: (string) $review->customer_name,
                rating: (int) $review->rating,
                text: (string) $review->body,
                town: $review->location?->cityState()['city'] ?? '',
                service: $service->name,
                date: $review->reviewed_at->format('Y-m-d'),
            ))
            ->all();
    }
}
