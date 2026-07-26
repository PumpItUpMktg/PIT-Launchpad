<?php

namespace App\ContentEngine\Reconcile;

/**
 * A readiness row's traffic-light state (§B slice 5). Green = aligned, amber = drifted but publishing,
 * red = broken / blocking. Drives the surface's color + sort (worst first).
 */
enum ReadinessStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Bad = 'bad';

    /** Worst-first sort weight. */
    public function weight(): int
    {
        return match ($this) {
            self::Bad => 0,
            self::Warn => 1,
            self::Ok => 2,
        };
    }

    public function glyph(): string
    {
        return match ($this) {
            self::Ok => '✓',
            self::Warn => '⚠',
            self::Bad => '✗',
        };
    }
}
