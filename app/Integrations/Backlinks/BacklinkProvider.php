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

    /**
     * Referring domains that link to at least one of `$targets` and to none of `$exclude` — the competitor
     * intersect. Each row says which targets it links to.
     *
     * @param  list<string>  $targets  up to 20 domains
     * @param  list<string>  $exclude  up to 10 domains
     * @return list<IntersectRow>
     */
    public function domainIntersection(array $targets, array $exclude, int $limit = 300): array;
}
