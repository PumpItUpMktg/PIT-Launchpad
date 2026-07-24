<?php

namespace App\Console\Commands;

use App\Build\ServiceStructureWriter;
use App\Enums\ServicePageTreatment;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Re-derive a site's silo structure from its AUTHORED service tree (the services-entry grouping), via
 * {@see ServiceStructureWriter}. Dry-run report by default — prints the shape each top-level service
 * WOULD build (hub vs service page, its page-children and folded sections); `--apply` persists the
 * blueprint. Deterministic and idempotent.
 */
class RebuildStructureFromServicesCommand extends Command
{
    protected $signature = 'launchpad:rebuild-structure-from-services
        {site : site id (ULID) or exact brand_name}
        {--apply : write the blueprint (default: dry-run report only)}';

    protected $description = 'Re-derive a site\'s silo structure from the authored service grouping. Dry-run unless --apply.';

    public function handle(ServiceStructureWriter $writer): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            return self::FAILURE;
        }

        $topLevel = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNull('parent_service_id')
            ->with(['childServices' => fn ($q) => $q->withoutGlobalScope(SiteScope::class)])
            ->orderBy('group_order')->orderBy('name')
            ->get();

        if ($topLevel->isEmpty()) {
            $this->warn("No top-level services for [{$site->brand_name}] — nothing to build.");

            return self::SUCCESS;
        }

        foreach ($topLevel as $service) {
            $pageKids = $service->childServices->where('page_treatment', ServicePageTreatment::Page);
            $sectionKids = $service->childServices->where('page_treatment', ServicePageTreatment::Section);
            $shape = $pageKids->isNotEmpty() ? 'HUB' : 'service page';

            $this->line("<info>{$service->name}</info> → {$shape}");
            foreach ($pageKids as $kid) {
                $this->line("    ├─ {$kid->name} (page)");
            }
            foreach ($sectionKids as $kid) {
                $this->line("    └─ {$kid->name} (section)");
            }
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Dry run — re-run with --apply to write the blueprint.');

            return self::SUCCESS;
        }

        $writer->write($site);
        $this->newLine();
        $this->info("Structure written for [{$site->brand_name}].");

        return self::SUCCESS;
    }

    private function resolveSite(): ?Site
    {
        $arg = (string) $this->argument('site');
        $site = Site::withoutGlobalScope(SiteScope::class)
            ->where('id', $arg)->orWhere('brand_name', $arg)->first();

        if ($site === null) {
            $this->error("No site matching [{$arg}].");
        }

        return $site;
    }
}
