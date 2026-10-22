<?php

namespace App\Enums;

/** Where a link prospect stands in the hand-worked outreach (§ Links). */
enum LinkProspectStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Replied = 'replied';
    case Acquired = 'acquired';
    case Declined = 'declined';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Replied => 'Replied',
            self::Acquired => 'Won — link acquired',
            self::Declined => 'Declined',
            self::Dismissed => 'Dismissed',
        };
    }

    /** Still worth a person's time: not won, not declined, not dismissed. */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::Replied], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_map(fn (self $s): string => $s->value, array_filter(self::cases(), fn (self $s): bool => $s->isOpen()));
    }
}
