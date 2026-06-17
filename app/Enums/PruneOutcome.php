<?php

namespace App\Enums;

/**
 * The owner's decision for a candidate spoke during the Phase 4 prune — the routing
 * table that resolves a `candidate` into a confirmed status + page type. Nothing leaves
 * `candidate` without one of these.
 */
enum PruneOutcome: string
{
    /** Yes, I offer this — or I'd add it now. A service page, built + live. */
    case Offer = 'offer';

    /** I'd add it in future. A service page live day one to earn authority ahead of fulfilment. */
    case Future = 'future';

    /** No — capture the upstream searcher with a content-path guide that routes to the core service. */
    case Capture = 'capture';

    /** Out of lane / sibling brand — not built. */
    case Skip = 'skip';

    public function status(): SpokeStatus
    {
        return match ($this) {
            self::Offer => SpokeStatus::Offered,
            self::Future => SpokeStatus::Future,
            self::Capture => SpokeStatus::Content,
            self::Skip => SpokeStatus::Skipped,
        };
    }

    /**
     * The page type this routing implies — a capture decision converts the spoke to a
     * content-path guide; offer/future are service pages. Skip leaves the type as-is.
     */
    public function pageType(): ?SpokePageType
    {
        return match ($this) {
            self::Offer, self::Future => SpokePageType::Service,
            self::Capture => SpokePageType::Content,
            self::Skip => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Offer => 'Offer (service page)',
            self::Future => 'Future (service page, live day one)',
            self::Capture => 'Capture (content path)',
            self::Skip => 'Skip',
        };
    }
}
