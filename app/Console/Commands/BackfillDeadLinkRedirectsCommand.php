<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Publishing\Links\DeadLinkBackfill;
use Illuminate\Console\Command;

/**
 * Backfill 301s for internal links already baked at dead paths (the ones the published-only relatedLinks
 * filter now prevents going forward). See {@see DeadLinkBackfill}. REPORT-ONLY by default: it prints the
 * confidently-resolvable dead paths (old → live target, with the rule) and the UNRESOLVABLE ones — writing
 * nothing until --execute.
 *
 * Unresolvable = a dead path with no clean numbered-sibling and no unique live last-segment match: a
 * held-market / removed-duplicate target that shouldn't exist. Those are REPORTED, never redirected — the
 * fix is removing the href from the copy, not pointing it at a wrong page. Live-only, all tenants or --site.
 */
class BackfillDeadLinkRedirectsCommand extends Command
{
    protected $signature = 'launchpad:backfill-dead-link-redirects
        {--site= : Limit to one site id or brand name}
        {--execute : Write the redirects (default: report-only — writes nothing)}';

    protected $description = 'Backfill 301s for already-baked dead internal links (report-only by default; --execute to write). Unresolvable targets are reported, never redirected.';

    public function handle(DeadLinkBackfill $backfill): int
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
            ? 'EXECUTE · writing 301s for resolvable dead internal links.'
            : 'Read-only · dead-link redirect backfill PLAN. Nothing is changed (pass --execute to write).');

        $grandResolvable = 0;
        $grandUnresolvable = 0;
        $grandUnresolvablePages = 0;
        foreach ($sites as $site) {
            $plan = $backfill->plan($site);
            if ($plan['resolvable'] === [] && $plan['unresolvable'] === []) {
                continue;
            }
            $grandUnresolvablePages += $plan['unresolvable_pages'];

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id})");

            if ($plan['resolvable'] !== []) {
                $this->line('  <comment>Resolvable</comment> (301 old → live target):');
                foreach ($plan['resolvable'] as $r) {
                    $grandResolvable++;
                    $this->line("      {$r['from']}  →  {$r['to']}   [{$r['rule']}, {$r['count']} link(s)]");
                }
            }

            if ($plan['unresolvable'] !== []) {
                $this->line("  <fg=yellow>Unresolvable</> — no live target (held/removed-duplicate page), carried by {$plan['unresolvable_pages']} published page(s):");
                foreach ($plan['unresolvable'] as $u) {
                    $grandUnresolvable++;
                    $this->line("      {$u['from']}   [{$u['count']} link(s)]");
                }
            }

            if ($execute) {
                $backfill->apply($site);
            }
        }

        $this->newLine();
        if ($grandResolvable === 0 && $grandUnresolvable === 0) {
            $this->info('No dead internal links to backfill.');

            return self::SUCCESS;
        }

        if ($grandUnresolvable > 0) {
            $this->warn("{$grandUnresolvable} dead path(s) have no live target — reported, not redirected. They are carried by {$grandUnresolvablePages} published page(s); removing the hrefs repushes those pages. Their target is a held or removed duplicate — held targets self-heal once that market publishes/renames, so removing now is optional.");
        }

        if (! $execute) {
            $this->info("{$grandResolvable} dead path(s) would get a 301 to a live target. Re-run with --execute to write (nothing was changed).");

            return self::SUCCESS;
        }

        // Write-verification: re-read; the resolvable set should now be empty (each has a redirect).
        $remaining = 0;
        foreach ($sites as $site) {
            $remaining += count($backfill->plan($site->fresh() ?? $site)['resolvable']);
        }
        $this->info("Wrote {$grandResolvable} redirect(s). Resolvable dead paths remaining after re-read: {$remaining}.");

        return self::SUCCESS;
    }
}
