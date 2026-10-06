<?php

namespace App\Operator\Coverage;

use App\Enums\IndexCoverageState;
use App\Integrations\SearchConsole\SitemapSubmitter;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Links\IndexBooster;
use App\Publishing\Links\InternalLinkGraph;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Why a published page is UNKNOWN to Google — the reachability check (§ Indexing). "Unknown" means Google
 * has never met the URL through the sitemap or a link, so the question is never the content; it is one
 * of these, checked in order:
 *
 *  1. missing_on_site  — the live site has no post carrying this page (the push never landed) → re-push.
 *  2. url_mismatch     — the URL we inspect is not the URL the site serves (slug drift) → re-push.
 *  3. not_in_sitemap   — the live sitemap does not list it (the post lost its Launchpad marker) → re-push.
 *  4. not_served       — WordPress prints the URL but answers it with a 404 (a broken parent chain) → re-push the hub, then the page.
 *  5. sitemap_stale    — listed, but Search Console last read the sitemap before the page existed → resubmit.
 *  5. orphan           — no indexed page links to it → link it from a ranking page.
 *  6. reachable        — in the sitemap, submitted, linked: Google simply has not come yet → request indexing.
 *
 * The live sitemap is fetched once per site (cached), the live permalink read per page through the
 * companion plugin's diagnose endpoint (bounded), inbound links from the internal link graph.
 */
final class Reachability
{
    public const MISSING_ON_SITE = 'missing_on_site';

    public const URL_MISMATCH = 'url_mismatch';

    public const NOT_IN_SITEMAP = 'not_in_sitemap';

    /** WordPress prints this URL but serves a 404 for it — a broken parent chain; re-push the hub, then the page. */
    public const NOT_SERVED = 'not_served';

    public const SITEMAP_STALE = 'sitemap_stale';

    public const ORPHAN = 'orphan';

    public const REACHABLE = 'reachable';

    private const DIAGNOSE_LIMIT = 40;

    public function __construct(
        private readonly IndexWatchlist $watchlist,
        private readonly InternalLinkGraph $graph,
        private readonly SitemapSubmitter $sitemaps,
        private readonly WordpressClientFactory $wordpress,
        private readonly IndexBooster $booster,
    ) {}

    /**
     * @return array{
     *     sitemap: array{url: ?string, fetched: bool, urls: int, error: ?string},
     *     gsc: array{connected: bool, last_submitted: ?string, submitted: int, pending: bool},
     *     live_error: ?string,
     *     pages: list<array{content_id: string, title: string, url: ?string, state: string, published_at: ?string, days_waiting: ?int, in_sitemap: ?bool, live_permalink: ?string, url_matches: ?bool, served: ?bool, found_on_site: ?bool, inbound: int, inbound_indexed: int, indexnow_at: ?string, verdict: string, action: string}>,
     *     by_verdict: array<string, int>
     * }
     */
    public function for(Site $site, bool $includeDiscovered = false, bool $diagnoseLive = true): array
    {
        $states = [IndexCoverageState::Unknown->value];
        if ($includeDiscovered) {
            $states[] = IndexCoverageState::DiscoveredNotIndexed->value;
        }
        $rows = array_values(array_filter(
            $this->watchlist->for($site)['rows'],
            fn (array $r): bool => $r['state'] !== 'indexed' && in_array($r['verdict'], $states, true),
        ));

        $sitemap = $this->liveSitemap($site);
        $gsc = $this->gscStatus($site);
        $graph = $this->graph->build($site);
        $indexed = array_fill_keys(PageIndexState::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('index_verdict', 'PASS')->whereNotNull('content_id')
            ->pluck('content_id')->map(fn ($id): string => (string) $id)->all(), true);
        $pages = Content::withoutGlobalScope(SiteScope::class)->whereKey(array_column($rows, 'content_id'))->get()->keyBy('id');

        $out = [];
        $byVerdict = [];
        $diagnosed = 0;
        foreach ($rows as $row) {
            $page = $pages->get($row['content_id']);
            $url = $row['url'];
            $inSitemap = $sitemap['fetched'] ? isset($sitemap['paths'][self::normalize((string) $url)]) : null;

            $live = null;
            if ($diagnoseLive && $page !== null && $diagnosed < self::DIAGNOSE_LIMIT) {
                $live = $this->diagnose($site, $page);
                $diagnosed++;
            }
            $found = $live === null ? null : (bool) ($live['found'] ?? false);
            $permalink = $live !== null && is_string($live['permalink'] ?? null) ? (string) $live['permalink'] : null;
            if ($permalink === null && $url !== null && $sitemap['fetched']) {
                $twin = self::sitemapTwin($url, $sitemap['paths']);
                if ($twin !== null) {
                    $permalink = rtrim((string) $site->domain_url, '/').'/'.$twin.'/';   // the sitemap's own entry: the URL the site serves
                }
            }
            $matches = $permalink === null || $url === null ? null : self::normalize($permalink) === self::normalize($url);
            $served = $live === null || ! array_key_exists('permalink_resolves', $live) ? null : (bool) $live['permalink_resolves'];

            $inboundIds = $graph->inbound($row['content_id']);
            $inboundIndexed = count(array_filter($inboundIds, fn (string $id): bool => isset($indexed[$id])));

            $lastSubmitted = $gsc['last_submitted'] !== null ? Carbon::parse($gsc['last_submitted']) : null;
            $publishedAt = $row['published_at'] !== null ? Carbon::parse($row['published_at'])->startOfDay() : null;
            $sitemapStale = $gsc['connected'] && $publishedAt !== null && ($lastSubmitted === null || $lastSubmitted->lt($publishedAt));

            [$verdict, $action] = match (true) {
                $found === false => [self::MISSING_ON_SITE, 'The live site has no post carrying this page — the push never landed. Re-push it.'],
                $matches === false => [self::URL_MISMATCH, "We inspect {$url} but the site serves {$permalink} — the slug drifted. Re-push it so the URLs agree."],
                $served === false => [self::NOT_SERVED, 'WordPress prints this URL but answers it with a 404 — the page\'s parent chain is broken on the site (the hub it nests under is missing, trashed, or a different post type). Re-push the hub page, then this page.'],
                $inSitemap === false => [self::NOT_IN_SITEMAP, 'The live sitemap does not list it — the WordPress post lost its Launchpad marker. Re-push it.'],
                $sitemapStale => [self::SITEMAP_STALE, 'Listed in the sitemap, but Search Console last read the sitemap before this page existed. Resubmit the sitemap.'],
                $inboundIndexed === 0 => [self::ORPHAN, 'In the sitemap, but no indexed page links to it — link it from a ranking page (the index booster) so Google follows a path it already crawls.'],
                default => [self::REACHABLE, 'In the sitemap, submitted, and linked from an indexed page — Google has not come yet. Request indexing for it in Search Console.'],
            };

            $out[] = [
                'content_id' => $row['content_id'],
                'title' => $row['title'],
                'url' => $url,
                'state' => (string) $row['verdict'],
                'published_at' => $row['published_at'],
                'days_waiting' => $row['days_waiting'],
                'in_sitemap' => $inSitemap,
                'live_permalink' => $permalink,
                'url_matches' => $matches,
                'served' => $served,
                'found_on_site' => $found,
                'inbound' => count($inboundIds),
                'inbound_indexed' => $inboundIndexed,
                'indexnow_at' => $row['indexnow_at'],
                'verdict' => $verdict,
                'action' => $action,
            ];
            $byVerdict[$verdict] = ($byVerdict[$verdict] ?? 0) + 1;
        }
        ksort($byVerdict);

        return [
            'sitemap' => ['url' => $sitemap['url'], 'fetched' => $sitemap['fetched'], 'urls' => count($sitemap['paths']), 'error' => $sitemap['error']],
            'gsc' => $gsc,
            'live_error' => $this->liveError,
            'pages' => $out,
            'by_verdict' => $byVerdict,
        ];
    }

    /**
     * Act on a report: re-push the pages whose push never landed / drifted / fell out of the sitemap,
     * resubmit the sitemap once if it is stale, and link the orphans from ranking pages.
     *
     * @param  array{pages: list<array<string, mixed>>}  $report
     * @return array{repushed: int, sitemap_resubmitted: bool, orphans_linked: int}
     */
    public function fix(Site $site, array $report): array
    {
        $repushed = 0;
        $orphans = [];
        $stale = false;
        $hubsPushed = [];
        foreach ($report['pages'] as $p) {
            if ($p['verdict'] === self::NOT_SERVED) {
                $page = Content::withoutGlobalScope(SiteScope::class)->find($p['content_id']);
                $hub = $page?->parent_content_id !== null ? (string) $page->parent_content_id : null;
                if ($hub !== null && ! isset($hubsPushed[$hub])) {
                    PublishContent::dispatch($hub);
                    $hubsPushed[$hub] = true;
                    $repushed++;
                }
                PublishContent::dispatch((string) $p['content_id'])->delay(Carbon::now()->addSeconds(30));
                $repushed++;

                continue;
            }
            if (in_array($p['verdict'], [self::MISSING_ON_SITE, self::URL_MISMATCH, self::NOT_IN_SITEMAP], true)) {
                PublishContent::dispatch((string) $p['content_id']);
                $repushed++;
            } elseif ($p['verdict'] === self::SITEMAP_STALE) {
                $stale = true;
            } elseif ($p['verdict'] === self::ORPHAN) {
                $orphans[] = (string) $p['content_id'];
            }
        }
        $resubmitted = false;
        if ($stale || $repushed > 0) {
            try {
                $resubmitted = (bool) $this->sitemaps->submit($site)['ok'];
            } catch (Throwable) {
                $resubmitted = false;   // Search Console not connected / unreachable: the re-pushes still stand
            }
        }
        $linked = 0;
        if ($orphans !== []) {
            $targets = Content::withoutGlobalScope(SiteScope::class)->whereKey($orphans)->get();
            $linked = count($this->booster->boostTargets($site, $targets)['details']);
        }

        return ['repushed' => $repushed, 'sitemap_resubmitted' => $resubmitted, 'orphans_linked' => $linked];
    }

    /**
     * The live content sitemap's paths (normalized), cached half an hour.
     *
     * @return array{url: ?string, fetched: bool, paths: array<string, true>, error: ?string}
     */
    private function liveSitemap(Site $site): array
    {
        $domain = is_string($site->domain_url) ? rtrim(trim($site->domain_url), '/') : '';
        if ($domain === '') {
            return ['url' => null, 'fetched' => false, 'paths' => [], 'error' => 'no domain'];
        }
        $url = $domain.'/sitemap-content.xml';

        return Cache::remember('reachability:sitemap:'.$site->id, 1800, function () use ($url): array {
            try {
                $response = Http::timeout(15)->get($url);
                if (! $response->successful()) {
                    return ['url' => $url, 'fetched' => false, 'paths' => [], 'error' => 'HTTP '.$response->status()];
                }
                preg_match_all('#<loc>\s*(.*?)\s*</loc>#i', $response->body(), $m);
                $paths = [];
                foreach ($m[1] as $loc) {
                    $paths[self::normalize(html_entity_decode((string) $loc))] = true;
                }

                return ['url' => $url, 'fetched' => true, 'paths' => $paths, 'error' => null];
            } catch (Throwable $e) {
                return ['url' => $url, 'fetched' => false, 'paths' => [], 'error' => $e->getMessage()];
            }
        });
    }

    /** @return array{connected: bool, last_submitted: ?string, submitted: int, pending: bool} */
    private function gscStatus(Site $site): array
    {
        try {
            $status = $this->sitemaps->status($site);
        } catch (Throwable) {
            return ['connected' => false, 'last_submitted' => null, 'submitted' => 0, 'pending' => false];
        }
        $last = null;
        foreach ($status['sitemaps'] as $entry) {
            if (($entry['last_submitted'] ?? null) !== null && ($last === null || $entry['last_submitted'] > $last)) {
                $last = (string) $entry['last_submitted'];
            }
        }

        return ['connected' => (bool) $status['connected'], 'last_submitted' => $last, 'submitted' => (int) $status['submitted'], 'pending' => (bool) $status['pending']];
    }

    /** The first reason a live permalink read failed this run (null when every read answered). */
    private ?string $liveError = null;

    /** @return array<string, mixed>|null */
    private function diagnose(Site $site, Content $page): ?array
    {
        try {
            return $this->wordpress->forSite($site)->diagnoseContent((string) $page->id, (string) $page->slug);
        } catch (Throwable $e) {
            $this->liveError ??= $e->getMessage();
            Log::warning('Reachability: live permalink read failed', ['site_id' => $site->id, 'content_id' => $page->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * When the live read is unavailable, the sitemap still tells us the URL the site serves: a sitemap entry
     * whose last path segment is this page's last slug segment but whose full path differs is the page at a
     * different nesting (a hub that was never pushed, or was taken down, so WordPress serves the town flat).
     *
     * @param  array<string, true>  $sitemapPaths  normalized
     */
    private static function sitemapTwin(string $ourUrl, array $sitemapPaths): ?string
    {
        $ours = self::normalize($ourUrl);
        $last = substr($ours, (int) strrpos('/'.$ours, '/'));
        if ($last === '' || isset($sitemapPaths[$ours])) {
            return null;
        }
        foreach (array_keys($sitemapPaths) as $path) {
            $segment = substr($path, (int) strrpos('/'.$path, '/'));
            if ($segment === $last) {
                return $path;
            }
        }

        return null;
    }

    /** Lower-cased path without the scheme, host, or edge slashes — so "/a/b/" and "https://x/A/B" agree. */
    private static function normalize(string $url): string
    {
        $path = parse_url(trim($url), PHP_URL_PATH);
        $path = is_string($path) ? $path : $url;

        return mb_strtolower(trim($path, '/'));
    }
}
