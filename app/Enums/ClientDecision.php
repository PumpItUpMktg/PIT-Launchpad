<?php

namespace App\Enums;

/**
 * The client's call on a link prospect (§ Links). Outreach goes only to prospects the client marked
 * Interested — the client reviews and decides; the platform never blasts the list.
 */
enum ClientDecision: string
{
    case Interested = 'interested';
    case Pass = 'pass';

    public function label(): string
    {
        return match ($this) {
            self::Interested => 'Interested',
            self::Pass => 'Pass',
        };
    }
}
