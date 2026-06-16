<?php

namespace App\Enums;

/**
 * The lifecycle/routing of a spoke. Phase 2's expansion emits `candidate` (proposed,
 * pre-prune — never ships as-is); Phase 4's prune resolves each candidate to an
 * owner-confirmed routing (offered / future / content / skipped). Nothing becomes a
 * page without that explicit confirm + chosen path.
 */
enum SpokeStatus: string
{
    /** Proposed by the Phase 2 expansion — awaiting the owner prune. Ships nothing on its own. */
    case Candidate = 'candidate';

    /** Yes, I offer this — or I'd add it now. Service page, built + live. */
    case Offered = 'offered';

    /** I'd add it in future. Service page live day one to earn authority ahead of fulfilment. */
    case Future = 'future';

    /** No — capture the upstream searcher with a content-path guide that routes to the core service. */
    case Content = 'content';

    /** Out of lane / sibling brand — not built here. */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Candidate => 'Candidate (proposed)',
            self::Offered => 'Offered (service page)',
            self::Future => 'Future (service page, live day one)',
            self::Content => 'Content path (capture)',
            self::Skipped => 'Skipped',
        };
    }
}
