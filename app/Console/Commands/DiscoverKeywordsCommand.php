<?php

namespace App\Console\Commands;

use App\Enums\PipelineTrigger;
use App\KeywordGenerator\Pipeline\RefreshKeywordPipelines;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Run §5 keyword discovery for a site ON DEMAND — the operator trigger that fills the §4 silo board
 * with keyword targets, instead of waiting for the daily {@see RefreshKeywordPipelines}
 * job. Forces past the discovery cadence (the operator asked now) and runs synchronously (CLI, no FPM
 * clock). Needs the site's silos to carry rule_sets — run `launchpad:derive-silo-rulesets` first so
 * discovery has somewhere to route the keywords.
 *
 * `--site=` runs one tenant; omitted, it sweeps every site.
 */
class DiscoverKeywordsCommand extends Command
{
    protected $signature = 'launchpad:discover-keywords
        {--site= : limit to one site id (default: every site)}';

    protected $description = 'Run §5 keyword discovery on demand to fill a site\'s silo keyword targets (forces past the daily cadence).';

    public function handle(SitePipelineRefresher $refresher): int
    {
        $sites = $this->targetSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $total = 0;
        foreach ($sites as $site) {
            $result = $refresher->refresh($site, PipelineTrigger::Manual, force: true);
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — {$result->keywordsScored} keyword(s) scored.");
            $total += $result->keywordsScored;
        }

        $this->newLine();
        $this->info("Discovery complete: {$total} keyword(s) scored across ".count($sites).' site(s).');

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
