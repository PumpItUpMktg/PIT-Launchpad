<?php

namespace App\Publishing\Redirects;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\KeywordGenerator\Bucketer;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Collection;

/**
 * Reads a revival plan ({@see LegacyContentReviver::plan()}) BEFORE it is applied and says, per family,
 * whether reviving it as-is would produce a good post — or a duplicate, a bad brief, or a post about
 * something the site does not do. Report-only.
 *
 * Sump Pump Gurus' first plan (76 families, 2M impressions) read well at a glance and would have produced:
 *   • three posts briefed "sump pump gpm" beside the LIVE "Sump Pump GPM" post — those families want a
 *     redirect to it, not a rewrite (the planner missed the match: the live post carries no target
 *     keyword for its query rung to hit);
 *   • three posts briefed "sump pump", two "what size sump pump do i need", two "sump pump design ideas
 *     2025" — the reviver groups numbered twins, not families that share a query, so each shared brief
 *     would have cannibalised itself;
 *   • a post briefed on a bare head term, one on a dated image query, one on a scraped sentence;
 *   • a post for `/springcity` (a place), one for a warranty programme, three for car-wash equipment.
 *
 * Five checks, one verdict per family, in precedence order:
 *   REDIRECT  a live post already covers the brief → 301 the family to it ({@see coveredRedirects()});
 *   DECIDE    the primary URL does not look like an article, or no silo matches the brief → a human
 *             (never folded away silently);
 *   FOLD      another family in the plan shares a SOUND brief → revive the lead only, fold this one's
 *             URLs in (a shared weak brief is two families that both need rebriefing, not a fold);
 *   REBRIEF   the brief is a head term, a dated query, a sentence, or missing → rebrief before drafting;
 *   CLEAN     revive as planned.
 */
final class RevivalReview
{
    public const CLEAN = 'clean';

    public const REDIRECT = 'redirect';

    public const FOLD = 'fold';

    public const DECIDE = 'decide';

    public const REBRIEF = 'rebrief';

    /** Query tokens this short carry no topic; fewer than this and a containment match means nothing. */
    private const MIN_QUERY_TOKENS = 2;

    private const SENTENCE_WORDS = 9;

    private const STOP = ['the', 'and', 'for', 'your', 'you', 'with', 'from', 'that', 'this', 'how', 'what', 'why', 'when', 'into', 'out', 'not', 'are', 'can', 'should', 'does', 'guide', 'homeowners', 'homeowner', 'best', 'top', 'need', 'home'];

    private const STRUCTURAL = ['program', 'programme', 'offer', 'coupon', 'warranty', 'financing', 'pricing', 'careers', 'about', 'contact', 'reviews', 'testimonials', 'faq', 'privacy', 'terms'];

    public function __construct(
        private readonly LegacyContentReviver $reviver,
        private readonly Bucketer $bucketer,
    ) {}

    /**
     * @return array{
     *     families: list<array{
     *         key: string, from_urls: list<string>, query: ?string, impressions: int,
     *         verdict: string, flags: list<string>,
     *         covered_by: ?array{title: string, path: string}, lead: ?string
     *     }>,
     *     counts: array<string, int>,
     *     silo_check: bool
     * }
     */
    public function for(Site $site, ?int $minImpressions = null, ?int $limit = null): array
    {
        $plan = $this->reviver->plan($site, $minImpressions, $limit);
        if ($plan === []) {
            return ['families' => [], 'counts' => [], 'silo_check' => false];
        }

        $livePosts = $this->livePosts($site);
        $serviceTokens = $this->serviceTokens($site);
        $silos = Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get()
            ->filter(fn (Silo $s): bool => is_array($s->rule_set) && (($s->rule_set['include_patterns'] ?? []) !== [] || ($s->rule_set['seed_terms'] ?? []) !== []));
        $siloCheck = $silos->isNotEmpty();

        // Shared briefs: the biggest family on a query leads; the rest fold into it.
        $leadByQuery = [];
        foreach ($plan as $f) {
            $q = $this->normalizeQuery($f['query']);
            if ($q === '') {
                continue;
            }
            if (! isset($leadByQuery[$q]) || $f['impressions'] > $leadByQuery[$q]['impressions']) {
                $leadByQuery[$q] = $f;
            }
        }

        $out = [];
        $counts = [];
        foreach ($plan as $f) {
            $flags = [];
            $coveredBy = null;
            $lead = null;
            $q = $this->normalizeQuery($f['query']);
            $queryTokens = $this->tokens($q);
            $leaf = $this->leaf($f['from_urls'][0] ?? '');

            // 1. Already covered by a live post?
            $coveredBy = $this->coveredBy($queryTokens, $leaf, $livePosts, $serviceTokens);
            if ($coveredBy !== null) {
                $flags[] = "a live post already covers it: “{$coveredBy['title']}” ({$coveredBy['path']})";
            }

            // 2. Shared brief?
            if ($q !== '' && isset($leadByQuery[$q]) && $leadByQuery[$q]['key'] !== $f['key']) {
                $lead = $leadByQuery[$q]['key'];
                $flags[] = "shares the brief “{$q}” with {$lead} (".number_format($leadByQuery[$q]['impressions']).' impr) — fold into it';
            }

            // 3. Not an article? (the eligibility rules passed it; these are the shapes they cannot see)
            $decide = [];
            if ($leaf !== '' && ! str_contains($leaf, '-')) {
                $decide[] = "bare slug /{$leaf} — a place or a name, not an article?";
            }
            $structural = array_values(array_intersect($this->tokens($leaf), self::STRUCTURAL));
            if ($structural !== []) {
                $decide[] = 'reads like an offer or structural page ('.implode(', ', $structural).')';
            }
            // 4. Off the footprint?
            if ($siloCheck) {
                $probe = $q !== '' ? $q : str_replace('-', ' ', $leaf);
                if ($probe !== '' && $this->bucketer->bucket($probe, $silos) === null) {
                    $decide[] = 'no silo matches the brief — outside what the site does?';
                }
            }
            array_push($flags, ...$decide);

            // 5. Weak brief?
            $weak = $this->weakBrief($q, $queryTokens, $serviceTokens);
            if ($weak !== null) {
                $flags[] = $weak;
            }

            $verdict = match (true) {
                $coveredBy !== null => self::REDIRECT,
                $decide !== [] => self::DECIDE,
                $lead !== null && $weak === null => self::FOLD,
                $weak !== null => self::REBRIEF,
                default => self::CLEAN,
            };
            $counts[$verdict] = ($counts[$verdict] ?? 0) + 1;

            $out[] = [
                'key' => $f['key'],
                'from_urls' => $f['from_urls'],
                'query' => $f['query'],
                'impressions' => $f['impressions'],
                'verdict' => $verdict,
                'flags' => $flags,
                'covered_by' => $coveredBy,
                'lead' => $lead,
            ];
        }

        return ['families' => $out, 'counts' => $counts, 'silo_check' => $siloCheck];
    }

    /**
     * Family keys the review passed — what `launchpad:revive-legacy-content --clean` applies.
     *
     * @return list<string>
     */
    public function cleanKeys(Site $site, ?int $minImpressions = null, ?int $limit = null): array
    {
        return array_values(array_map(
            fn (array $f): string => $f['key'],
            array_filter($this->for($site, $minImpressions, $limit)['families'], fn (array $f): bool => $f['verdict'] === self::CLEAN),
        ));
    }

    /**
     * The redirects the REDIRECT families want: every one of their URLs → the live post that covers them.
     *
     * @param  array{families: list<array<string, mixed>>}  $review
     * @return list<array{from: string, to: string, title: string, impressions: int}>
     */
    public function coveredRedirects(array $review): array
    {
        $rows = [];
        foreach ($review['families'] as $f) {
            if ($f['verdict'] !== self::REDIRECT || ! is_array($f['covered_by'])) {
                continue;
            }
            foreach ($f['from_urls'] as $from) {
                $rows[] = ['from' => (string) $from, 'to' => (string) $f['covered_by']['path'], 'title' => (string) $f['covered_by']['title'], 'impressions' => (int) $f['impressions']];
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $queryTokens
     * @param  Collection<int, array{title: string, path: string, tokens: list<string>}>  $livePosts
     * @param  list<list<string>>  $serviceTokens
     * @return ?array{title: string, path: string}
     */
    private function coveredBy(array $queryTokens, string $leaf, Collection $livePosts, array $serviceTokens): ?array
    {
        // The words that make the query a TOPIC rather than the trade: "sump pump gpm" minus the pillar's
        // "sump pump" is "gpm". A query with nothing left ("sump pump") is inside every post on the site
        // and covered by none of them.
        $distinguishing = $queryTokens;
        foreach ($serviceTokens as $tokens) {
            $distinguishing = array_values(array_diff($distinguishing, $tokens));
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($livePosts as $post) {
            $score = 0.0;
            // The brief's every topic word appears in the live post's title or slug — "sump pump gpm" is
            // inside "Sump Pump GPM: How to Size Your Pump". Containment, not resemblance: a short query
            // wholly present is the signal; a long query sharing two words is not.
            if (count($queryTokens) >= self::MIN_QUERY_TOKENS && $distinguishing !== [] && array_diff($queryTokens, $post['tokens']) === []) {
                $score = 1.0;
            } elseif ($queryTokens === [] && $leaf !== '') {
                // No brief: fall back to the family's own slug against the live slug (the planner's floor).
                $score = $this->jaccard($this->tokens($leaf), $post['tokens']);
                $score = $score >= 0.6 ? $score : 0.0;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['title' => $post['title'], 'path' => $post['path']];
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $queryTokens
     * @param  list<list<string>>  $serviceTokens
     */
    private function weakBrief(string $q, array $queryTokens, array $serviceTokens): ?string
    {
        if ($q === '') {
            return 'no query to brief on — the post would be titled from the slug';
        }
        if (preg_match('/\b(19|20)\d{2}\b/', $q) === 1) {
            return "dated query “{$q}” — the year will be wrong by the time it ranks";
        }
        if (str_word_count($q) >= self::SENTENCE_WORDS || str_ends_with($q, '.') || str_ends_with($q, '?')) {
            return "a sentence, not a query: “{$q}”";
        }
        if (count($queryTokens) < self::MIN_QUERY_TOKENS) {
            return "a single word — “{$q}” is not a topic";
        }
        foreach ($serviceTokens as $tokens) {
            if (array_diff($queryTokens, $tokens) === []) {
                return "the head term “{$q}” — the pillar's own query, not an article's";
            }
        }

        return null;
    }

    /** @return Collection<int, array{title: string, path: string, tokens: list<string>}> */
    private function livePosts(Site $site): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'title', 'slug', 'kind', 'page_type'])
            ->map(fn (Content $c): array => $this->livePost($site, $c))
            ->values();
    }

    /** @return array{title: string, path: string, tokens: list<string>} */
    private function livePost(Site $site, Content $c): array
    {
        $url = PublicUrl::forContent($site->domain_url, $c);
        $path = $url !== null ? '/'.trim((string) parse_url($url, PHP_URL_PATH), '/') : '/'.trim((string) $c->slug, '/');

        return [
            'title' => (string) $c->title,
            'path' => $path,
            'tokens' => array_values(array_unique(array_merge($this->tokens((string) $c->title), $this->tokens((string) $c->slug)))),
        ];
    }

    /** @return list<list<string>> */
    private function serviceTokens(Site $site): array
    {
        return Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->pluck('name')
            ->map(fn ($name): array => array_values(array_diff($this->tokens((string) $name), ['services', 'service'])))
            ->filter(fn (array $t): bool => $t !== [])
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $w): bool => mb_strlen($w) > 2 && ! in_array($w, self::STOP, true))));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / count(array_unique(array_merge($a, $b)));
    }

    private function normalizeQuery(?string $query): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $query))));
    }

    private function leaf(string $path): string
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));

        return $parts === [] ? '' : mb_strtolower((string) end($parts));
    }
}
