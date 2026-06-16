<?php

namespace App\Enums;

/**
 * Whether a head term earns its own spoke page or is folded into the pillar. The
 * split-vs-consolidate call surfaced during the prune when volume is ambiguous.
 */
enum SpokeGranularity: string
{
    case OwnPage = 'own_page';
    case Folded = 'folded';

    public function label(): string
    {
        return match ($this) {
            self::OwnPage => 'Own page',
            self::Folded => 'Folded into pillar',
        };
    }
}
