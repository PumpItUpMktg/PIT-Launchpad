<?php

namespace App\Console\Commands;

use App\Build\StructureResetter;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Clear a site's generated structure so it rebuilds from the current stated inputs — the companion to
 * `launchpad:clean-projected-services`. After the Service cleanup, the {@see Spoke} tree
 * still mirrors the old (deleted) catalog; this drops that tree (+ the orphaned queued blog targets)
 * and resets the blueprint's generated state, keeping the trade/interview seed so the operator's
 * "generate structure" action / keyword-first derive rebuilds against the clean catalog.
 *
 * DRY-RUN by default — reports what it would remove and writes nothing. `--force` performs the reset.
 * `--site=` limits to one tenant; omitted, it sweeps every site. It never deletes Service rows,
 * published content, or §4 silos/keywords (those are reconciled on rebuild).
 */
class ResetStructureCommand extends Command
{
    protected $signature = 'launchpad:reset-structure
        {--site= : limit to one site id (default: every site)}
        {--force : actually clear (default is a dry-run listing)}';

    protected $description = 'Clear a site\'s generated spoke tree + queued blog targets (keeping the trade/interview seed) so structure rebuilds from the clean stated catalog. Dry-run by default; --force to clear.';

    public function handle(StructureResetter $resetter): int
    {
        $sites = $this->targetSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $touched = 0;

        foreach ($sites as $site) {
            $counts = $force ? $resetter->reset($site) : $resetter->preview($site);
            if ($counts['spokes'] === 0 && $counts['queued_targets'] === 0 && ! $counts['blueprint']) {
                continue; // nothing built for this site
            }

            $verb = $force ? 'cleared' : 'would clear';
            $bp = $counts['blueprint'] ? '; blueprint generated-state reset' : '';
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — {$verb} {$counts['spokes']} spoke(s), {$counts['queued_targets']} queued blog target(s){$bp}.");
            $touched++;
        }

        $this->newLine();
        if ($touched === 0) {
            $this->info('No generated structure found — nothing to reset.');
        } elseif ($force) {
            $this->info("Reset {$touched} site(s). Re-run the structure generation to rebuild from the clean catalog.");
        } else {
            $this->warn("[dry-run] {$touched} site(s) would be reset. Re-run with --force to clear.");
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
