<?php

namespace App\Backlinks;

use App\Integrations\Backlinks\BacklinkProvider;
use App\Integrations\Backlinks\BacklinkRow;
use App\Models\BacklinkSyncRun;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBacklink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pull a site's backlink profile from the index into `site_backlinks` (§ Links): the totals, then the
 * referring domains one strongest link each, strongest first, page by page up to the configured cap.
 * The table is replaced whole inside one transaction, so a reader never sees half a sync, and a sync that
 * fails mid-way leaves the previous rows in place and records the failure on the run.
 */
final class BacklinkSyncer
{
    public function __construct(private readonly BacklinkProvider $provider) {}

    public function run(Site $site): BacklinkSyncRun
    {
        $target = self::target((string) $site->domain_url);
        $pageSize = max(1, min(1000, (int) config('launchpad.backlinks.page_size', 1000)));
        $maxPages = max(1, (int) config('launchpad.backlinks.max_pages', 5));

        try {
            $summary = $this->provider->summary($target);

            $rows = [];
            $after = null;
            $pages = 0;
            do {
                $page = $this->provider->referringDomains($target, $pageSize, $after);
                $pages++;
                foreach ($page->rows as $row) {
                    $rows[$row->domainFrom] ??= $row; // one_per_domain already; keep the first (strongest) on a repeat
                }
                $after = $page->nextToken;
            } while ($after !== null && $page->rows !== [] && $pages < $maxPages);
        } catch (Throwable $e) {
            return BacklinkSyncRun::withoutGlobalScope(SiteScope::class)->create([
                'site_id' => $site->id, 'target' => $target, 'status' => BacklinkSyncRun::FAILED,
                'error' => Str::limit($e->getMessage(), 500), 'fetched_at' => now(),
            ]);
        }

        $now = now();
        $records = array_map(fn (BacklinkRow $r): array => [
            'id' => (string) Str::ulid(),
            'site_id' => $site->id,
            'domain_from' => $r->domainFrom,
            'url_from' => Str::limit($r->urlFrom, 2048, ''),
            'url_to' => Str::limit($r->urlTo, 2048, ''),
            'target_path' => self::path($r->urlTo),
            'domain_from_rank' => $r->domainFromRank,
            'page_from_rank' => $r->pageFromRank,
            'spam_score' => $r->spamScore,
            'dofollow' => $r->dofollow,
            'is_broken' => $r->isBroken,
            'anchor' => $r->anchor !== null ? Str::limit($r->anchor, 500, '') : null,
            'first_seen' => $r->firstSeen,
            'last_seen' => $r->lastSeen,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($rows));

        return DB::transaction(function () use ($site, $target, $summary, $records, $pages, $now): BacklinkSyncRun {
            SiteBacklink::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->delete();
            foreach (array_chunk($records, 500) as $chunk) {
                SiteBacklink::withoutGlobalScope(SiteScope::class)->insert($chunk);
            }

            return BacklinkSyncRun::withoutGlobalScope(SiteScope::class)->create([
                'site_id' => $site->id,
                'target' => $target,
                'status' => BacklinkSyncRun::COMPLETE,
                'backlinks' => $summary->backlinks,
                'referring_domains' => $summary->referringDomains,
                'broken_backlinks' => $summary->brokenBacklinks,
                'rank' => $summary->rank,
                'rows_stored' => count($records),
                'pages_fetched' => $pages,
                'fetched_at' => $now,
            ]);
        });
    }

    /** The index target for a site: its host, no scheme, no www. */
    public static function target(string $domainUrl): string
    {
        $host = parse_url(trim($domainUrl), PHP_URL_HOST) ?: trim($domainUrl);

        return strtolower((string) preg_replace('/^www\./i', '', trim((string) $host, '/')));
    }

    /** The normalized path a link points at: leading slash, no trailing slash, "/" for the root. */
    public static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        $trimmed = trim(strtolower($path), '/');

        return $trimmed === '' ? '/' : '/'.$trimmed;
    }
}
