<?php

namespace App\Enums;

/**
 * Where a candidate spoke sits relative to the owner's confirmed offering, on the
 * customer's problem chain. Set by the problem-chain expansion (Phase 2).
 */
enum SpokeTag: string
{
    /** Confirmed offering — matches the seed / GBP. */
    case Core = 'core';

    /** Related service within the same trade. */
    case Adjacent = 'adjacent';

    /** Problem-chain service, often another trade, with the connection stated. */
    case Connecting = 'connecting';

    /** Peripheral / out-of-lane — flag, possibly route to a sibling brand. */
    case Fringe = 'fringe';

    public function label(): string
    {
        return match ($this) {
            self::Core => 'Core offering',
            self::Adjacent => 'Adjacent',
            self::Connecting => 'Connecting',
            self::Fringe => 'Fringe',
        };
    }
}
