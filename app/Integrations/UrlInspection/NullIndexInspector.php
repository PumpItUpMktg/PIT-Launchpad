<?php

namespace App\Integrations\UrlInspection;

use App\Models\Site;

/**
 * The default {@see IndexInspector} binding — no shared Google grant, so nothing is connected and every
 * inspection resolves to null (the surfaces then show the honest "not connected / not inspected" state,
 * never a fabricated index verdict).
 */
final class NullIndexInspector implements IndexInspector
{
    public function connected(Site $site): bool
    {
        return false;
    }

    public function inspect(Site $site, string $url): ?IndexStatus
    {
        return null;
    }

    public function cached(Site $site, string $url): ?IndexStatus
    {
        return null;
    }
}
