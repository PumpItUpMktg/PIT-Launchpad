<?php

namespace App\Console\Commands;

use App\Security\ConnectionStaleness;
use App\Security\StaleConnection;
use Illuminate\Console\Command;

/**
 * Scheduled staleness check: reports credentials overdue for rotation against
 * the configurable per-provider threshold. Advisory only — it never rotates
 * anything; the operator acts from the admin connections panel.
 */
class CheckStaleConnectionsCommand extends Command
{
    protected $signature = 'launchpad:check-stale-connections';

    protected $description = 'Report Connection credentials overdue for rotation (never auto-rotates).';

    public function handle(ConnectionStaleness $staleness): int
    {
        $report = $staleness->report();

        if ($report->isEmpty()) {
            $this->info('No stale credentials — every connection is within its rotation threshold.');

            return self::SUCCESS;
        }

        $this->warn("{$report->count()} credential(s) overdue for rotation:");
        $this->table(
            ['Site', 'Provider', 'Last rotated', 'Days', 'Threshold'],
            $report->map(fn (StaleConnection $s) => [
                $s->connection->site_id,
                $s->connection->provider->value,
                $s->neverRotated() ? 'never' : (string) $s->connection->last_rotated_at?->toDateString(),
                $s->neverRotated() ? '—' : (string) $s->daysSinceRotation,
                (string) $s->thresholdDays,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
