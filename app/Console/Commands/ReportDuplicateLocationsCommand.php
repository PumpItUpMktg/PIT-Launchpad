<?php

namespace App\Console\Commands;

use App\Locations\Diagnostics\LocationAudit;
use Illuminate\Console\Command;

/**
 * Report (location-integrity relay, item 3): partial-insert duplicate locations — a second row at the
 * same address carrying no county data, not a storefront, with zero pages (the abandoned half of a
 * two-step insert; the Roslyn case). REPORT-ONLY by default; `--execute` removes the stub (the only
 * write in the whole relay, gated here).
 *
 * Counts are LIVE-ONLY: NAP-merged locations are excluded; the survivor is the non-stub row at the same
 * address the stub should have merged into.
 */
class ReportDuplicateLocationsCommand extends Command
{
    protected $signature = 'launchpad:report-duplicate-locations {--execute : Remove the partial-insert duplicates (default is report-only)}';

    protected $description = 'Report (read-only) partial-insert duplicate locations; --execute removes them.';

    public function handle(LocationAudit $audit): int
    {
        $rows = $audit->duplicateLocations();
        $execute = (bool) $this->option('execute');

        $this->info('Counts are LIVE-ONLY (NAP-merged locations excluded).'.($execute ? ' --execute: stubs WILL be removed.' : ' Report-only — pass --execute to remove.'));

        if ($rows === []) {
            $this->info('No partial-insert duplicate locations found.');

            return self::SUCCESS;
        }

        $removed = 0;
        $crossTenant = 0;
        foreach ($rows as $dup) {
            $tag = $dup['cross_tenant'] ? 'CROSS-TENANT stray' : 'duplicate';
            $crossTenant += $dup['cross_tenant'] ? 1 : 0;
            $this->newLine();
            $this->warn("● {$tag}: {$dup['duplicate']}  (site {$dup['site_id']}, location {$dup['duplicate_id']})");
            $this->line("  address:  {$dup['address']}");
            $this->line('  survivor: '.($dup['survivor'] !== null
                ? "{$dup['survivor']} ({$dup['survivor_id']})".($dup['cross_tenant'] ? "  on DIFFERENT tenant {$dup['survivor_site_id']}" : '')
                : '(none found — verify before removing)'));
            $this->line("  why:      {$dup['reason']}");

            if ($execute) {
                $removed += $audit->removeDuplicate($dup['duplicate_id']) ? 1 : 0;
            }
        }

        $this->newLine();
        if ($execute) {
            $this->warn("Removed {$removed} stray/duplicate location(s).");
        } else {
            $this->warn(count($rows).' stray/duplicate location(s)'.($crossTenant > 0 ? " ({$crossTenant} cross-tenant)" : '').'. Re-run with --execute to remove them.');
        }

        return self::SUCCESS;
    }
}
