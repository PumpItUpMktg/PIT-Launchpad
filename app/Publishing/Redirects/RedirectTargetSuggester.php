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
    public function __construct(private readonly GscUrlInventory $inventory) {}

    /**
     * Ranked candidate targets for one legacy path, best first.
     *
     * @return array{
     *     from: string, top_query: ?string, impressions: int,
     *     candidates: list<array{path: string, title: string, shares_query: int, overlap: float, impressions: int}>
     * }
     */
    public function for(Site $site, string $from, int $limit = 8): array
    {
        $fromPath = UrlNormalizer::path($from);
        $topQuery = $this->topQueryFor($site, $fromPath);
        $sourceImpressions = $this->impressionsFor($site, $fromPath);

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
            if ($path === $fromPath) {
                continue;
            }

            $shares = $sharing[$path] ?? 0;
            $overlap = $this->jaccard($sourceTokens, $this->tokens($path));
            if ($shares === 0 && $overlap < 0.25) {
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

        return [
            'from' => $fromPath,
            'top_query' => $topQuery,
            'impressions' => $sourceImpressions,
            'candidates' => array_slice($candidates, 0, max(1, $limit)),
        ];
    }

    /**
     * The legacy URLs worth routing that the planner left unresolved, biggest first — the set this exists
     * for.
     *
     * @return list<array{from: string, impressions: int}>
     */
    public function unrouted(Site $site, int $minImpressions = 2500): array
    {
        $plan = $this->planFor($site);
        $out = [];
        foreach ($plan['unresolved'] as $row) {
            if ((int) $row['impressions'] >= $minImpressions) {
                $out[] = ['from' => (string) $row['from'], 'impressions' => (int) $row['impressions']];
            }
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
