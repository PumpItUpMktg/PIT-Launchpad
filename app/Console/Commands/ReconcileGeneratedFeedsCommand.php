<?php

namespace App\Console\Commands;

use App\ContentEngine\Feeds\GeneratedFeedReconciler;
use App\Models\Site;
use Illuminate\Console\Command;

class ReconcileGeneratedFeedsCommand extends Command
{
    protected $signature = 'launchpad:reconcile-generated-feeds {--site= : Limit to a single site id}';

    protected $description = 'Materialize generated Google News feeds from the keyword map × markets (idempotent; retires stale feeds by deactivation).';

    public function handle(GeneratedFeedReconciler $reconciler): int
    {
        $upserted = 0;
        $deactivated = 0;

        foreach ($this->sites() as $site) {
            $result = $reconciler->reconcile($site);
            $upserted += $result['upserted'];
            $deactivated += $result['deactivated'];
        }

        $this->info("Generated feeds reconciled: {$upserted} active, {$deactivated} retired.");

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, Site>
     */
    private function sites(): iterable
    {
        $site = $this->option('site');

        return $site !== null
            ? Site::query()->whereKey($site)->get()
            : Site::query()->get();
    }
}
