<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Operator\Coverage\Reachability;
use Illuminate\Console\Command;

/**
 * Why a site's pages are UNKNOWN to Google (§ Indexing): the live sitemap, Search Console's last read of
 * it, each page's live URL, and its inbound links from indexed pages — one verdict and one action per page.
 * `--fix` re-pushes the pages whose push never landed / drifted / fell out of the sitemap, resubmits the
 * sitemap once, and links the orphans from ranking pages.
 */
class CheckReachabilityCommand extends Command
{
    protected $signature = 'launchpad:check-reachability
        {--site= : Site id or brand name (required)}
        {--include-discovered : also check pages Google has discovered but not crawled}
        {--no-live : skip the per-page live permalink read (sitemap + links only)}
        {--fix : re-push / resubmit the sitemap / link orphans according to the verdicts}';

    protected $description = 'Explain why pages are unknown to Google (sitemap, live URL, links) and optionally fix it';

    public function handle(Reachability $reachability): int
    {
        $arg = $this->option('site');
        $site = is_string($arg) && $arg !== '' ? Site::withoutGlobalScopes()->where('id', $arg)->orWhere('brand_name', $arg)->first() : null;
        if ($site === null) {
            $this->error('Pass --site=<id or brand name>.');

            return self::FAILURE;
        }

        $report = $reachability->for($site, (bool) $this->option('include-discovered'), ! $this->option('no-live'));
        $sm = $report['sitemap'];
        $gsc = $report['gsc'];
        $this->info("Reachability — {$site->brand_name}");
        $this->line(sprintf('Live sitemap %s: %s', $sm['url'] ?? '(no domain)', $sm['fetched'] ? number_format($sm['urls']).' URLs' : 'NOT readable ('.($sm['error'] ?? 'unknown').')'));
        $this->line($gsc['connected']
            ? sprintf('Search Console: sitemap last submitted %s · %s URLs submitted%s', $gsc['last_submitted'] ?? 'never', number_format($gsc['submitted']), $gsc['pending'] ? ' · pending' : '')
            : 'Search Console: not connected for this site');
        if ($report['live_error'] !== null) {
            $this->warn('Live permalink read (the plugin\'s /content/diagnose) failed: '.$report['live_error'].' — "Live URL matches" falls back to the sitemap; an older companion plugin (before 0.9.48) lacks the route.');
        }
        $this->line(sprintf('Pages checked: %d', count($report['pages'])));
        foreach ($report['by_verdict'] as $verdict => $n) {
            $this->line(sprintf('  %-16s %d', $verdict, $n));
        }
        if ($report['pages'] !== []) {
            $this->table(['Page', 'Google', 'Days', 'In sitemap', 'Live URL matches', 'Serves it', 'Linked from indexed', 'Verdict'], array_map(fn (array $p): array => [
                mb_substr($p['title'], 0, 40), $p['state'], $p['days_waiting'] ?? '—',
                $p['in_sitemap'] === null ? '?' : ($p['in_sitemap'] ? 'yes' : 'NO'),
                $p['url_matches'] === null ? ($p['found_on_site'] === false ? 'NOT ON SITE' : '?') : ($p['url_matches'] ? 'yes' : 'NO'),
                $p['served'] === null ? '?' : ($p['served'] ? 'yes' : '404'),
                $p['inbound_indexed'].' of '.$p['inbound'], $p['verdict'],
            ], $report['pages']));
        }

        if ($this->option('fix')) {
            $fix = $reachability->fix($site, $report);
            $this->info(sprintf('Fix: re-pushed %d page(s) · sitemap %s · linked %d orphan(s) from ranking pages', $fix['repushed'], $fix['sitemap_resubmitted'] ? 'resubmitted' : 'not resubmitted', $fix['orphans_linked']));
        } else {
            $this->line('Dry run — add --fix to re-push, resubmit the sitemap, and link the orphans.');
        }

        return self::SUCCESS;
    }
}
