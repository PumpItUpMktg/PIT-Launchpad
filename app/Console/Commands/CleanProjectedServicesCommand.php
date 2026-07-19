<?php

namespace App\Console\Commands;

use App\Build\GuidedEntityProjector;
use App\Build\ProjectedServiceCleaner;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Remove the Service rows structure output contaminated the catalog with — the legacy
 * {@see GuidedEntityProjector} wrote a provenance-less, unenriched Service per pillar /
 * service spoke, name-matched 1:1 to the structure (see {@see ProjectedServiceCleaner} for the
 * both-conditions-required rule that spares genuine manual + stated services).
 *
 * DRY-RUN by default: it lists what it would delete and writes nothing. Pass `--force` to actually
 * delete. `--site=` limits to one tenant; omitted, it sweeps every site. This is a one-way delete of
 * junk rows, so the dry-run listing is the review step — read it before `--force`.
 */
class CleanProjectedServicesCommand extends Command
{
    protected $signature = 'launchpad:clean-projected-services
        {--site= : limit to one site id (default: every site)}
        {--force : actually delete (default is a dry-run listing)}';

    protected $description = 'Remove services structure generation wrongly created (no provenance/enrichment AND name-matches a spoke/pillar/keyword). Dry-run by default; --force to delete.';

    public function handle(ProjectedServiceCleaner $cleaner): int
    {
        $sites = $this->targetSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $total = 0;

        foreach ($sites as $site) {
            $rows = $cleaner->contaminated($site);
            if ($rows->isEmpty()) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — {$rows->count()} contaminated service(s):");
            foreach ($rows as $service) {
                $verb = $force ? 'deleted' : 'would delete';
                $this->line("  • {$verb} \"{$service->name}\" ({$service->id})");
            }

            if ($force) {
                $cleaner->purge($site);
            }

            $total += $rows->count();
        }

        $this->newLine();
        if ($total === 0) {
            $this->info('No contaminated services found — nothing to clean.');
        } elseif ($force) {
            $this->info("Deleted {$total} contaminated service(s).");
        } else {
            $this->warn("[dry-run] {$total} contaminated service(s) would be deleted. Re-run with --force to remove them.");
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
