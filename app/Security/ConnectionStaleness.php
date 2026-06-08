<?php

namespace App\Security;

use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Collection;

/**
 * Flags credentials overdue for rotation against a configurable per-provider
 * threshold, across all tenants (an operator-wide view). Purely advisory: it
 * produces a report for the admin connections panel and never rotates anything.
 */
class ConnectionStaleness
{
    /**
     * The stale credentials (overdue or never rotated), as report rows.
     *
     * @return Collection<int, StaleConnection>
     */
    public function report(): Collection
    {
        return Connection::withoutGlobalScope(SiteScope::class)
            ->orderBy('site_id')
            ->orderBy('provider')
            ->get()
            ->map(fn (Connection $c) => $this->evaluate($c))
            ->filter()
            ->values();
    }

    private function evaluate(Connection $connection): ?StaleConnection
    {
        $threshold = $this->thresholdFor($connection);

        if ($connection->last_rotated_at === null) {
            return new StaleConnection($connection, $threshold, null);
        }

        $days = (int) $connection->last_rotated_at->startOfDay()->diffInDays(now()->startOfDay());

        if ($days <= $threshold) {
            return null;
        }

        return new StaleConnection($connection, $threshold, $days);
    }

    private function thresholdFor(Connection $connection): int
    {
        /** @var array<string, int> $map */
        $map = config('launchpad.rotation.staleness_days', []);

        return (int) ($map[$connection->provider->value]
            ?? config('launchpad.rotation.default_staleness_days', 180));
    }
}
