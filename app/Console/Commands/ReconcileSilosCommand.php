<?php

namespace App\Console\Commands;

use App\Build\GuidedEntityProjector;
use App\Build\SiloReconciler;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Remove §4 silos left behind by an earlier structure so the keyword board matches the current tree
 * ({@see SiloReconciler}). New/regenerated sites reconcile automatically at materialize
 * ({@see GuidedEntityProjector}); this repairs a site whose tree was regenerated without a
 * re-materialize, where the board still shows old silo names ("silos for services not present").
 *
 * DRY-RUN by default (lists the stale silos); `--force` deletes. `--site=` limits to one tenant;
 * omitted, it sweeps every site. Safe by the schema FKs — a deleted silo nulls its keywords'/pages'
 * `silo_id` (they survive, unpinned) and drops only its stale blog targets. It NEVER deletes when a
 * site has no spoke tree (nothing to reconcile against).
 */
class ReconcileSilosCommand extends Command
{
    protected $signature = 'launchpad:reconcile-silos
        {--site= : limit to one site id (default: every site)}
        {--force : actually delete (default is a dry-run listing)}';

    protected $description = 'Delete §4 silos not in the current spoke tree so the keyword board matches reality. Dry-run by default; --force to delete.';

    public function handle(SiloReconciler $reconciler): int
    {
        $sites = $this->targetSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $total = 0;

        foreach ($sites as $site) {
            $stale = $reconciler->stale($site);
            if ($stale === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — ".count($stale).' stale silo(s):');
            foreach ($stale as $name) {
                $verb = $force ? 'deleted' : 'would delete';
                $this->line("  • {$verb} \"{$name}\"");
            }

            if ($force) {
                $reconciler->reconcile($site);
            }

            $total += count($stale);
        }

        $this->newLine();
        if ($total === 0) {
            $this->info('No stale silos — every silo matches the current tree.');
        } elseif ($force) {
            $this->info("Deleted {$total} stale silo(s).");
        } else {
            $this->warn("[dry-run] {$total} stale silo(s) would be deleted. Re-run with --force to remove them.");
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
