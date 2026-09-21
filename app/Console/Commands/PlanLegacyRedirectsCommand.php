<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\Redirects\LegacyRedirectPlanner;
use App\Publishing\Redirects\RedirectGuard;
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
    protected $signature = 'launchpad:plan-legacy-redirects {--site= : Site id or brand name (required)} {--apply : Persist the 301/410 redirect rows} {--force : Apply despite guard findings} {--limit=25 : How many rows to print per bucket}';

    protected $description = 'Plan old→new 301/410 redirects for a migrated site from the recovered GSC URL inventory.';

    public function handle(LegacyRedirectPlanner $planner, RedirectGuard $guard): int
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

        $findings = $guard->check($site, $plan);
        $this->report($findings, $limit);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Dry run — re-run with --apply to persist these rows.');

            return self::SUCCESS;
        }

        // A warning nobody has to read is not a guard. Applying over a finding takes an explicit --force.
        if ($findings['blocking'] && ! $this->option('force')) {
            $this->newLine();
            $this->error('Not applied — the checks above found redirects that would lose traffic.');
            $this->line('Revive the high-value families first (launchpad:revive-legacy-content --apply), which claims');
            $this->line('those URLs so the planner stops routing them. Re-run with <info>--force</info> to apply anyway.');

            return self::FAILURE;
        }

        $written = $planner->apply($site, $plan);
        $this->newLine();
        $this->info("Applied {$written} redirect row(s). Push to WordPress with the §2 redirect publish path.");

        return self::SUCCESS;
    }

    /**
     * @param  array{outranked: list<array<string, mixed>>, funnels: list<array<string, mixed>>, blocking: bool}  $findings
     */
    private function report(array $findings, int $limit): void
    {
        if (! $findings['blocking']) {
            $this->newLine();
            $this->line('  <info>Checks passed</info> — no redirect retires a page that outperforms its successor, and no');
            $this->line('  single page is being asked to absorb a section.');

            return;
        }

        if ($findings['funnels'] !== []) {
            $this->newLine();
            $this->line('  <comment>One page absorbing many:</comment> Google ranks per query, so a single successor cannot hold');
            $this->line('  the rankings of a dozen different articles however similar the slugs look.');
            foreach (array_slice($findings['funnels'], 0, $limit) as $f) {
                $this->line(sprintf('    %s  ←  %d source(s), %s impression(s)  [target earns %s today, position %s]',
                    $f['to'], $f['sources'], number_format((int) $f['impressions']),
                    number_format((int) $f['target_impressions']),
                    $f['target_position'] === null ? 'unranked' : number_format((float) $f['target_position'], 1)));
            }
        }

        if ($findings['outranked'] !== []) {
            $this->newLine();
            $this->line('  <comment>Retiring the stronger page:</comment> a 301 passes ranking signal, but a weaker successor');
            $this->line('  cannot hold a ranking it could not have earned.');
            foreach (array_slice($findings['outranked'], 0, $limit) as $o) {
                $this->line(sprintf('    %s impressions at #%s  %s  →  %s  (target: %s impressions, %s)  [%s]',
                    number_format((int) $o['impressions']),
                    $o['source_position'] === null ? '—' : number_format((float) $o['source_position'], 1),
                    $o['from'], $o['to'],
                    number_format((int) $o['target_impressions']),
                    $o['target_position'] === null ? 'never shown' : '#'.number_format((float) $o['target_position'], 1),
                    $o['reason']));
            }
        }
    }
}
