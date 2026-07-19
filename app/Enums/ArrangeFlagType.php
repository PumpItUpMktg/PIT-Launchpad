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

    /**
     * Plain-language copy for the operator: what this kind of call is, and what Accept vs Dismiss
     * actually does. The auto-arranger already applied its recommendation — Accept locks it in,
     * Dismiss reverts to the pre-arrange structure and stops re-flagging. Kept here (not in the
     * view) so the wording is single-sourced and testable.
     *
     * @return array{what: string, accept: string, dismiss: string}
     */
    public function help(): array
    {
        return match ($this) {
            self::DedupAmbiguous => [
                'what' => 'Two pages look like near-duplicates and the arranger couldn\'t tell which should own the topic.',
                'accept' => 'Keep the home it chose.',
                'dismiss' => 'Use the other page as the home instead.',
            ],
            self::NestLowConfidence => [
                'what' => 'A supporting topic had no clear parent page, so it was parked on the silo\'s hub.',
                'accept' => 'Nest it under the suggested parent page.',
                'dismiss' => 'Leave it on the hub.',
            ],
            self::SubHubDemotion => [
                'what' => 'A whole silo\'s topics mostly belong under another silo, so it was demoted to a sub-section of it.',
                'accept' => 'Keep it demoted under the parent silo.',
                'dismiss' => 'Keep it as its own top-level silo.',
            ],
            self::KeywordCollision => [
                'what' => 'Two pages target the same search term — only one can rank for it.',
                'accept' => 'Keep the page the arranger picked for that term.',
                'dismiss' => 'Use the other page for it.',
            ],
            self::SubHubKeywordCollision => [
                'what' => 'A demoted sub-hub\'s landing term still overlaps one of its child pages.',
                'accept' => 'Keep the demotion as arranged.',
                'dismiss' => 'Keep them separate.',
            ],
            self::DeadSilo => [
                'what' => 'No page in this silo is strong enough to stand on its own, and its total search demand is low.',
                'accept' => 'Fold it into the suggested silo.',
                'dismiss' => 'Keep it as its own silo.',
            ],
        };
    }
}
