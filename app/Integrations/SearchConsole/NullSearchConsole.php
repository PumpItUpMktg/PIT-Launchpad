<?php

namespace App\Integrations\SearchConsole;

use App\Models\Site;

/** No Search Console connection yet — the Live boards show the connect prompt. */
final class NullSearchConsole implements SearchConsoleProvider
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

    public function pageStatsCached(Site $site, string $path, int $days = 28): ?PageSearchStats
    {
        return null;
    }

    /**
     * @return list<PageQuery>
     */
    public function pageQueriesCached(Site $site, string $path, int $days = 28, int $limit = 8): array
    {
        return [];
    }
}
