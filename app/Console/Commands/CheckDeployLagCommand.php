<?php

namespace App\Console\Commands;

use App\Operator\DeployLag;
use Illuminate\Console\Command;

/**
 * Scheduled (hourly) deploy-lag probe: fetch `origin/main`, compute how far the deployed checkout is behind
 * and the oldest undeployed commit's age, and STORE the snapshot so the lobby renders its platform-level
 * deploy-lag notice without shelling out per request. Hourly matches the "a deploy should have landed by
 * now" horizon — a weekly sweep would take days to notice a stuck pipeline (how the feeds ran 16 days).
 * Read-only against the repo; writes only the cached snapshot.
 */
class CheckDeployLagCommand extends Command
{
    protected $signature = 'launchpad:check-deploy-lag';

    protected $description = 'Probe how far the deployed checkout is behind origin/main and cache it for the lobby deploy-lag notice.';

    public function handle(DeployLag $lag): int
    {
        $snap = $lag->compute(fetch: true);
        $lag->store($snap);

        if ($snap['deployed_sha'] === null) {
            $this->warn('Not a git checkout — deploy-lag unknown; nothing to compare (stored as such).');

            return self::SUCCESS;
        }

        $behind = $snap['behind'] !== null ? (string) $snap['behind'] : 'unknown';
        $this->info("Deploy lag: severity={$snap['severity']}, behind={$behind}, checked {$snap['checked_at']}.");

        return self::SUCCESS;
    }
}
