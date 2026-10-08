<?php

namespace App\Operator\Coverage;

use App\Enums\ContentStatus;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Redirects\CollisionSuffix;
use App\Publishing\Redirects\GscUrlInventory;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The "duplicate of a published page" twins — a numbered URL Google has shown (`/foo-2/`) whose base
 * (`/foo/`) is a page Launchpad publishes — settled one page at a time by asking WordPress where our
 * content actually lives, instead of assuming.
 *
 * WordPress appends `-2` when the slug we ask for is already held, and the companion plugin reclaims the
 * clean slug on every push UNLESS the holder is an unmanaged published page (a legacy post it will not
 * clobber). So a twin earning impressions means one of two very different things:
 *
 *   • OURS_AT_TWIN — WordPress serves OUR page at the numbered URL; the clean URL we store, inspect and
 *     count is someone else's page. Every verdict we hold for it describes the legacy post. The fix is
 *     ours: adopt the URL WordPress serves ({@see repoint()}), so tracking follows the real page.
 *   • The twin is NOT ours (our page sits at the clean URL): a legacy duplicate still live at the twin
 *     (TWIN_LIVE), one that already redirects (TWIN_REDIRECTS), or one that is gone and whose impressions
 *     are history (TWIN_GONE). Those belong to the legacy-twin consolidation, listed here so nothing hides.
 *
 * Evidence is live: the plugin's /content/diagnose for where our content is, a single un-followed HTTP
 * request per twin for what answers there. Report-only by default; {@see repoint()} is the only write.
 */
final class SlugCollisions
{
    public const OURS_AT_TWIN = 'ours_at_twin';

    public const TWIN_LIVE = 'twin_live';

    public const TWIN_REDIRECTS = 'twin_redirects';

    public const TWIN_GONE = 'twin_gone';

    public const UNKNOWN = 'unknown';

    public function __construct(
        private readonly GscUrlInventory $inventory,
        private readonly WordpressClientFactory $wordpress,
    ) {}

    /**
     * @return array{
     *     pages: list<array{
     *         content_id: string, title: string, slug: string, url: string, impressions: int, verdict: ?string,
     *         wp_permalink: ?string, wp_post_name: ?string, wp_slug: ?string, holder: ?array{status: string, reclaimable: bool},
     *         twins: list<array{url: string, impressions: int, verdict: ?string, status: ?int, location: ?string, answer: string}>,
     *         state: string, action: string
     *     }>,
     *     counts: array<string, int>,
     *     live_error: ?string
     * }
     */
    public function for(Site $site, bool $live = true): array
    {
        $published = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'title', 'slug', 'kind', 'page_type']);

        /** @var array<string, Content> $oursByPath */
        $oursByPath = [];
        foreach ($published as $page) {
            $url = PublicUrl::forContent($site->domain_url, $page);
            if ($url !== null) {
                $oursByPath[UrlNormalizer::path($url)] = $page;
            }
        }

        // Lifetime impressions per normalized path, and the twins grouped under the page they collide with.
        $impressions = [];
        $twinsByContent = [];
        foreach ($this->inventory->urlTotals($site) as $row) {
            $path = UrlNormalizer::path($row['url']);
            $impressions[$path] = ($impressions[$path] ?? 0) + $row['impressions'];
            if (isset($oursByPath[$path])) {
                continue;
            }
            $base = CollisionSuffix::stripPath($path);
            if ($base === null || ! isset($oursByPath[$base])) {
                continue;
            }
            $id = (string) $oursByPath[$base]->id;
            $twinsByContent[$id][$path] = ($twinsByContent[$id][$path] ?? 0) + $row['impressions'];
        }

        if ($twinsByContent === []) {
            return ['pages' => [], 'counts' => [], 'live_error' => null];
        }

        $verdicts = PageIndexState::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->pluck('index_verdict', 'url_normalized');
        $base = rtrim((string) $site->domain_url, '/');
        $liveError = null;

        $pages = [];
        $counts = [];
        foreach ($twinsByContent as $id => $twinPaths) {
            /** @var Content $page */
            $page = $published->firstWhere('id', $id);
            $url = (string) PublicUrl::forContent($site->domain_url, $page);
            $ourPath = UrlNormalizer::path($url);

            $diag = $live ? $this->diagnose($site, $page, $liveError) : null;
            $permalink = $diag !== null && is_string($diag['permalink'] ?? null) && $diag['permalink'] !== '' ? (string) $diag['permalink'] : null;
            $wpPath = $permalink !== null ? UrlNormalizer::path($permalink) : null;
            $holder = $diag !== null && is_array($diag['slug_holder'] ?? null)
                ? ['status' => (string) ($diag['slug_holder']['status'] ?? ''), 'reclaimable' => (bool) ($diag['slug_holder']['reclaimable'] ?? false)]
                : null;

            $twins = [];
            foreach ($twinPaths as $path => $count) {
                $twinUrl = $base.$path.'/';
                [$status, $location] = $live ? $this->answer($twinUrl) : [null, null];
                $twins[] = [
                    'url' => $twinUrl,
                    'impressions' => $count,
                    'verdict' => $verdicts[UrlNormalizer::url($twinUrl)] ?? null,
                    'status' => $status,
                    'location' => $location,
                    'answer' => match (true) {
                        $wpPath !== null && $wpPath === $path => 'our page',
                        $status === null => 'unknown',
                        $status >= 300 && $status < 400 => 'redirects',
                        $status === 404 || $status === 410 => 'gone',
                        $status >= 200 && $status < 300 => 'another page',
                        default => 'http '.$status,
                    },
                ];
            }

            $state = $this->state($wpPath, $ourPath, $twins);
            $counts[$state] = ($counts[$state] ?? 0) + 1;

            $pages[] = [
                'content_id' => $id,
                'title' => (string) $page->title,
                'slug' => (string) $page->slug,
                'url' => $url,
                'impressions' => $impressions[$ourPath] ?? 0,
                'verdict' => $verdicts[UrlNormalizer::url($url)] ?? null,
                'wp_permalink' => $permalink,
                'wp_post_name' => $diag !== null && is_string($diag['post_name'] ?? null) ? (string) $diag['post_name'] : null,
                'wp_slug' => $wpPath !== null ? ltrim($wpPath, '/') : null,
                'holder' => $holder,
                'twins' => $twins,
                'state' => $state,
                'action' => $this->action($state, $holder),
            ];
        }

        return ['pages' => $pages, 'counts' => $counts, 'live_error' => $liveError];
    }

    /**
     * Adopt the URL WordPress serves for every OURS_AT_TWIN page: the stored slug becomes the live
     * post_name path, and the verdict row we held for the clean URL — which describes the legacy holder,
     * not us — is dropped (the all-known capture re-inspects that URL as a discovered one). The next push
     * sends the slug WordPress already has, so nothing moves on the site.
     *
     * @param  array{pages: list<array<string, mixed>>}  $report
     * @return array{repointed: list<array{content_id: string, title: string, from: string, to: string}>, skipped: list<array{content_id: string, title: string, reason: string}>}
     */
    public function repoint(Site $site, array $report): array
    {
        $repointed = [];
        $skipped = [];

        foreach ($report['pages'] as $p) {
            if ($p['state'] !== self::OURS_AT_TWIN || ! is_string($p['wp_slug']) || $p['wp_slug'] === '') {
                continue;
            }
            $page = Content::withoutGlobalScopes()->find($p['content_id']);
            if ($page === null) {
                continue;
            }
            $taken = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('slug', $p['wp_slug'])->whereKeyNot($page->id)->exists();
            if ($taken) {
                $skipped[] = ['content_id' => (string) $page->id, 'title' => (string) $page->title, 'reason' => "another page already stores the slug {$p['wp_slug']}"];

                continue;
            }

            $from = (string) $page->slug;
            DB::transaction(function () use ($site, $page, $p): void {
                $page->forceFill(['slug' => $p['wp_slug']])->save();
                $current = UrlNormalizer::url((string) PublicUrl::forContent($site->domain_url, $page->fresh()));
                PageIndexState::withoutGlobalScope(SiteScope::class)
                    ->where('site_id', $site->id)->where('content_id', $page->id)
                    ->where('url_normalized', '!=', $current)
                    ->delete();
            });
            $repointed[] = ['content_id' => (string) $page->id, 'title' => (string) $page->title, 'from' => $from, 'to' => $p['wp_slug']];
        }

        return ['repointed' => $repointed, 'skipped' => $skipped];
    }

    /** @param  list<array{answer: string}>  $twins */
    private function state(?string $wpPath, string $ourPath, array $twins): string
    {
        if ($wpPath === null) {
            return self::UNKNOWN;
        }
        if ($wpPath !== $ourPath) {
            // WordPress serves us somewhere else. If that somewhere is one of the twins, the twin IS our page.
            foreach ($twins as $t) {
                if ($t['answer'] === 'our page') {
                    return self::OURS_AT_TWIN;
                }
            }

            return self::UNKNOWN;
        }

        $answers = array_column($twins, 'answer');

        return match (true) {
            in_array('another page', $answers, true) => self::TWIN_LIVE,
            in_array('redirects', $answers, true) => self::TWIN_REDIRECTS,
            in_array('gone', $answers, true) => self::TWIN_GONE,
            default => self::UNKNOWN,
        };
    }

    /** @param  ?array{status: string, reclaimable: bool}  $holder */
    private function action(string $state, ?array $holder): string
    {
        return match ($state) {
            self::OURS_AT_TWIN => 'Adopt the URL WordPress serves (--execute): our tracking has been reading the '
                .($holder !== null ? "legacy {$holder['status']} page" : 'other page').' that holds the clean slug.',
            self::TWIN_LIVE => 'Our page is at the clean URL; a different page answers the twin — a legacy duplicate for the consolidation (301 → the earner).',
            self::TWIN_REDIRECTS => 'Already consolidated: the twin redirects. Nothing to do.',
            self::TWIN_GONE => 'The twin is gone; its impressions are history. A 301 → our page would keep what equity remains (consolidation).',
            default => 'Could not read where our page lives — re-run with WordPress reachable.',
        };
    }

    /** @return array<string, mixed>|null */
    private function diagnose(Site $site, Content $page, ?string &$liveError): ?array
    {
        try {
            return $this->wordpress->forSite($site)->diagnoseContent((string) $page->id, (string) $page->slug);
        } catch (Throwable $e) {
            $liveError ??= $e->getMessage();
            Log::warning('SlugCollisions: live permalink read failed', ['site_id' => $site->id, 'content_id' => $page->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** One un-followed request: the status and, for a redirect, where it points. @return array{0: ?int, 1: ?string} */
    private function answer(string $url): array
    {
        try {
            $response = Http::withoutRedirecting()->timeout(10)->get($url);
            $location = $response->header('Location');

            return [$response->status(), $location !== '' ? $location : null];
        } catch (Throwable) {
            return [null, null];
        }
    }
}
