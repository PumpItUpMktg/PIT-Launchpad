<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Operator\Coverage\MarketArtifactRenamer;
use Illuminate\Console\Command;

/**
 * Strip the leading numbered-list artifact ("4, Marshall" -> "Marshall") off a market and everything
 * coupled to it BY NAME — its source CoverageArea and its pinned town pages' titles — in lockstep, so the
 * rename is a no-op for the next build (see {@see MarketArtifactRenamer} for why the cascade is required).
 *
 * REPORT-ONLY by default: prints the per-market plan and writes NOTHING. Pass --execute to apply, then it
 * re-reads and reports remaining dirty markets (write-verification). Slugs are intentionally NOT touched —
 * LocationNesting recomputes each town slug from its (now-corrected) title on the next build.
 * Live-only, all tenants (or one via --site).
 */
class RenameMarketArtifactsCommand extends Command
{
    protected $signature = 'launchpad:rename-market-artifacts
        {--site= : Limit to one site id or brand name}
        {--execute : Apply the rename (default: report-only — writes nothing)}';

    protected $description = 'Strip the "N, " numbered-list artifact off markets + their CoverageArea + pinned page titles (report-only by default; --execute to apply).';

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
            ? 'EXECUTE · live-only · stripping the "N, " artifact off markets + coupled CoverageArea + page titles.'
            : 'Read-only · live-only · market-artifact rename PLAN. Nothing is changed (pass --execute to apply).');

        $grandRenamable = 0;
        $grandCollisions = 0;
        $grandPublished = 0;
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
                    $this->line("  · <comment>\"{$r['old']}\"</comment>  →  \"{$r['new']}\"  <fg=red>COLLISION</> — \"{$r['new']}\" already exists; skipped (merge by hand)");

                    continue;
                }

                $grandRenamable++;
                $area = $r['coverage_area_id'] !== null
                    ? 'CoverageArea '.($r['coverage_area_dirty'] ? 'cleaned' : 'already clean').' (matched)'
                    : 'no CoverageArea matched (market-only)';
                $pages = count($r['pages']);
                $this->line("  · <comment>\"{$r['old']}\"</comment>  →  \"{$r['new']}\"  · {$area} · {$pages} page title(s)");
                foreach ($r['pages'] as $p) {
                    $flag = $p['published'] ? ' <fg=yellow>[published — slug regenerates on next build → live URL change]</>' : '';
                    $this->line("        title \"{$p['old_title']}\"  →  \"{$p['new_title']}\"  (slug {$p['slug']}){$flag}");
                }
                $grandPublished += $r['published_pages'];
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

        if ($grandPublished > 0) {
            $this->warn("{$grandPublished} affected page(s) are PUBLISHED — their slug regenerates from the corrected title on the next build, changing a live URL. Resolve redirects (§2 PublishRedirects) before rebuilding.");
        }
        if ($grandCollisions > 0) {
            $this->warn("{$grandCollisions} market(s) skipped for a name COLLISION — the cleaned name already belongs to another market. Merge those by hand.");
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
