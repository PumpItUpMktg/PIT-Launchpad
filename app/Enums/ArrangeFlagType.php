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

    /** Pass C: a silo whose spokes mostly cluster into one other silo — recommend demoting it to a sub-hub. */
    case SubHubDemotion = 'sub_hub_demotion';

    /** Pass D: two pages resolve to the same query and the resolution isn't mechanical — operator confirms. */
    case KeywordCollision = 'keyword_collision';

    /** Pass D: a sub-hub's umbrella keyword still collides with a child — don't silently collapse the demotion. */
    case SubHubKeywordCollision = 'sub_hub_keyword_collision';

    /** Pass E: a silo no core clears the bar for and whose total volume is below it — advisory fold candidate. */
    case DeadSilo = 'dead_silo';

    public function label(): string
    {
        return match ($this) {
            self::DedupAmbiguous => 'Ambiguous duplicate',
            self::NestLowConfidence => 'Uncertain nesting',
            self::SubHubDemotion => 'Sub-hub demotion',
            self::KeywordCollision => 'Keyword collision',
            self::SubHubKeywordCollision => 'Sub-hub keyword collision',
            self::DeadSilo => 'Dead silo',
        };
    }
}
