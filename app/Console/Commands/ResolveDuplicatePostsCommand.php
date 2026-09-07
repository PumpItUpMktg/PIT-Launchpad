<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Operator\Coverage\DuplicatePostResolver;
use Illuminate\Console\Command;

/**
 * Resolve LIVE duplicate blog posts: 301 the redundant post → the keeper, verify serving, then remove it —
 * the post-lane twin of launchpad:resolve-live-duplicates. See {@see DuplicatePostResolver}.
 *
 * KEEPER = the earner (most impressions); on an all-zero group the clean (non-numbered) slug. A group the
 * rule can't settle — a tie at the top impression count (`ambiguous-earner`), or all-zero with no single
 * clean slug (`no-clean-keeper`) — is REPORTED, never resolved. Settle one with --keep=<content_id>: a
 * PER-GROUP override that pins that group's keeper (repeatable; one id per group). The report prints each
 * member's content id so you know what to pass.
 *
 * REPORT-ONLY by default (writes nothing). --execute applies, per loser in order: write the redirect → push
 * to WordPress → VERIFY the from_url answers a 3xx → only then remove the post. A loser whose redirect can't
 * be confirmed serving is left live and reported (never a removal before a confirmed 301). All tenants, or
 * --site; --days sets the GSC window (default 28).
 */
class ResolveDuplicatePostsCommand extends Command
{
    protected $signature = 'launchpad:resolve-duplicate-posts
        {--site= : Limit to one site id or brand name}
        {--days=28 : GSC window in days}
        {--keep=* : Pin a keeper by content id (per group; repeatable) — settles an ambiguous group}
        {--execute : Apply (default: report-only — writes nothing)}';

    protected $description = 'Resolve live duplicate blog posts: 301 the redundant one → the earner, verify serving, then remove it (report-only by default; --execute to apply).';

    public function handle(DuplicatePostResolver $resolver): int
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
        /** @var list<string> $keep */
        $keep = array_values(array_filter(array_map('strval', (array) $this->option('keep'))));
        $execute = (bool) $this->option('execute');

        $this->info($execute
            ? 'EXECUTE · resolving live duplicate blog posts (write redirect → push → verify → remove).'
            : 'Read-only · live duplicate blog-post resolution PLAN. Nothing is changed (pass --execute to apply).');

        $seenKeep = [];   // which --keep ids actually matched a member (to warn on typos)
        $purge = [];      // full URLs actually removed — the operator must purge these at the CDN
        $grandRedirects = 0;
        $grandBlocked = 0;
        foreach ($sites as $site) {
            $plan = $resolver->plan($site, $days, $keep);
            if ($plan === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id})");
            foreach ($plan as $g) {
                foreach ($g['members'] as $m) {
                    if (in_array($m['content_id'], $keep, true)) {
                        $seenKeep[$m['content_id']] = true;
                    }
                }

                if (! $g['resolvable']) {
                    $grandBlocked++;
                    $this->line("  · <fg=yellow>BLOCKED</> ({$g['reason']}) <comment>{$g['title']}</comment> — decide by hand, then pass --keep=<id>:");
                    foreach ($g['members'] as $m) {
                        $pos = $m['position'] !== null ? (string) $m['position'] : '—';
                        $this->line("      {$m['content_id']}  {$m['slug']}  · impr {$m['impressions']} · pos {$pos}");
                    }

                    continue;
                }

                $keeper = $g['keeper'];
                $tag = $g['reason'] === 'operator-override' ? '<fg=cyan>[--keep]</>' : "[{$g['reason']}]";
                $this->line("  · <comment>{$g['title']}</comment> — keep {$tag} <info>{$keeper['path']}</info> (impr {$keeper['impressions']}):");
                foreach ($g['losers'] as $loser) {
                    $grandRedirects++;
                    $this->line("      301 {$loser['from']} → {$loser['to']} (impr {$loser['impressions']}), then remove the post.");
                }
            }

            if ($execute) {
                $base = rtrim((string) $site->domain_url, '/');
                foreach ($resolver->apply($site, $days, $keep) as $r) {
                    $icon = $r['removed'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
                    $this->line("      {$icon} {$r['from']} → {$r['to']} — {$r['note']}");
                    if ($r['removed'] && $base !== '') {
                        $purge[] = $base.'/'.trim($r['from'], '/').'/';
                    }
                }
            }
        }

        // Surface any --keep id that matched no post (a typo would otherwise silently no-op).
        foreach ($keep as $id) {
            if (! isset($seenKeep[$id])) {
                $this->warn("--keep={$id} matched no duplicate-post member (ignored).");
            }
        }

        $this->newLine();
        if ($grandRedirects === 0 && $grandBlocked === 0) {
            $this->info('No live duplicate blog posts found.');

            return self::SUCCESS;
        }
        if ($grandBlocked > 0) {
            $this->warn("{$grandBlocked} group(s) left for a human (ambiguous earner or no clean keeper) — pin one with --keep=<content id>.");
        }

        if (! $execute) {
            $this->info("{$grandRedirects} redirect(s) would be written + the redundant post(s) removed. Re-run with --execute to apply (nothing was changed).");

            return self::SUCCESS;
        }

        // CDN purge reminder: verification confirms the 301 at ORIGIN; a CDN edge may keep serving the old
        // page (200) from cache until purged, so removal ≠ "visitors see the redirect" yet. Name the URLs.
        if ($purge !== []) {
            $this->newLine();
            $this->warn('Verified at ORIGIN. If a CDN (e.g. Cloudflare) fronts the site, PURGE these '.count($purge).' URL(s) — the edge may serve the old page until then:');
            foreach ($purge as $url) {
                $this->line("    • {$url}");
            }
        }

        // Write-verification: re-read and confirm no resolvable group remains unresolved.
        $remaining = 0;
        foreach ($sites as $site) {
            $remaining += count(array_filter($resolver->plan($site->fresh() ?? $site, $days, $keep), fn (array $r): bool => $r['resolvable']));
        }
        $this->info("Applied. Remaining resolvable duplicate-post group(s) after re-read: {$remaining}.");

        return self::SUCCESS;
    }
}
