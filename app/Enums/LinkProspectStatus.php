<?php

namespace App\Enums;

/** Where a link prospect stands in the hand-worked outreach (§ Links). */
enum LinkProspectStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Acquired = 'acquired';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Acquired => 'Link acquired',
            self::Dismissed => 'Dismissed',
        };
    }
}
