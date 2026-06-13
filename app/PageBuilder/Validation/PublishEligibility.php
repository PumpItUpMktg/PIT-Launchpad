<?php

namespace App\PageBuilder\Validation;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\PageBuilder\Entities\EntityResolver;
use InvalidArgumentException;

/**
 * Publish-eligibility for a Content page: kit-schema validation + thin-page guard
 * + the location-market fail-closed rule, parking a failing page in review (never
 * live) with structured reasons.
 *
 * Two surfaces:
 *  - {@see evaluate()} runs the FULL gate (structure + media + entities) — the
 *    draft/review-time check, where the payload is complete.
 *  - {@see evaluateForPublish()} is the §2 publish-time gate: it keeps only the
 *    ENTITY-backing codes (proof / reviews / conversion / location), thin-page,
 *    and the location-market rule. Structural text + MEDIA are deliberately NOT
 *    re-checked here — media is produced DURING publish (the render step gates it,
 *    it is never in slot_payload), and slot structure is the draft-time contract.
 *    It builds its own context (resolved market + flags) so §2 runs it turnkey.
 */
class PublishEligibility
{
    public function __construct(
        private readonly KitValidator $validator,
        private readonly ThinPageGuard $guard,
        private readonly EntityResolver $entities,
    ) {}

    public function evaluate(Content $content, ValidationContext $context): ValidationResult
    {
        $kit = $content->wireframeKit;

        if ($kit === null) {
            throw new InvalidArgumentException("Content [{$content->id}] has no wireframe kit to validate against.");
        }

        $schema = $kit->schema();
        $result = $this->validator->validate($schema, $content->slot_payload ?? [], $context);

        $thin = $this->guard->evaluate($schema, $context);
        if (! $thin->earned) {
            $result = $result->merge(ValidationResult::fail([
                new ValidationFailure(null, ValidationCode::ThinPage, 'Page has no entity-backed proof yet — not earned.'),
            ]));
        }

        // Fail closed: a location page that doesn't know its market can't resolve
        // the market-scoped reviews gate and must never publish.
        if ($this->isLocationPage($content) && $content->market_id === null) {
            $result = $result->merge(ValidationResult::fail([
                new ValidationFailure(null, ValidationCode::LocationMarketMissing, 'Location page has no market assigned; market-scoped reviews cannot be resolved.'),
            ]));
        }

        if ($result->failed()) {
            $content->update(['status' => ContentStatus::InReview]);
        }

        return $result;
    }

    /**
     * The §2 publish-time review gate. SERVICE pages publish WITHOUT a review gate
     * (their testimonial slot is conditional in-schema). Only LOCATION pages are
     * gated: they must know their market (fail closed → location.market_missing)
     * and have ≥1 market-scoped or site-wide substantiated review. A failing page
     * is parked in review (never live). The broader structural/media/proof checks
     * are NOT re-run here — those are the draft/render-time contract; media is
     * produced during publish and is never in slot_payload.
     */
    public function evaluateForPublish(Content $content): ValidationResult
    {
        if (! $this->isLocationPage($content)) {
            return ValidationResult::fail([]);
        }

        $failures = [];

        if ($content->market_id === null) {
            $failures[] = new ValidationFailure(null, ValidationCode::LocationMarketMissing, 'Location page has no market assigned; it cannot publish.');
        } elseif (($this->entities->count('reviews.market', $this->contextFor($content)) ?? 0) < 1) {
            $failures[] = new ValidationFailure('local_testimonials', ValidationCode::EntityBelowMinimum, 'Location page requires at least one market-scoped review before publishing.');
        }

        $result = ValidationResult::fail($failures);

        if ($result->failed()) {
            $content->update(['status' => ContentStatus::InReview]);
        }

        return $result;
    }

    /**
     * Build the publish-time context: the page's market (from market_id), and the
     * flags the kit conditions read:
     *  - `has_reviews`  — ≥1 substantiated review (service-page testimonial slot).
     *  - `has_substantiated_proof` — ≥2 substantiated proof items (proof_strip omits
     *    below that, like testimonial, rather than blocking — never fabricate stats).
     *  - `has_location` — ≥1 location (contact_block resolves NAP from the primary
     *    location; with none, the block omits gracefully).
     *  - `is_storefront` — the site's location is walk-in (nap_block/map conditions).
     */
    public function contextFor(Content $content): ValidationContext
    {
        $market = $content->market_id !== null
            ? Market::withoutGlobalScope(SiteScope::class)->find($content->market_id)
            : null;

        $base = new ValidationContext($content);
        $hasReviews = ($this->entities->count('reviews.site', $base) ?? 0) >= 1;
        $hasSubstantiatedProof = ($this->entities->count('proof.substantiated', $base) ?? 0) >= 2;
        $hasLocation = ($this->entities->count('location.nap', $base) ?? 0) >= 1;
        $isStorefront = (bool) Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->value('is_storefront');

        return new ValidationContext(
            content: $content,
            market: $market,
            flags: [
                'has_reviews' => $hasReviews,
                'has_substantiated_proof' => $hasSubstantiatedProof,
                'has_location' => $hasLocation,
                'is_storefront' => $isStorefront,
            ],
        );
    }

    private function isLocationPage(Content $content): bool
    {
        return $content->kind === ContentKind::Page && $content->page_type === PageType::Location;
    }
}
