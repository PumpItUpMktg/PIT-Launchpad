<?php

namespace App\Citations;

use App\Integrations\DomainAuthority\DomainAuthorityProvider;
use App\Models\Directory;

/**
 * Refreshes each directory's `domain_rank` from the {@see DomainAuthorityProvider} seam (§ Citations, PR5).
 * With the default mock binding this is a no-op (the platform never fabricates authority numbers); a real
 * DataForSEO adapter fills it in. Kept separate from {@see DirectoryRating} so a rank refresh and a value
 * recompute can run independently.
 */
final class DirectoryRankRefresher
{
    public function __construct(private readonly DomainAuthorityProvider $authority) {}

    /** Refresh one directory's domain_rank. Returns true when a rank was written. */
    public function refresh(Directory $directory): bool
    {
        $rank = $this->authority->rankFor($directory->domain);
        if ($rank === null) {
            return false;
        }

        $directory->forceFill(['domain_rank' => max(0, min(100, $rank))])->save();

        return true;
    }

    /** Refresh every active directory. Returns the number whose rank was written. */
    public function refreshAll(): int
    {
        $count = 0;
        foreach (Directory::query()->where('is_active', true)->get() as $directory) {
            if ($this->refresh($directory)) {
                $count++;
            }
        }

        return $count;
    }
}
