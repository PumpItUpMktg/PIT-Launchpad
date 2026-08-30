<?php

namespace App\Console\Commands;

use App\Citations\NapBackfiller;
use Illuminate\Console\Command;

/**
 * Seed canonical NAP profiles from GBP data for existing locations (§ Citations). For locations that predate NAP
 * auto-population: creates a NAP where the GBP data supports one, syncs GBP-tracked fields where a NAP already
 * exists (operator overrides preserved), and skips locations with no usable GBP data. Reads stored data only —
 * no Places calls — so it's fast and safe to re-run. `--site=` scopes to one tenant.
 */
class BackfillNapsCommand extends Command
{
    protected $signature = 'launchpad:backfill-naps {--site= : Only backfill this site}';

    protected $description = 'Create/sync canonical NAP profiles from GBP data for existing locations.';

    public function handle(NapBackfiller $backfiller): int
    {
        $site = $this->option('site');
        $counts = $backfiller->run(is_string($site) ? $site : null);

        $this->info("NAP backfill complete: {$counts['created']} created, {$counts['updated']} synced, "
            ."{$counts['skipped']} skipped (no usable GBP data).");

        return self::SUCCESS;
    }
}
