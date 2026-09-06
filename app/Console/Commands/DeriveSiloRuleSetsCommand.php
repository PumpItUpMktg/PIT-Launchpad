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
        $totalNew = 0;
        $totalRepair = 0;

        foreach ($sites as $site) {
            $r = $force ? $deriver->deriveForSite($site) : $deriver->previewForSite($site);
            if ($r['new'] === 0 && $r['repair'] === 0) {
                continue;
            }

            $verb = $force ? 'gave' : 'would give';
            $parts = [];
            if ($r['new'] > 0) {
                $parts[] = "{$r['new']} new";
            }
            if ($r['repair'] > 0) {
                $parts[] = "{$r['repair']} repaired (empty seed_terms back-filled from spokes)";
            }
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — {$verb} rule_sets: ".implode(', ', $parts).'.');
            $totalNew += $r['new'];
            $totalRepair += $r['repair'];
        }

        $total = $totalNew + $totalRepair;

        $this->newLine();
        if ($total === 0) {
            $this->info('No silos need a rule_set — nothing to do.');
        } elseif ($force) {
            $this->info("Wrote {$total} rule_set(s): {$totalNew} new, {$totalRepair} repaired. Run discovery to fill their keyword targets.");
        } else {
            $this->warn("[dry-run] {$total} rule_set(s) would be written: {$totalNew} new, {$totalRepair} repaired. Re-run with --force.");
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
