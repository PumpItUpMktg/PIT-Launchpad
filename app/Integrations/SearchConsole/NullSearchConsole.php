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
}
