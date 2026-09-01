<?php

namespace App\Integrations\Citations;

/**
 * Reads the actual NAP off a found directory listing so the citation scanner can attribute it to the right
 * location and fault mismatches (§ Citations). Organic SERP carries no NAP, so a multi-location brand's listings
 * are otherwise un-tellable apart — this seam closes that gap. Capability-role interface: the default binding
 * fetches + parses the listing page; a vendor adapter (e.g. DataForSEO Business Data for the anti-scraping
 * majors) can bind later with no change to the scanner. Returns null when the listing can't be read.
 */
interface ListingVerifier
{
    public function verify(string $directoryDomain, string $url): ?VerifiedListing;
}
