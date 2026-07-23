<?php

namespace App\Enums;

/**
 * The flagged-lane alert categories surfaced in the §6c review queue. Each is
 * derived from state upstream stages persisted — never a silent auto-reject; the
 * operator decides. Informational on the row, filterable to the top.
 */
enum ReviewFlag: string
{
    /** §2 — a required image render terminally failed; blocks publish. */
    case RenderFailed = 'render_failed';
    /** §6b — a business claim that didn't trace to the Claims set. */
    case UnsupportedClaim = 'unsupported_claim';
    /** §6a — flagged near-duplicate of an existing page. */
    case NearDuplicate = 'near_duplicate';
    /** §6a — tragedy-exploitation / fear-mongering brand-safety flag. */
    case BrandSafety = 'brand_safety';
    /** §6b — operator/gap/backfill/seasonal-triggered (non-reactive) item. */
    case OnDemand = 'on_demand';
    /** §6a — borderline relevance promoted for an operator decision. */
    case RelevanceBand = 'relevance_band';
    /** §1 — a service spoke whose Service record has no enrichment (symptoms/scope/process/cost), so
     * its mid-page sections omit and the page reads thin. Enrich the service, then regenerate. */
    case NeedsEnrichment = 'needs_enrichment';
    /** §2/§4 — a hub page that is ungenerated (empty drafted body) or has no materialized spokes in its
     * silo (empty services grid), so it renders thin and can't route to its children. Generate it. */
    case NeedsGeneration = 'needs_generation';

    public function label(): string
    {
        return match ($this) {
            self::RenderFailed => 'Render failed',
            self::UnsupportedClaim => 'Unsupported claim',
            self::NearDuplicate => 'Near-duplicate',
            self::BrandSafety => 'Brand safety',
            self::OnDemand => 'On-demand',
            self::RelevanceBand => 'Borderline relevance',
            self::NeedsEnrichment => 'Needs enrichment',
            self::NeedsGeneration => 'Needs generation',
        };
    }

    /** A flag that hard-blocks approval until resolved. */
    public function blocksApproval(): bool
    {
        return $this === self::RenderFailed;
    }
}
