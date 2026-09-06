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

    public function sessionsCached(Site $site, string $path, int $days = 28): ?int
    {
        return null;
    }

    /** @return array{sessions: ?int, warmed: bool} */
    public function sessionsCachedState(Site $site, string $path, int $days = 28): array
    {
        return ['sessions' => null, 'warmed' => false];
    }

    public function refresh(Site $site, string $path, int $days = 28): ?int
    {
        return null;
    }
}
