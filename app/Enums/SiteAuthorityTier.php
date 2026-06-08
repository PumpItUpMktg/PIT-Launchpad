<?php

namespace App\Enums;

/**
 * A coarse site-authority tier inferred from domain/position metrics. Gates
 * organic beatability and self-calibrates from position-tracking results.
 */
enum SiteAuthorityTier: string
{
    case New = 'new';
    case Developing = 'developing';
    case Established = 'established';

    /**
     * A 0..1 organic-strength factor for this tier.
     */
    public function organicStrength(): float
    {
        return match ($this) {
            self::New => 0.25,
            self::Developing => 0.55,
            self::Established => 0.85,
        };
    }
}
