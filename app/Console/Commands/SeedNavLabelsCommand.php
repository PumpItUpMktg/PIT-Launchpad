<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Chrome\NavLabelSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Seed the header-only short nav labels ({@see Content::$nav_label}) for a site's hub-child pages
 * — strip each hub's terms from its children so the menu reads short, falling back to the full title on
 * collisions / too-short results. Operator-confirmed labels are never overwritten. Idempotent.
 *
 * `--site=` seeds one tenant; omitted, it sweeps every site.
 */
class SeedNavLabelsCommand extends Command
{
    protected $signature = 'launchpad:seed-nav-labels
        {--site= : limit to one site id (default: every site)}';

    protected $description = 'Auto-seed header nav_label short labels for hub-child pages (operator overrides preserved).';

    public function handle(NavLabelSeeder $seeder): int
    {
        $sites = $this->targetSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $total = 0;
        foreach ($sites as $site) {
            $changed = $seeder->seed($site);
            $total += $changed;
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — {$changed} nav label(s) seeded.");
        }

        $this->info("Done — {$total} nav label(s) seeded across ".count($sites).' site(s).');

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
