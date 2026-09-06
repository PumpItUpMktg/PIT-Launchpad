<?php

namespace App\Console\Commands;

use App\Enums\PipelineTrigger;
use App\KeywordGenerator\Discovery\SiloKeywordGenerator;
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
        {--site= : limit to one site id (default: every site)}
        {--dry-run : Preview what discovery WOULD generate per silo — writes nothing, no scoring/tracking}';

    protected $description = 'Run §5 keyword discovery on demand to fill a site\'s silo keyword targets (forces past the daily cadence).';

    public function handle(SitePipelineRefresher $refresher, SiloKeywordGenerator $generator): int
    {
        $sites = $this->targetSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            return $this->dryRun($sites, $generator);
        }

        $total = 0;
        foreach ($sites as $site) {
            $result = $refresher->refresh($site, PipelineTrigger::Manual, force: true, generate: true);
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — {$result->keywordsGenerated} generated, {$result->keywordsScored} keyword(s) scored.");
            $total += $result->keywordsScored;
        }

        $this->newLine();
        $this->info("Discovery complete: {$total} keyword(s) scored across ".count($sites).' site(s).');

        return self::SUCCESS;
    }

    /**
     * DRY-RUN: report what discovery WOULD create per silo (idea pull + dedup, no writes). The go/no-go
     * for reviving generation — proves the idea provider returns ideas, and shows which silos are starved
     * (would_create 0 = a silo whose seeds return nothing, or whose seed_terms are empty).
     *
     * @param  Collection<int, Site>  $sites
     */
    private function dryRun(Collection $sites, SiloKeywordGenerator $generator): int
    {
        $this->info('DRY-RUN · no writes · previewing what §5 discovery would generate.');

        $grand = 0;
        foreach ($sites as $site) {
            $plan = $generator->preview($site);
            $siteTotal = array_sum(array_map(fn (array $p): int => $p['would_create'], $plan));
            $grand += $siteTotal;

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — would generate {$siteTotal} new keyword(s) across ".count($plan).' silo(s):');
            foreach ($plan as $row) {
                $seeds = $row['seeds'] === [] ? 'NO SEEDS' : implode(', ', $row['seeds']);
                $this->line("  · {$row['silo']}: +{$row['would_create']} (seeds: {$seeds})"
                    .($row['samples'] !== [] ? ' — e.g. '.implode('; ', $row['samples']) : ''));
            }
        }

        $this->newLine();
        $this->info("Dry-run complete: would generate {$grand} new keyword(s) across ".count($sites).' site(s). Nothing was written.');

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
