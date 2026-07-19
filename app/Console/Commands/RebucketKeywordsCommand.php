<?php

namespace App\Console\Commands;

use App\KeywordGenerator\KeywordRebucketer;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Re-file a site's unassigned keywords into silos via rule_set matching ({@see KeywordRebucketer}) —
 * clears the board's "Unassigned" band after a silo change orphaned them (e.g. the reconcile that
 * removed stale silos nulled their keywords). Run `launchpad:derive-silo-rulesets` first so the silos
 * have terms to match on. `--site=` limits to one tenant; omitted, it sweeps every site.
 */
class RebucketKeywordsCommand extends Command
{
    protected $signature = 'launchpad:rebucket-keywords
        {--site= : limit to one site id (default: every site)}';

    protected $description = 'Re-file unassigned keywords into silos by rule_set match (clears the board\'s Unassigned band).';

    public function handle(KeywordRebucketer $rebucketer): int
    {
        $sites = $this->targetSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $total = 0;
        foreach ($sites as $site) {
            $count = $rebucketer->rebucket($site);
            if ($count > 0) {
                $this->line("<info>{$site->brand_name}</info> ({$site->id}) — re-filed {$count} keyword(s).");
                $total += $count;
            }
        }

        $this->newLine();
        $this->info($total === 0
            ? 'No unassigned keywords matched a silo (derive rule_sets first if the silos have none).'
            : "Re-filed {$total} keyword(s) into silos.");

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
