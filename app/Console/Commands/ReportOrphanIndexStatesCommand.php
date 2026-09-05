<?php

namespace App\Console\Commands;

use App\Enums\IndexCoverageState;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Console\Command;

/**
 * Report (Indexing accuracy): ORPHANED page_index_states rows — a verdict stored at a URL that is no
 * longer the content's canonical URL. A content's URL can change (a slug edit, or the home page
 * canonicalizing /home/→/), and `IndexMetricProvider` upserts on (site, url_normalized) without pruning,
 * so the old row persists at a URL nothing re-inspects — showing a dead URL's verdict (a home page stuck
 * at excluded_redirect on /home/) and double-counting the content on the Indexing panel.
 *
 * Also classifies excluded_redirect rows, because that verdict can MASK this bug: an orphan at a
 * redirecting URL looks like a "correct exclusion" but is really a live page inspected at the wrong URL —
 * distinct from a genuine canonical redirect (the row IS the content's canonical URL and Google still
 * calls it a redirect).
 *
 * REPORT-ONLY by default; --execute prunes the orphan rows. Live-only: a row whose content is
 * soft-deleted (or gone) is itself an orphan.
 */
class ReportOrphanIndexStatesCommand extends Command
{
    protected $signature = 'launchpad:report-orphan-index-states {--execute : Delete the orphaned rows (default is report-only)}';

    protected $description = 'Report (read-only) page_index_states rows stored at a non-canonical URL; --execute prunes them.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        $rows = PageIndexState::withoutGlobalScope(SiteScope::class)
            ->whereNotNull('content_id')
            ->get(['id', 'site_id', 'content_id', 'url', 'url_normalized', 'index_verdict']);

        $contents = Content::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $rows->pluck('content_id')->unique()->all())
            ->get(['id', 'site_id', 'slug', 'page_type'])
            ->keyBy('id');
        $sites = Site::withoutGlobalScope(VisibleSiteScope::class)
            ->whereIn('id', $rows->pluck('site_id')->unique()->all())
            ->get(['id', 'domain_url'])
            ->keyBy('id');

        $orphanIds = [];
        $orphanRedirects = 0;   // the masked bug: excluded_redirect at a stale URL
        $genuineRedirects = 0;  // canonical URL, Google still says redirect — a real page issue
        $contentGone = 0;

        foreach ($rows as $row) {
            $content = $contents->get($row->content_id);
            $isRedirect = $row->index_verdict === IndexCoverageState::ExcludedRedirect->value;

            if ($content === null) {
                $orphanIds[] = $row->id; // content soft-deleted / gone → the row can't be canonical
                $contentGone++;

                continue;
            }

            $canonical = UrlNormalizer::url((string) PublicUrl::forContent($sites->get($content->site_id)?->domain_url, $content));
            $isOrphan = $canonical !== '' && $canonical !== (string) $row->url_normalized;

            if ($isOrphan) {
                $orphanIds[] = $row->id;
                $orphanRedirects += $isRedirect ? 1 : 0;
            } elseif ($isRedirect) {
                $genuineRedirects++;
            }
        }

        $this->info('Live-only (a row whose content is soft-deleted counts as an orphan).'.($execute ? ' --execute: orphan rows WILL be deleted.' : ' Report-only — pass --execute to prune.'));
        $this->newLine();
        $this->warn(count($orphanIds).' orphaned row(s): '.($contentGone).' whose content is gone, '.(count($orphanIds) - $contentGone).' at a non-canonical URL.');
        $this->line("  of the orphans, {$orphanRedirects} are excluded_redirect — a live page inspected at a redirecting URL (the masked bug, e.g. a home page stuck at /home/).");
        $this->line("  {$genuineRedirects} excluded_redirect row(s) are at the content's canonical URL — genuine redirects/alternates, left as-is.");

        if ($execute && $orphanIds !== []) {
            $deleted = 0;
            foreach (array_chunk($orphanIds, 1000) as $chunk) {
                $deleted += PageIndexState::withoutGlobalScope(SiteScope::class)->whereIn('id', $chunk)->delete();
            }
            $this->newLine();
            $this->warn("Pruned {$deleted} orphaned row(s). Each affected content re-inspects at its canonical URL on the next sync.");
        } elseif ($orphanIds !== []) {
            $this->newLine();
            $this->warn('Re-run with --execute to prune. Prune-on-sync (IndexMetricProvider) heals these going forward as each content is re-inspected.');
        }

        return self::SUCCESS;
    }
}
