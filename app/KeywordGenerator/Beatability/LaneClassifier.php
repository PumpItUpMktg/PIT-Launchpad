<?php

namespace App\KeywordGenerator\Beatability;

use App\Enums\BeatabilityLane;
use App\Enums\IntentLevel;

/**
 * Routes a keyword to the winnable lane: local-intent (hire-now) queries to the
 * local_pack lane; informational queries to the organic lane.
 */
class LaneClassifier
{
    private const LOCAL_SIGNALS = ['near me', 'nearby', 'in my area', 'open now'];

    public function classify(string $query, IntentLevel $intent): BeatabilityLane
    {
        $haystack = mb_strtolower($query);

        foreach (self::LOCAL_SIGNALS as $signal) {
            if (str_contains($haystack, $signal)) {
                return BeatabilityLane::LocalPack;
            }
        }

        return match ($intent) {
            IntentLevel::Transactional, IntentLevel::Commercial => BeatabilityLane::LocalPack,
            IntentLevel::Informational, IntentLevel::Navigational => BeatabilityLane::Organic,
        };
    }
}
