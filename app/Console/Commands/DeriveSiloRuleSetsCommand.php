<?php

namespace App\Console\Commands;

use App\Build\GuidedEntityProjector;
use App\Build\SiloRuleSetDeriver;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Backfill topical `rule_set`s onto a guided site's §4 silos so §5 keyword discovery can route
 * keywords into them ({@see SiloRuleSetDeriver}). New sites get this automatically at materialize
 * ({@see GuidedEntityProjector}); this repairs sites built before it — the reason their
 * §4 board reads "thin" is discovery had no bucketing terms to file keywords under.
 *
 * DRY-RUN by default (reports how many silos would get a rule_set); `--force` writes. `--site=` limits
 * to one tenant; omitted, it sweeps every site. Non-destructive: a silo that already has a rule_set is
 * never overwritten.
 */
class DeriveSiloRuleSetsCommand extends Command
{
    protected $signature = 'launchpad:derive-silo-rulesets
        {--site= : limit to one site id (default: every site)}
        {--force : actually write (default is a dry-run count)}';

    protected $description = 'Give guided silos topical rule_sets (from their spokes) so §5 discovery can route keywords into them. Dry-run by default; --force to write.';

    public function handle(SiloRuleSetDeriver $deriver): int
    {
        $sites = $this->targetSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $total = 0;

        foreach ($sites as $site) {
            $count = $force ? $deriver->deriveForSite($site) : $deriver->previewForSite($site);
            if ($count === 0) {
                continue;
            }

            $verb = $force ? 'gave rule_sets to' : 'would give rule_sets to';
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — {$verb} {$count} silo(s).");
            $total += $count;
        }

        $this->newLine();
        if ($total === 0) {
            $this->info('No silos need a rule_set — nothing to do.');
        } elseif ($force) {
            $this->info("Gave rule_sets to {$total} silo(s). Run discovery to fill their keyword targets.");
        } else {
            $this->warn("[dry-run] {$total} silo(s) would get a rule_set. Re-run with --force to write.");
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
