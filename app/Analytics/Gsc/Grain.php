<?php

namespace App\Analytics\Gsc;

/**
 * The idempotency key for a GSC snapshot row: a stable sha256 over the grain
 * tuple. Used as the upsert `grain_hash` so a trailing-window re-pull (which
 * absorbs GSC's ~3-day revisions) updates the existing row instead of
 * inserting a duplicate. Parts are joined with a NUL separator so no value can
 * collide across boundaries.
 */
final class Grain
{
    /**
     * @param  array<int, string|int|null>  $parts
     */
    public static function hash(array $parts): string
    {
        return hash('sha256', implode("\0", array_map(static fn ($p): string => (string) $p, $parts)));
    }
}
