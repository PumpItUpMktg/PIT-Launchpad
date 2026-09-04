<?php

namespace App\Enums;

/**
 * The four attention tiers for operator-lobby badges. Colour maps to TIER, never to count — ten pending
 * reviews (Tier 3, amber) must never look more alarming than one broken connection (Tier 1, red). A
 * lower `rank()` is more urgent; a Tier-1 badge suppresses all lower tiers on a card into "+N more".
 */
enum LobbyBadgeTier: int
{
    case BrokenBlocking = 1;   // publishing blocked (red)
    case WrongData = 2;        // wrong data reaching the public (red)
    case WorkWaiting = 3;      // work waiting on a person (amber)
    case Degrading = 4;        // degrading quietly (grey)

    /** Filament/UI colour token for the tier. */
    public function color(): string
    {
        return match ($this) {
            self::BrokenBlocking, self::WrongData => 'danger',
            self::WorkWaiting => 'warning',
            self::Degrading => 'gray',
        };
    }

    public function rank(): int
    {
        return $this->value;
    }

    /** Tier 1 is the publishing-blocked tier — its presence flips a card to "Blocked". */
    public function blocksPublishing(): bool
    {
        return $this === self::BrokenBlocking;
    }
}
