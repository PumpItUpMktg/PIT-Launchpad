<?php

namespace App\KeywordGenerator\Tracking;

use App\Integrations\Serp\SerpResultSet;

/**
 * Flags cannibalization: more than one of our own URLs ranking for a single
 * keyword.
 */
class CannibalizationDetector
{
    public function isCannibalizing(SerpResultSet $serp, string $siteUrlOrDomain): bool
    {
        return count($serp->ownedBy($this->host($siteUrlOrDomain))) > 1;
    }

    /**
     * @return list<string>
     */
    public function offendingUrls(SerpResultSet $serp, string $siteUrlOrDomain): array
    {
        $owned = $serp->ownedBy($this->host($siteUrlOrDomain));

        return count($owned) > 1
            ? array_map(fn ($r) => $r->url, $owned)
            : [];
    }

    private function host(string $siteUrlOrDomain): string
    {
        $host = parse_url($siteUrlOrDomain, PHP_URL_HOST) ?: $siteUrlOrDomain;

        return strtolower(preg_replace('/^www\./', '', $host) ?? $host);
    }
}
