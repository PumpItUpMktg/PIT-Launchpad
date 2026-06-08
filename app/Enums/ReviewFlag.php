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

    public function label(): string
    {
        return match ($this) {
            self::RenderFailed => 'Render failed',
            self::UnsupportedClaim => 'Unsupported claim',
            self::NearDuplicate => 'Near-duplicate',
            self::BrandSafety => 'Brand safety',
            self::OnDemand => 'On-demand',
            self::RelevanceBand => 'Borderline relevance',
        };
    }

    /** A flag that hard-blocks approval until resolved. */
    public function blocksApproval(): bool
    {
        return $this === self::RenderFailed;
    }
}
