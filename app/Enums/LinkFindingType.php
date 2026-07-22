<?php

namespace App\Enums;

use App\Publishing\Links\InternalLinkAuditor;

/**
 * A finding from the internal-link audit ({@see InternalLinkAuditor}).
 */
enum LinkFindingType: string
{
    /** A published page nothing else links TO — it needs an inbound link to be discoverable. */
    case Orphan = 'orphan';

    /** A published page that links to nothing — a dead end that passes no authority onward. */
    case DeadEnd = 'dead_end';

    /** A page whose body already NAMES another page's term but doesn't link it — a link to add. */
    case Opportunity = 'opportunity';

    public function label(): string
    {
        return match ($this) {
            self::Orphan => 'No inbound links',
            self::DeadEnd => 'No outbound links',
            self::Opportunity => 'New link available',
        };
    }
}
