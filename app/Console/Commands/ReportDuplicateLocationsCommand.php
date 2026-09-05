<?php

namespace App\Console\Commands;

use App\Locations\Diagnostics\LocationAudit;
use Illuminate\Console\Command;

/**
 * Report (location-integrity relay, item 3): same-address duplicate locations. A zero-page row is
 * REMOVABLE only when a same-address sibling's counties are a strict superset (it provably adds nothing
 * — Roslyn's [36059] ⊂ [36059,36081,36103,36047]; the empty-county stub is the ∅ ⊂ anything case). A
 * zero-page row with no such sibling (disjoint/equal counties, or a storefront) is AMBIGUOUS — reported,
 * never auto-removed. A row with pages is never removable. REPORT-ONLY by default; `--execute` removes
 * only the removable rows (the only write in the whole relay, gated here + re-verified at delete time).
 *
 * Counts are LIVE-ONLY: NAP-merged locations are excluded; the survivor is the strictly-more-complete
 * same-address row (which may be on a different tenant — a cross-tenant stray).
 */
class ReportDuplicateLocationsCommand extends Command
{
    protected $signature = 'launchpad:report-duplicate-locations {--execute : Remove the provably-redundant duplicates (default is report-only)}';

    protected $description = 'Report (read-only) same-address duplicate locations; --execute removes only the provably-redundant ones.';

    public function handle(LocationAudit $audit): int
    {
        $rows = $audit->duplicateLocations();
        $execute = (bool) $this->option('execute');

        $this->info('Counts are LIVE-ONLY (NAP-merged locations excluded).'.($execute ? ' --execute: removable rows WILL be deleted; ambiguous rows are left for you.' : ' Report-only — pass --execute to remove the removable ones.'));

        if ($rows === []) {
            $this->info('No same-address duplicate locations found.');

            return self::SUCCESS;
        }

        $removed = $removable = $ambiguous = $crossTenant = 0;
        foreach ($rows as $dup) {
            $tag = ! $dup['removable'] ? 'AMBIGUOUS (report-only)' : ($dup['cross_tenant'] ? 'CROSS-TENANT removable' : 'removable duplicate');
            $dup['removable'] ? $removable++ : $ambiguous++;
            $crossTenant += $dup['cross_tenant'] ? 1 : 0;

            $this->newLine();
            $this->warn("● {$tag}: {$dup['duplicate']}  (site {$dup['site_id']}, location {$dup['duplicate_id']})");
            $this->line("  address:  {$dup['address']}");
            $this->line('  counties: ['.implode(',', $dup['candidate_counties']).']');
            $this->line('  survivor: '.($dup['survivor'] !== null
                ? "{$dup['survivor']} ({$dup['survivor_id']})  counties [".implode(',', $dup['survivor_counties']).']'.($dup['cross_tenant'] ? "  on DIFFERENT tenant {$dup['survivor_site_id']}" : '')
                : '(none — no strictly-more-complete sibling)'));
            $this->line("  why:      {$dup['reason']}");

            if ($execute && $dup['removable']) {
                $removed += $audit->removeDuplicate($dup['duplicate_id']) ? 1 : 0;
            }
        }

        $this->newLine();
        if ($execute) {
            $this->warn("Removed {$removed} removable duplicate(s); left {$ambiguous} ambiguous for you to decide.");
        } else {
            $this->warn("{$removable} removable · {$ambiguous} ambiguous (report-only)".($crossTenant > 0 ? " · {$crossTenant} cross-tenant" : '').'. Re-run with --execute to remove the removable ones.');
        }

        return self::SUCCESS;
    }
}
