<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Operator\Coverage\MarketArtifactRenamer;
use Illuminate\Console\Command;

/**
 * Strip the leading numbered-list artifact ("4, Marshall" -> "Marshall") off a market NAME and its source
 * CoverageArea, so the rename is a no-op for the next build (see {@see MarketArtifactRenamer}). Published
 * pages are NOT touched — the numbered markets that carry pages are runaway duplicates that get DEDUPED,
 * not retitled. A market whose cleaned name already belongs to another market is a duplicate: skipped here
 * (COLLISION) for the merge tool.
 *
 * REPORT-ONLY by default: prints the per-market plan and writes NOTHING. Pass --execute to apply, then it
 * re-reads and reports remaining dirty markets (write-verification). Live-only, all tenants (or one via --site).
 */
class RenameMarketArtifactsCommand extends Command
{
    protected $signature = 'launchpad:rename-market-artifacts
        {--site= : Limit to one site id or brand name}
        {--execute : Apply the rename (default: report-only — writes nothing)}';

    protected $description = 'Strip the "N, " numbered-list artifact off market names + their CoverageArea source (report-only by default; --execute to apply). Published pages are not touched.';

    public function handle(MarketArtifactRenamer $renamer): int
    {
        $opt = trim((string) $this->option('site'));
        if ($opt !== '') {
            $site = Site::withoutGlobalScope(VisibleSiteScope::class)->where('id', $opt)->orWhere('brand_name', $opt)->first();
            if ($site === null) {
                $this->error("No site matches [{$opt}].");

                return self::FAILURE;
            }
            $sites = collect([$site]);
        } else {
            $sites = Site::query()->get();
        }

        $execute = (bool) $this->option('execute');
        $this->info($execute
            ? 'EXECUTE · live-only · stripping the "N, " artifact off market names + their CoverageArea source.'
            : 'Read-only · live-only · market-artifact rename PLAN. Nothing is changed (pass --execute to apply).');

        $grandRenamable = 0;
        $grandCollisions = 0;
        foreach ($sites as $site) {
            $plan = $renamer->plan($site);
            if ($plan === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id})");
            foreach ($plan as $r) {
                if ($r['collision']) {
                    $grandCollisions++;
                    $this->line("  · <comment>\"{$r['old']}\"</comment>  →  \"{$r['new']}\"  <fg=red>COLLISION</> — \"{$r['new']}\" already exists; a duplicate, skipped (use the merge tool)");

                    continue;
                }

                $grandRenamable++;
                $area = $r['coverage_area_id'] !== null
                    ? 'CoverageArea '.($r['coverage_area_dirty'] ? 'cleaned' : 'already clean').' (matched)'
                    : 'no CoverageArea matched (market-only)';
                $this->line("  · <comment>\"{$r['old']}\"</comment>  →  \"{$r['new']}\"  · {$area}");
            }

            if ($execute) {
                $renamer->apply($site);
            }
        }

        $this->newLine();
        if ($grandRenamable === 0 && $grandCollisions === 0) {
            $this->info('No market artifacts found — nothing to rename.');

            return self::SUCCESS;
        }

        if ($grandCollisions > 0) {
            $this->warn("{$grandCollisions} market(s) skipped for a name COLLISION — the cleaned name already belongs to another market (a duplicate). Merge those with launchpad:merge-markets.");
        }

        if (! $execute) {
            $this->info("{$grandRenamable} market(s) would be renamed across all tenants. Re-run with --execute to apply (nothing was changed).");

            return self::SUCCESS;
        }

        // Write-verification: re-read after applying and confirm no non-colliding dirty market remains.
        $remaining = 0;
        foreach ($sites as $site) {
            $remaining += count(array_filter($renamer->plan($site->fresh() ?? $site), fn (array $r): bool => ! $r['collision']));
        }
        $this->info("Renamed {$grandRenamable} market(s). Remaining renamable artifacts after re-read: {$remaining}.");

        return self::SUCCESS;
    }
}
