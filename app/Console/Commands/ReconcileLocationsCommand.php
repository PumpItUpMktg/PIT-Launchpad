<?php

namespace App\Console\Commands;

use App\Locations\Reconcile\LocationNapReconciler;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Heals the two-unlinked-rows NAP bug ({@see LocationNapReconciler}): a physical location split across
 * a bare intake row and a GBP-enriched row, so its page renders a thin NAP. Folds the duplicate into a
 * survivor (back-fills the survivor's missing GBP fields, re-points its pages, tombstones the dupe so
 * it can't grow a second hub page). Non-destructive — the retired row is hidden, not deleted, and can
 * be restored by nulling merged_into_id.
 *
 * DRY-RUN by default (lists the folds); `--force` applies. `--site=` limits to one tenant; omitted, it
 * sweeps every site. Only high-precision matches are folded (phone, or address+name); anything
 * ambiguous is left untouched for manual review.
 */
class ReconcileLocationsCommand extends Command
{
    protected $signature = 'launchpad:reconcile-locations
        {--site= : limit to one site id (default: every site)}
        {--force : actually apply the folds (default is a dry-run listing)}';

    protected $description = 'Fold duplicate physical Location rows (bare intake + GBP-synced) into one so the NAP is complete. Dry-run by default; --force to apply.';

    public function handle(LocationNapReconciler $reconciler): int
    {
        $sites = $this->targetSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $total = 0;

        foreach ($sites as $site) {
            $merges = $reconciler->reconcile($site, apply: $force);
            if ($merges === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — ".count($merges).' duplicate location(s):');
            foreach ($merges as $merge) {
                $verb = $force ? '✓' : 'would fold';
                $this->line("  • {$verb} {$merge->summary()}");
            }

            $total += count($merges);
        }

        $this->newLine();
        if ($total === 0) {
            $this->info('No duplicate locations — every physical location is a single row.');
        } elseif ($force) {
            $this->info("Folded {$total} duplicate location(s). Re-push the affected location pages to refresh their NAP.");
        } else {
            $this->warn("[dry-run] {$total} duplicate location(s) would be folded. Re-run with --force to apply.");
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>|null
     */
    private function targetSites(): ?Collection
    {
        $siteId = $this->option('site');

        if ($siteId !== null) {
            $site = Site::withoutGlobalScopes()->find($siteId);
            if ($site === null) {
                $this->error("No site with id [{$siteId}].");

                return null;
            }

            return collect([$site]);
        }

        return Site::withoutGlobalScope(SiteScope::class)->orderBy('brand_name')->get();
    }
}
