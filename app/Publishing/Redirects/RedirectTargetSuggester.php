<?php

namespace App\Publishing\Redirects;

use App\Console\Commands\FixRedirectCommand;
use App\Enums\ContentStatus;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\DB;

/**
 * Where a legacy URL should actually point — ranked by evidence, for the URLs the planner could not route
 * confidently.
 *
 * {@see LegacyRedirectPlanner} routes by resemblance and leaves anything unconfident alone, which is the
 * right default and also a dead end: `/services/sewage-pump-services/sewage-pump-replacement` earns 33,487
 * impressions, is held out of revival as a service URL, and has no proposed redirect. Nothing will ever
 * pick it up on its own.
 *
 * The strongest signal is one the cascade never uses: **does a live page already rank for the query this
 * URL ranks for?** A page Google is already showing for "sewage ejector pump replacement" can hold that
 * ranking; a page that merely has similar words in its slug is a guess. Slug overlap is kept as a
 * secondary signal and reported separately, so a suggestion made on resemblance alone is visibly weaker
 * than one made on a shared ranking.
 *
 * When NOTHING scores, that is the answer rather than a failure: there is no page serving that intent, so
 * the fix is to build one, not to redirect 33,000 impressions at whatever is nearest.
 *
 * Read-only. Writing the redirect is {@see FixRedirectCommand}, deliberately a
 * separate, explicit step.
 */
class RedirectTargetSuggester
{
    public function __construct(
        private readonly GscUrlInventory $inventory,
        private readonly RevivalEligibility $eligibility,
    ) {}

    /**
     * A candidate "already ranks" for the query only when it earns a real share of what the source earns.
     * The homepage picks up a stray impression on nearly every query a site is known for — on Sump Pump
     * Gurus, one impression was enough to beat every topical candidate and get a write line. A share this
     * small is noise, not evidence the page can hold the ranking.
     */
    private const MIN_SHARED_FRACTION = 0.02;

    private const MIN_SHARED_IMPRESSIONS = 10;

    /** Below this, a resemblance-only candidate is not a candidate. */
    private const MIN_OVERLAP = 0.25;

    /**
     * Ranked candidate targets for one legacy path, best first.
     *
     * `kind` is what the source URL IS ({@see RevivalEligibility}). A core page or a brand-query URL gets no
     * candidates at all: it is a live page someone is searching for by name, and a redirect would retire it.
     * `strong` is whether the best candidate earned its place on a shared ranking rather than on resemblance —
     * only a strong suggestion is safe to write without a human weighing it first.
     *
     * @return array{
     *     from: string, kind: string, top_query: ?string, impressions: int, strong: bool,
     *     candidates: list<array{path: string, title: string, shares_query: int, overlap: float, impressions: int}>
     * }
     */
    public function for(Site $site, string $from, int $limit = 8): array
    {
        $fromPath = UrlNormalizer::path($from);
        $rawQuery = $this->topQueryFor($site, $fromPath);
        // A site: or inurl: query is an SEO's diagnostic, not demand — it says nothing about where the
        // traffic should go, and every page on the site "ranks" for it.
        $topQuery = $rawQuery !== null && preg_match('/\b(site|inurl|intitle|cache):/i', $rawQuery) ? null : $rawQuery;
        $sourceImpressions = $this->impressionsFor($site, $fromPath);
        $kind = $this->eligibility->classify($site, $fromPath, $rawQuery);

        // Live pages people search for by name. There is nothing to route; the URL is the destination.
        if (in_array($kind, ['core_page', 'brand_query'], true)) {
            return ['from' => $fromPath, 'kind' => $kind, 'top_query' => $rawQuery, 'impressions' => $sourceImpressions,
                'strong' => false, 'candidates' => []];
        }

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'title', 'slug', 'kind', 'page_type']);

        $sharing = $topQuery === null ? [] : $this->pagesRankingFor($site, $topQuery);
        $earning = $this->impressionsByPath($site);
        $sourceTokens = $this->tokens($fromPath);

        $candidates = [];
        foreach ($pages as $page) {
            $url = PublicUrl::forContent($site->domain_url, $page);
            if ($url === null) {
                continue;
            }
            $path = UrlNormalizer::path($url);
            if ($path === $fromPath || $path === '/') {
                continue;   // never the homepage: a content URL 301'd to / reads as a soft 404
            }

            $shared = $sharing[$path] ?? 0;
            // Only a REAL share of the source's traffic counts as "already ranks for it".
            $shares = $shared >= max(self::MIN_SHARED_IMPRESSIONS, (int) ($sourceImpressions * self::MIN_SHARED_FRACTION))
                ? $shared
                : 0;
            $overlap = $this->jaccard($sourceTokens, $this->tokens($path));
            if ($shares === 0 && $overlap < self::MIN_OVERLAP) {
                continue;   // neither a shared ranking nor a recognisable name — not a candidate at all
            }

            $candidates[] = [
                'path' => $path,
                'title' => (string) $page->title,
                'shares_query' => $shares,
                'overlap' => round($overlap, 2),
                'impressions' => $earning[$path] ?? 0,
            ];
        }

        // A shared ranking beats resemblance, always; then the stronger page.
        usort($candidates, fn (array $a, array $b): int => [$b['shares_query'], $b['overlap'], $b['impressions']]
            <=> [$a['shares_query'], $a['overlap'], $a['impressions']]);

        $top = array_slice($candidates, 0, max(1, $limit));

        return [
            'from' => $fromPath,
            'kind' => $kind,
            'top_query' => $topQuery,
            'impressions' => $sourceImpressions,
            'strong' => $top !== [] && $top[0]['shares_query'] > 0,
            'candidates' => $top,
        ];
    }

    /**
     * The unresolved legacy URLs that are NOT articles — old service paths and town slugs — biggest first.
     *
     * Articles are deliberately left out: an unresolved article belongs in revival, where it is rewritten
     * and 301'd onto its replacement. Offering it a redirect here would route it onto whatever page is
     * nearest and lose the ranking the article held. On Sump Pump Gurus the first sweep listed eighty of
     * them, most released back to unresolved when their revival candidates were deleted. `$articles`
     * includes them anyway for the case where that is what the operator wants to see.
     *
     * @return list<array{from: string, impressions: int, kind: string}>
     */
    public function unrouted(Site $site, int $minImpressions = 2500, bool $articles = false): array
    {
        $plan = $this->planFor($site);
        $out = [];
        foreach ($plan['unresolved'] as $row) {
            if ((int) $row['impressions'] < $minImpressions) {
                continue;
            }
            $kind = $this->eligibility->classify($site, (string) $row['from'], $row['top_query']);
            if (! $articles && $kind === RevivalEligibility::ARTICLE) {
                continue;
            }
            $out[] = ['from' => (string) $row['from'], 'impressions' => (int) $row['impressions'], 'kind' => $kind];
        }

        return $out;
    }

    /** @return array{unresolved: list<array{from: string, impressions: int, top_query: ?string}>} */
    private function planFor(Site $site): array
    {
        /** @var array{unresolved: list<array{from: string, impressions: int, top_query: ?string}>} $plan */
        $plan = app(LegacyRedirectPlanner::class)->plan($site);

        return $plan;
    }

    /** Impressions each live path earns, for ranking candidates by strength. @return array<string, int> */
    private function impressionsByPath(Site $site): array
    {
        $out = [];
        foreach ($this->inventory->urlTotals($site) as $row) {
            $path = UrlNormalizer::path($row['url']);
            $out[$path] = ($out[$path] ?? 0) + (int) $row['impressions'];
        }

        return $out;
    }

    private function impressionsFor(Site $site, string $path): int
    {
        return $this->impressionsByPath($site)[$path] ?? 0;
    }

    private function topQueryFor(Site $site, string $path): ?string
    {
        foreach ($this->inventory->urlTotals($site) as $row) {
            if (UrlNormalizer::path($row['url']) === $path) {
                return $this->inventory->topQuery($site, $row['url']);
            }
        }

        return null;
    }

    /**
     * Live paths already earning impressions for a query, with how many — the evidence that a page can
     * hold this ranking rather than merely look like it should.
     *
     * @return array<string, int>
     */
    private function pagesRankingFor(Site $site, string $query): array
    {
        $out = [];
        foreach (['gsc_url_query_daily', 'gsc_url_query_monthly'] as $table) {
            $rows = DB::table($table)
                ->where('site_id', $site->id)
                ->where('query', $query)
                ->groupBy('url')
                ->selectRaw('url, sum(impressions) as impressions')
                ->get();
            foreach ($rows as $row) {
                $path = UrlNormalizer::path((string) $row->url);
                $out[$path] = ($out[$path] ?? 0) + (int) $row->impressions;
            }
        }

        return $out;
    }

    /** @return array<string, true> */
    private function tokens(string $path): array
    {
        $leaf = (string) preg_replace('/^.*\//', '', trim($path, '/'));
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($leaf)) ?: [] as $token) {
            if ($token !== '' && ! in_array($token, ['and', 'the', 'for', 'a', 'of'], true)) {
                $out[$token] = true;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $a
     * @param  array<string, true>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        // Both are non-empty by the guard above, so the union is too.
        return count(array_intersect_key($a, $b)) / count($a + $b);
    }
}
