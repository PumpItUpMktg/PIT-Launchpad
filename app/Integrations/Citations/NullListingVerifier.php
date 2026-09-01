<?php

namespace App\Integrations\Citations;

/**
 * Verifies nothing — the safe default when no page fetch / vendor adapter is wanted (and the scanner's own
 * fallback in unit tests). A null result leaves a found listing un-attributed (needs review) rather than guessed.
 */
final class NullListingVerifier implements ListingVerifier
{
    public function verify(string $directoryDomain, string $url): ?VerifiedListing
    {
        return null;
    }
}
