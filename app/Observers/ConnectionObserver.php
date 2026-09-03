<?php

namespace App\Observers;

use App\Models\Connection;
use App\Operator\SiteHealthCounters;

/**
 * Keeps the persisted `sites.compromised_count` in sync on every single-model `Connection` write. Every
 * writer of `compromised` is a single-model save or `updateOrCreate` (no bulk query updates exist), so this
 * catches them all. Recompute-from-source (idempotent); only fires when `compromised` was actually created
 * or changed. Delete also recomputes (a removed compromised connection lowers the count).
 */
class ConnectionObserver
{
    public function __construct(private readonly SiteHealthCounters $counters) {}

    public function created(Connection $connection): void
    {
        if ($connection->compromised) {
            $this->counters->recomputeCompromised((string) $connection->site_id);
        }
    }

    public function updated(Connection $connection): void
    {
        if ($connection->wasChanged('compromised')) {
            $this->counters->recomputeCompromised((string) $connection->site_id);
        }
    }

    public function deleted(Connection $connection): void
    {
        if ($connection->compromised) {
            $this->counters->recomputeCompromised((string) $connection->site_id);
        }
    }
}
