<?php

namespace App\Integrations\Citations;

use App\Citations\CitationAttributor;

/**
 * The real NAP read off a found directory listing (§ Citations). It's what turns a bare SERP domain match into
 * an attributable, verifiable citation: the {@see CitationAttributor} weights a matching phone as
 * decisive, and the scanner's NAP compare needs an address/name to fault a listing as a mismatch. Any field the
 * source couldn't yield is null.
 */
final class VerifiedListing
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $address = null,
        public readonly ?string $phone = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->name === null && $this->address === null && $this->phone === null;
    }
}
