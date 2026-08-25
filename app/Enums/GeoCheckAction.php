<?php

namespace App\Enums;

/**
 * What the GEO check did with one (prompt × engine) pair during a run — the rows of the operator's
 * activity log. `measured` wrote a fresh reading; `skipped_fresh` reused a still-fresh one (freshness
 * cache); `deferred` ran out of the wall-clock budget before reaching it; `error` got no answer from the
 * engine (kept the prior reading, wrote nothing).
 */
enum GeoCheckAction: string
{
    case Measured = 'measured';
    case SkippedFresh = 'skipped_fresh';
    case Deferred = 'deferred';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Measured => 'Measured',
            self::SkippedFresh => 'Skipped (fresh)',
            self::Deferred => 'Deferred (budget)',
            self::Error => 'Engine error',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Measured => '#2563eb',
            self::SkippedFresh => '#8a95a3',
            self::Deferred => '#b45309',
            self::Error => '#c0392b',
        };
    }
}
