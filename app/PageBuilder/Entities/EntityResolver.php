<?php

namespace App\PageBuilder\Entities;

use App\Models\ConversionConfig;
use App\Models\Location;
use App\Models\Offer;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\PageBuilder\Validation\ValidationContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves the count of structured entity content backing a slot, querying §1
 * models. This is what makes accuracy structural: an entity/grounded slot only
 * validates if its backing set actually resolves to the required minimum.
 *
 * Queries drop the SiteScope and filter site_id explicitly so resolution is
 * deterministic regardless of the ambient CurrentSite, while keeping other
 * global scopes (e.g. soft deletes) intact.
 */
class EntityResolver
{
    /**
     * Count the entity set for the given key, or null if the key is unknown.
     */
    public function count(string $entityKey, ValidationContext $context): ?int
    {
        $siteId = $context->siteId();

        return match ($entityKey) {
            'proof.substantiated' => ProofItem::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)
                ->where('is_substantiated', true)
                ->count(),

            'reviews.market' => $this->reviewsForMarket($context),

            // Site-level substantiated reviews (any market or none) — the
            // service-page testimonial conditional gates on this (has_reviews).
            'reviews.site' => $this->substantiatedReviews($siteId)->count(),

            'services.site' => Service::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)
                ->count(),

            'offers.site' => Offer::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)
                ->count(),

            'location.nap' => Location::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)
                ->count(),

            'conversion.primary_action' => $this->primaryActionCount($siteId),

            'market.profile' => $context->market !== null ? 1 : 0,

            // No Job Capture model exists until that section ships; resolves to
            // zero so the thin-page guard relies on market-tagged reviews today.
            'jobcapture.radius' => 0,

            default => null,
        };
    }

    /**
     * Substantiated reviews backing a location page's market gate. A review counts
     * when it is attached to the page's market (content.market_id) OR is site-wide
     * — a review attached to no market satisfies any market's gate (confirmed
     * product decision: site-wide reviews count toward every market). The fail-
     * closed "location page with no market_id never publishes" is enforced upstream
     * (PublishEligibility), not here.
     */
    private function reviewsForMarket(ValidationContext $context): int
    {
        $marketId = $context->content->market_id;

        return $this->substantiatedReviews($context->siteId())
            ->where(function ($query) use ($marketId) {
                $query->whereDoesntHave('markets'); // site-wide
                if ($marketId !== null) {
                    $query->orWhereHas('markets', fn ($m) => $m->whereKey($marketId));
                }
            })
            ->count();
    }

    /**
     * Base query for a site's substantiated review-type proof (testimonials +
     * review aggregates), SiteScope dropped + site filtered explicitly.
     *
     * @return Builder<ProofItem>
     */
    private function substantiatedReviews(string $siteId)
    {
        return ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('is_substantiated', true)
            ->whereIn('type', ['testimonial', 'review_aggregate']);
    }

    private function primaryActionCount(string $siteId): int
    {
        $config = ConversionConfig::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->first();

        return $config === null ? 0 : count($config->primary_actions ?? []);
    }
}
