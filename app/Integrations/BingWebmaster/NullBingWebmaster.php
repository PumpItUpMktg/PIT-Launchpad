<?php

namespace App\Integrations\BingWebmaster;

use App\Integrations\SearchConsole\PageQuery;
use App\Integrations\SearchConsole\PageSearchStats;
use App\Models\Site;

/** No Bing Webmaster Tools connection yet — the Live boards show the "Submitted to Bing" pill only. */
final class NullBingWebmaster implements BingWebmasterProvider
{
    public function connected(Site $site): bool
    {
        return false;
    }

    public function pageStats(Site $site, string $path, int $days = 28): ?PageSearchStats
    {
        return null;
    }

    /**
     * @return list<PageQuery>
     */
    public function pageQueries(Site $site, string $path, int $days = 28, int $limit = 8): array
    {
        return [];
    }
}
