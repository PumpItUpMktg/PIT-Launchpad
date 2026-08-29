<?php

namespace App\Integrations\DomainAuthority;

/**
 * Default domain-authority binding (§ Citations, PR5). Returns null — the platform never invents an authority
 * number. Directories keep whatever `domain_rank` an operator or import set, and the rating engine falls back
 * to `authority_tier`. A real provider (DataForSEO domain analytics) binds over this later.
 */
final class MockDomainAuthorityProvider implements DomainAuthorityProvider
{
    public function rankFor(string $domain): ?int
    {
        return null;
    }
}
