<?php

namespace App\Reviews\Publish;

use App\Enums\ReviewStatus;
use App\Local\Proof\LocalReview;
use App\Local\Proof\LocalReviewProvider;
use App\Local\Proof\NullLocalReviews;
use App\Locations\Distance;
use App\Models\Location;
use App\Models\Review;
use App\Models\Scopes\SiteScope;

/**
 * The real local-reviews source for a location page (Review Capture §8) — replaces {@see NullLocalReviews}.
 * Implements the contract's filtering: a PUBLISHED review belongs on this location's page when its OWN town is
 * one the location serves (served-town membership, primary), else when its geocoded point is within
 * {@see RADIUS_MILES} of the location (Haversine radius fallback). Each review displays its own town — never
 * the location's city (the fix for the "Belleville customer renders as Clifton" misattribution). A review with
 * no town and no point yet (pre-backfill) simply doesn't match. Empty ⇒ the gated section omits entirely.
 * Cross-tenant read (the location carries its own tenant); display only — NO rating/review structured data (§8).
 */
final class DbLocalReviewProvider implements LocalReviewProvider
{
    /** The "near enough" radius (miles) for a review whose town the location does not explicitly serve. */
    private const RADIUS_MILES = 20.0;

    /** @return list<LocalReview> */
    public function for(Location $location): array
    {
        $servedKeys = $this->servedTownKeys($location);
        $centerLat = $location->lat !== null ? (float) $location->lat : null;
        $centerLng = $location->lng !== null ? (float) $location->lng : null;

        return Review::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('status', ReviewStatus::Published)
            ->with('services')
            ->orderByDesc('reviewed_at')
            ->get()
            ->filter(fn (Review $review): bool => $this->belongs($review, $servedKeys, $centerLat, $centerLng))
            ->map(fn (Review $review): LocalReview => new LocalReview(
                authorFirst: (string) $review->customer_name,
                rating: (int) $review->rating,
                text: (string) $review->body,
                town: (string) $review->town,
                service: $review->services->first()?->name,
                date: $review->reviewed_at->format('Y-m-d'),
            ))
            ->values()
            ->all();
    }

    /** @param  array<string, true>  $servedKeys */
    private function belongs(Review $review, array $servedKeys, ?float $centerLat, ?float $centerLng): bool
    {
        // Primary: the review's own town is one this location serves.
        $key = $this->townKey((string) $review->town);
        if ($key !== '' && isset($servedKeys[$key])) {
            return true;
        }

        // Fallback: the review's geocoded point is within the radius of the location.
        if ($review->lat === null || $review->lng === null || $centerLat === null || $centerLng === null) {
            return false;
        }

        return Distance::miles((float) $review->lat, (float) $review->lng, $centerLat, $centerLng) <= self::RADIUS_MILES;
    }

    /**
     * The town keys this location serves: its own city plus every served town, normalized the same way a
     * review's town is (strip trailing ", ST", lowercase) so membership matches how town pages are keyed.
     *
     * @return array<string, true>
     */
    private function servedTownKeys(Location $location): array
    {
        $names = [$location->cityState()['city']];
        foreach ($location->served_towns ?? [] as $town) {
            $names[] = (string) ($town['name'] ?? '');
        }

        $keys = [];
        foreach ($names as $name) {
            $key = $this->townKey($name);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /** The same key used across the app: strip a trailing ", ST" then lowercase the bare town name. */
    private function townKey(string $town): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}$/', '', trim($town))));
    }
}
