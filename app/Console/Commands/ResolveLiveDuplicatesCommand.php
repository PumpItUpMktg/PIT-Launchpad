<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Operator\Coverage\LiveDuplicateResolver;
use Illuminate\Console\Command;

/**
 * Resolve LIVE duplicate location pages: 301 the redundant page → the keeper, then remove it. Covers the
 * landing↔town pairs ("/city/city/" → "/city/") and the town↔town duplicates ("…-2" → the clean slug) —
 * see {@see LiveDuplicateResolver}. Keeper is by ROLE (landing, or the clean-slug town), never by traffic.
 *
 * REPORT-ONLY by default — prints the full keep/redirect/remove plan and writes NOTHING. Pass --execute to
 * apply, which per loser does, in order: write the redirect → push to WordPress → VERIFY it is serving →
 * only then remove the page (a removal never precedes a confirmed redirect, so an indexed URL never 404s
 * in the gap). A pair whose redirect can't be confirmed is left live and reported. All tenants, or --site.
 */
class ResolveLiveDuplicatesCommand extends Command
{
    protected $signature = 'launchpad:resolve-live-duplicates
        {--site= : Limit to one site id or brand name}
        {--execute : Apply (default: report-only — writes nothing)}';

    protected $description = 'Resolve live duplicate location pages: 301 the redundant one → the keeper, verify serving, then remove it (report-only by default; --execute to apply).';

    public function handle(LiveDuplicateResolver $resolver): int
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
            ? 'EXECUTE · resolving live duplicate location pages (write redirect → push → verify → remove).'
            : 'Read-only · live duplicate resolution PLAN. Nothing is changed (pass --execute to apply).');

        $grandRedirects = 0;
        $grandAmbiguous = 0;
        foreach ($sites as $site) {
            $plan = $resolver->plan($site);
            if ($plan === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id})");
            foreach ($plan as $g) {
                if ($g['ambiguous']) {
                    $grandAmbiguous++;
                    $this->line("  · <fg=yellow>AMBIGUOUS</> {$g['town']} — no clear keeper among [".implode(' | ', $g['names']).']; decide by hand.');

                    continue;
                }

                $keeper = $g['keeper'];
                $this->line("  · <comment>{$g['town']}</comment> — keep [{$keeper['role']}] <info>{$keeper['path']}</info>:");
                foreach ($g['losers'] as $loser) {
                    $grandRedirects++;
                    $this->line("      301 [{$loser['role']}] {$loser['from']} → {$loser['to']}, then remove the page.");
                }
            }

            if ($execute) {
                foreach ($resolver->apply($site) as $r) {
                    $icon = $r['removed'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
                    $this->line("      {$icon} {$r['from']} → {$r['to']} — {$r['note']}");
                }
            }
        }

        $this->newLine();
        if ($grandRedirects === 0 && $grandAmbiguous === 0) {
            $this->info('No live duplicate location pairs found.');

            return self::SUCCESS;
        }
        if ($grandAmbiguous > 0) {
            $this->warn("{$grandAmbiguous} ambiguous group(s) left for a human — no clear keeper.");
        }

        if (! $execute) {
            $this->info("{$grandRedirects} redirect(s) across all tenants would be written + the redundant page(s) removed. Re-run with --execute to apply (nothing was changed).");

            return self::SUCCESS;
        }

        // Write-verification: re-read and confirm no non-ambiguous duplicate remains.
        $remaining = 0;
        foreach ($sites as $site) {
            $remaining += count(array_filter($resolver->plan($site->fresh() ?? $site), fn (array $r): bool => ! $r['ambiguous']));
        }
        $this->info("Applied. Remaining resolvable duplicate group(s) after re-read: {$remaining}.");

        return self::SUCCESS;
    }
}
