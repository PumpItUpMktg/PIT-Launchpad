<?php

namespace App\Jobs;

use App\Integrations\Census\Geocoder;
use App\Models\Review;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Geocode one review's OWN address to a point, off the request path (Review Capture — town/geo fix), so the
 * location-page reviews section can apply its Haversine radius fallback. Never runs in a web/Livewire request:
 * the import (inside ImportReviews) and first-party submission both DISPATCH this instead of geocoding inline.
 * Idempotent — skips a review that already has a point, and no-ops when there is nothing to geocode. For a
 * first-party review that captured only a raw service_address, it also best-effort derives "Town, ST" from the
 * matched address so the review can display its own town.
 */
class GeocodeReview implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct(public readonly string $reviewId) {}

    public function handle(Geocoder $geocoder): void
    {
        $review = Review::withoutGlobalScope(SiteScope::class)->find($this->reviewId);
        if ($review === null || ($review->lat !== null && $review->lng !== null)) {
            return; // gone, or already geocoded (idempotent)
        }

        $address = $this->address($review);
        if ($address === '') {
            return;
        }

        $result = $geocoder->geocode($address);
        if ($result === null) {
            return; // unresolved — stays geo-less; the radius fallback simply won't match it
        }

        $updates = ['lat' => $result->lat, 'lng' => $result->lng];

        // A first-party review that captured only a raw service_address has no structured town — derive a
        // best-effort "Town, ST" from the matched address so it still displays its own town.
        if (($review->town === null || $review->town === '')
            && ($parsed = $this->parseTownState($result->matchedAddress)) !== null) {
            [$updates['town'], $updates['state']] = $parsed;
        }

        $review->forceFill($updates)->save();
    }

    /** The best address to geocode: structured town/state/postal when present, else the raw service_address. */
    private function address(Review $review): string
    {
        $town = trim((string) $review->town);
        if ($town !== '') {
            return trim($town.', '.trim((string) $review->state).' '.trim((string) $review->postal_code));
        }

        return trim((string) $review->service_address);
    }

    /**
     * Pull "Town, ST" out of a Google-formatted address ("123 Main St, Belleville, NJ 07109, USA").
     *
     * @return array{0: string, 1: string}|null
     */
    private function parseTownState(string $formatted): ?array
    {
        if (preg_match('/,\s*([^,]+?),\s*([A-Z]{2})\s+\d{5}/', $formatted, $m) === 1) {
            return [trim($m[1]), $m[2]];
        }

        return null;
    }
}
