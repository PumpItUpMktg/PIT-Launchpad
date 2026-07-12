<?php

namespace App\Integrations\Analytics;

use App\Models\Site;

/** No GA4 connection yet — the Live boards show the connect prompt. */
final class NullPageTraffic implements PageTrafficProvider
{
    public function connected(Site $site): bool
    {
        return false;
    }

    public function sessions(Site $site, string $path, int $days = 28): ?int
    {
        return null;
    }
}
