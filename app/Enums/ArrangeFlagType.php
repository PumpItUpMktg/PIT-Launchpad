<?php

namespace App\Enums;

/**
 * The judgment calls auto-arrange surfaces for operator confirm (advisory — the
 * same pattern as the dead-silo flag). auto-arrange auto-resolves the mechanical
 * decisions and raises one of these for anything that needs a human look. Later
 * passes add SubHubDemotion (Pass C) and KeywordCollision (Pass D).
 */
enum ArrangeFlagType: string
{
    /** Pass B: a cross-silo near-dup where two homes were too close to call. */
    case DedupAmbiguous = 'dedup_ambiguous';

    /** Pass A: a folded spoke that cleared no core's relatedness floor — parked on the pillar. */
    case NestLowConfidence = 'nest_low_confidence';

    public function label(): string
    {
        return match ($this) {
            self::DedupAmbiguous => 'Ambiguous duplicate',
            self::NestLowConfidence => 'Uncertain nesting',
        };
    }
}
