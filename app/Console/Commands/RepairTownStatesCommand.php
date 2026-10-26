<?php

namespace App\Console\Commands;

use App\Locations\TownStateRepair;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * launchpad:repair-town-states — town pages titled with the wrong state for the town they are anchored to
 * ("Middletown, NJ" for the Bucks County Middletown): retitle, rewrite the wording, re-nest the slug with a
 * 301, fix the coverage row, re-push. Report-only unless --execute.
 */
class RepairTownStatesCommand extends Command
{
    protected $signature = 'launchpad:repair-town-states
        {--site= : The site ULID}
        {--execute : Apply the repair (default: report, change nothing)}';

    protected $description = 'Find town pages whose stored title names the wrong state for their anchored town, and (with --execute) retitle, re-nest with a 301, fix the wording and the coverage row, and re-push.';

    public function handle(TownStateRepair $repair): int
    {
        $site = Site::withoutGlobalScopes()->find((string) $this->option('site'));
        if ($site === null) {
            $this->error('Pass --site=<ulid>.');

            return self::FAILURE;
        }
        if (! (bool) $this->option('execute')) {
            $rows = $repair->report($site);
            if ($rows === []) {
                $this->line("<info>{$site->brand_name}</info> — every town page names the state its town is in.");

                return self::SUCCESS;
            }
            $this->line(sprintf('<info>%s</info> — READ-ONLY: %d town page(s) name the wrong state (run again with --execute to repair):', $site->brand_name, count($rows)));
            foreach ($rows as $r) {
                $this->line(sprintf('  /%s  "%s" → "%s"', $r['slug'], $r['title'], $r['new_title']));
            }

            return self::SUCCESS;
        }
        $done = $repair->execute($site);
        if ($done === []) {
            $this->line("<info>{$site->brand_name}</info> — nothing to repair.");

            return self::SUCCESS;
        }
        foreach ($done as $r) {
            $this->line(sprintf('  "%s" → "%s"  /%s → /%s  (301 recorded, re-push queued)', $r['title'], $r['new_title'], $r['old_slug'], $r['new_slug']));
        }
        $this->line(sprintf('Repaired %d town page(s).', count($done)));

        return self::SUCCESS;
    }
}
