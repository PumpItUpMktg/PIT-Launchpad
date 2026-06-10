<?php

namespace App\Publishing;

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;

/**
 * The shared publish gate: a site is publishable only with a present,
 * non-compromised WordPress app-password connection. needsRotation() covers both
 * a compromised credential and a never-rotated one. Used by the bulk launch
 * orchestrator and the per-post publisher so both refuse on the same §9 state.
 */
class ConnectionGate
{
    public function hasVerifiedWordpress(string $siteId): bool
    {
        $connection = Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('provider', ConnectionProvider::WpAppPassword->value)
            ->first();

        return $connection !== null && ! $connection->needsRotation();
    }
}
