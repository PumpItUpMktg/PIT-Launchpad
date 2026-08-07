<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\Redirects\LegacyRedirectPlanner;
use Illuminate\Console\Command;

/**
 * Plan (and optionally apply) old→new 301/410 redirects for a migrated site from
 * the recovered GSC URL inventory. Diffs every URL Google has indexed against the
 * current published pages and routes each orphan to its successor (or flushes it).
 *
 * Read-only by default (prints the plan, ranked by lost impressions). `--apply`
 * upserts the Redirect rows; the §2 publish path pushes them to WordPress — this
 * command never pushes.
 */
class PlanLegacyRedirectsCommand extends Command
{
    protected $signature = 'launchpad:plan-legacy-redirects {--site= : Site id or brand name (required)} {--apply : Persist the 301/410 redirect rows} {--limit=25 : How many rows to print per bucket}';

    protected $description = 'Plan old→new 301/410 redirects for a migrated site from the recovered GSC URL inventory.';

    public function handle(LegacyRedirectPlanner $planner): int
    {
        $arg = trim((string) $this->option('site'));
        if ($arg === '') {
            $this->error('--site is required (id or brand name).');

            return self::FAILURE;
        }
        $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error("No site matches [{$arg}].");

            return self::FAILURE;
        }

        $plan = $planner->plan($site);
        $limit = max(1, (int) $this->option('limit'));

        $this->line("<info>{$site->brand_name}</info> — legacy redirect plan (from GSC inventory)");
        $this->line(sprintf(
            '  %d redirect (301), %d gone (410), %d already-live (skipped), %d unresolved.',
            count($plan['redirect']), count($plan['gone']), $plan['skipped_live'], count($plan['unresolved']),
        ));

        if ($plan['redirect'] !== []) {
            $this->newLine();
            $this->line('  <comment>301 redirects (most lost impressions first):</comment>');
            foreach (array_slice($plan['redirect'], 0, $limit) as $r) {
                $this->line(sprintf('    %6d  %s  →  %s  [%s]', $r['impressions'], $r['from'], $r['to'], $r['reason']));
            }
        }
        if ($plan['gone'] !== []) {
            $this->newLine();
            $this->line('  <comment>410 gone (out-of-footprint / no successor):</comment>');
            foreach (array_slice($plan['gone'], 0, $limit) as $r) {
                $this->line(sprintf('    %6d  %s  [%s]', $r['impressions'], $r['from'], $r['reason']));
            }
        }
        if ($plan['unresolved'] !== []) {
            $this->newLine();
            $this->line('  <comment>Unresolved — no confident target (review manually):</comment>');
            foreach (array_slice($plan['unresolved'], 0, $limit) as $r) {
                $this->line(sprintf('    %6d  %s  (top query: %s)', $r['impressions'], $r['from'], $r['top_query'] ?? '—'));
            }
        }

        if ($this->option('apply')) {
            $written = $planner->apply($site, $plan);
            $this->newLine();
            $this->info("Applied {$written} redirect row(s). Push to WordPress with the §2 redirect publish path.");
        } else {
            $this->newLine();
            $this->comment('Dry run — re-run with --apply to persist these rows.');
        }

        return self::SUCCESS;
    }
}
