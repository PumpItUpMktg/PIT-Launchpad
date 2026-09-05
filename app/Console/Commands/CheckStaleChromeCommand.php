<?php

namespace App\Console\Commands;

use App\Observers\ContentObserver;
use App\Publishing\Chrome\ChromeStaleness;
use Illuminate\Console\Command;

/**
 * Scheduled chrome-drift backstop: recomputes the `sites.chrome_stale` flag across every non-onboarding
 * tenant by comparing the freshly-assembled header/footer profile to the fingerprint last pushed. Advisory
 * only — it flags drift (surfaced as a Lobby badge) and NEVER pushes chrome; the operator re-syncs from
 * the Portfolio / Recover "Push chrome" action.
 *
 * Event-driven page publish/unpublish already marks drift instantly ({@see ContentObserver});
 * this weekly sweep catches the drift that isn't a page publish — corporate NAP edits, header nav_order /
 * featured changes — so chrome can't rot unnoticed (the "feeds dying silently" shape).
 */
class CheckStaleChromeCommand extends Command
{
    protected $signature = 'launchpad:check-stale-chrome';

    protected $description = 'Flag tenants whose live WordPress header/footer chrome has drifted from the assembled profile (never auto-pushes).';

    public function handle(ChromeStaleness $staleness): int
    {
        $result = $staleness->sweep();

        $this->info("Checked {$result['checked']} tenant(s): {$result['drifted']} with drifted chrome, {$result['never']} connected but never synced.");

        if ($result['drifted'] > 0 || $result['never'] > 0) {
            $this->warn('Drifted / never-synced tenants are flagged in the Lobby (tier 2). Re-sync via the Portfolio or Recover → Push chrome.');
        }

        return self::SUCCESS;
    }
}
