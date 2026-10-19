<?php

namespace App\Integrations\Backlinks;

/**
 * The backlink-index seam (§ Links). Normalized contract — the sync, the citation link evidence and the
 * competitor intersect read these shapes and never the vendor's. The default binding is the DataForSEO
 * Backlinks API; tests fake the HTTP layer.
 */
interface BacklinkProvider
{
    public function summary(string $target): BacklinkSummary;

    /**
     * Live links to `$target`, ONE per referring domain (its strongest), strongest domains first. Pass the
     * previous page's `nextToken` to continue.
     */
    public function referringDomains(string $target, int $limit = 1000, ?string $after = null): BacklinkPage;
}
