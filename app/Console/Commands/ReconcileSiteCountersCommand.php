<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Observers\ConnectionObserver;
use App\Observers\ContentObserver;
use App\Operator\SiteHealthCounters;
use Illuminate\Console\Command;

/**
 * Recompute the persisted `sites` portfolio-health counters from the source of truth — the drift net for
 * the incremental {@see ContentObserver} / {@see ConnectionObserver}. Model
 * events are bypassed by bulk query updates and by hard-delete prunes (orphan / undrafted-town / dedupe
 * sweeps), so a scheduled reconcile guarantees the counters converge on the truth regardless. Idempotent;
 * safe to run any time. `--site=` reconciles a single tenant.
 */
class ReconcileSiteCountersCommand extends Command
{
    protected $signature = 'launchpad:reconcile-site-counters {--site= : Reconcile only this site id (default: all sites)}';

    protected $description = 'Recompute the persisted portfolio-health counters on sites from source (drift net).';

    public function handle(SiteHealthCounters $counters): int
    {
        $siteId = $this->option('site');

        if ($siteId !== null) {
            $site = Site::withoutGlobalScopes()->find($siteId);
            if ($site === null) {
                $this->error("No site with id {$siteId}.");

                return self::FAILURE;
            }

            $counters->recomputeAll((string) $site->id);
            $this->info("Reconciled health counters for site {$site->id}.");

            return self::SUCCESS;
        }

        $count = $counters->recomputeAllSites();
        $this->info("Reconciled health counters for {$count} site(s).");

        return self::SUCCESS;
    }
}
