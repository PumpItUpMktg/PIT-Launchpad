<?php

namespace App\Locations;

/**
 * The buildability of one (market × tier) under the tiered-rollout gate — buildable or locked, with a
 * human unlock/status reason and the tier-above indexing figures behind the decision. Consumed by
 * {@see TierGate} (the drip gate) and the PR-2 progression view. Advisory: `buildable=false` never hard-
 * stops an operator override, it only stops the automatic drip and drives the locked-band UI.
 */
final class TierStatus
{
    /**
     * @param  int  $builtAbove  built pages in the tier above (0 when this is the top tier)
     * @param  int  $indexedAbove  of those, how many Google reports indexed
     */
    public function __construct(
        public readonly bool $buildable,
        public readonly string $reason,
        public readonly int $builtAbove = 0,
        public readonly int $indexedAbove = 0,
    ) {}

    public static function buildable(string $reason, int $builtAbove = 0, int $indexedAbove = 0): self
    {
        return new self(true, $reason, $builtAbove, $indexedAbove);
    }

    public static function locked(string $reason, int $builtAbove = 0, int $indexedAbove = 0): self
    {
        return new self(false, $reason, $builtAbove, $indexedAbove);
    }
}
