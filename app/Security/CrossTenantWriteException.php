<?php

namespace App\Security;

use App\Models\Concerns\BelongsToSite;
use App\Support\CurrentSite;
use RuntimeException;

/**
 * Thrown when a mutating operation targets a row belonging to a tenant other than the locked working
 * tenant ({@see CurrentSite}). The wrong-site-write guard on
 * {@see BelongsToSite} raises this on `saving`/`deleting` so a mis-scoped write
 * fails loudly at the model layer instead of silently landing in another tenant's data — the
 * wrong-site-publish class of bug this whole workstream exists to prevent.
 *
 * It only fires when a tenant IS locked (an operator-panel request); jobs, console commands, and the
 * lobby run with no lock ({@see CurrentSite::id()} null), where the guard is a no-op.
 */
class CrossTenantWriteException extends RuntimeException
{
    public static function for(string $model, ?string $rowSite, ?string $lockedSite): self
    {
        return new self(sprintf(
            'Refusing to write %s for site %s while tenant %s is locked (cross-tenant write).',
            $model,
            $rowSite ?? 'null',
            $lockedSite ?? 'null',
        ));
    }
}
