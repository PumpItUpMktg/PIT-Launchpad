<?php

namespace App\Integrations\DomainAuthority;

/**
 * The swappable seam for a directory's domain authority rank (§ Citations, PR5). Vendors are deferred: the
 * default binding is {@see MockDomainAuthorityProvider} (returns null — no fabricated numbers), and a real
 * adapter (DataForSEO domain analytics) binds later with no change to the rating engine.
 */
interface DomainAuthorityProvider
{
    /** Domain authority rank 0–100 for a bare domain (e.g. "yelp.com"), or null when unavailable. */
    public function rankFor(string $domain): ?int;
}
