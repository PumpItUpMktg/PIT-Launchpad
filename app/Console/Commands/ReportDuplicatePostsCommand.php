<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Operator\Coverage\DuplicatePostMetrics;
use Illuminate\Console\Command;

/**
 * Report (read-only): every LIVE duplicate blog-post pair with GSC impressions + blended position on BOTH
 * sides — the post-lane twin of launchpad:report-duplicate-page-metrics. Surfaces the "…-what" / "…-what-2"
 * shape: two published posts for one story, both live, competing for the same query. See {@see DuplicatePostMetrics}.
 *
 * The member carrying the impressions is the earner (marked ← earns). The default keeper is the OLDEST post,
 * but when the numbered `-N` twin is the earner and the original is dead, age picks wrong (the Buckingham
 * lesson) — those groups are flagged ⚠ age-conflict so the resolver preserves the earner, not the elder.
 *
 * READ-ONLY, published-only, all tenants (or one via --site); --days sets the GSC window (default 28).
 * Requires GSC to have been synced (launchpad:sync-gsc) — run this where the synced rows live.
 */
class ReportDuplicatePostsCommand extends Command
{
    protected $signature = 'launchpad:report-duplicate-posts
        {--site= : Limit to one site id or brand name}
        {--days=28 : GSC window in days}';

    protected $description = 'Report (read-only) live duplicate blog posts (the -N slug family) with GSC impressions + position on both sides.';

    public function handle(DuplicatePostMetrics $metrics): int
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

        $days = max(1, (int) $this->option('days'));
        $this->info("Read-only · live duplicate blog posts with GSC impressions + blended position (last {$days}d).");

        $grandGroups = 0;
        $grandConflicts = 0;
        foreach ($sites as $site) {
            $groups = $metrics->report($site, $days);
            if ($groups === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id})");
            foreach ($groups as $g) {
                $grandGroups++;
                $conflict = $g['age_conflict'] ? ' <fg=yellow>⚠ age-conflict (earner is the -N twin, not the oldest)</>' : '';
                if ($g['age_conflict']) {
                    $grandConflicts++;
                }
                $this->line("  <comment>{$g['title']}</comment> — ".count($g['members'])." live posts:{$conflict}");
                foreach ($g['members'] as $m) {
                    $pos = $m['position'] !== null ? (string) $m['position'] : '—';
                    $earns = $m['top_impressions'] && $m['impressions'] > 0 ? ' <fg=green>← earns</>' : '';
                    $keeper = $m['age_keeper'] ? ' <fg=cyan>[oldest]</>' : '';
                    $this->line(sprintf(
                        '      %s · impr %d · clicks %d · pos %s · index: %s · published %s%s%s',
                        $m['url'] ?? '(no domain)',
                        $m['impressions'],
                        $m['clicks'],
                        $pos,
                        $m['index'],
                        $m['published_at'] ?? '—',
                        $keeper,
                        $earns,
                    ));
                }
            }
        }

        $this->newLine();
        if ($grandGroups === 0) {
            $this->info('No live duplicate blog posts found.');

            return self::SUCCESS;
        }

        $this->line("<info>{$grandGroups}</info> live duplicate post group(s)"
            .($grandConflicts > 0 ? ", <fg=yellow>{$grandConflicts}</> where the earner is NOT the oldest (age rule would pick wrong)" : '')
            .'. Per group: keep the earner, 301 the other → it. Then close the source: route every intake lane through '
            .'near-dup and make a ≥0.9 hit refresh the existing post instead of creating a second row.');

        return self::SUCCESS;
    }
}
