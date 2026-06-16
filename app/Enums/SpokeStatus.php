<?php

namespace App\Enums;

/**
 * The owner-confirmed routing of a spoke after the prune (Phase 4). Nothing becomes
 * a page without an explicit confirm + a chosen path — there is no "candidate"
 * status that silently ships.
 */
enum SpokeStatus: string
{
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
            self::Offered => 'Offered (service page)',
            self::Future => 'Future (service page, live day one)',
            self::Content => 'Content path (capture)',
            self::Skipped => 'Skipped',
        };
    }
}
