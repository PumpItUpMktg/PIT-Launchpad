<?php

namespace App\Publishing\Redirects;

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\RedirectSource;
use App\Locations\LegacyRedirectAuditor;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns the GSC URL inventory into a reviewable old→new redirect plan for a
 * migrated site: which indexed legacy URLs should 301 (to their successor in the
 * new IA), which should 410 (gone — out-of-footprint / no successor), and which
 * are already live (leave alone) or can't be confidently routed (surfaced for a
 * human, never guessed).
 *
 * The new site keeps its own clean URLs; a 301 passes essentially all ranking
 * signal, so we route rather than recreate content at old paths. Routing is a
 * confidence cascade — numbered-duplicate collapse → town → the URL's own top
 * GSC query matched to a live page's target keyword → slug-token overlap — and a
 * NEVER-shadow-a-live-page rule (the same invariant as {@see LegacyRedirectAuditor}).
 * A wrong redirect is worse than none, so an unconfident URL is left unresolved.
 *
 * `plan()` is pure (touches nothing); `apply()` upserts the 301/410 `Redirect`
 * rows the companion plugin serves (§2 pushes them to WordPress — this never
 * pushes).
 */
class LegacyRedirectPlanner
{
    /** USPS codes so an out-of-footprint town page (…-fl, …-tx) can be flushed with a 410. */
    private const US_STATES = ['al', 'ak', 'az', 'ar', 'ca', 'co', 'ct', 'de', 'fl', 'ga', 'hi', 'id', 'il', 'in', 'ia', 'ks', 'ky', 'la', 'me', 'md', 'ma', 'mi', 'mn', 'ms', 'mo', 'mt', 'ne', 'nv', 'nh', 'nj', 'nm', 'ny', 'nc', 'nd', 'oh', 'ok', 'or', 'pa', 'ri', 'sc', 'sd', 'tn', 'tx', 'ut', 'vt', 'va', 'wa', 'wv', 'wi', 'wy'];

    /** Slug-token Jaccard overlap at/above which an unambiguous best live page is a confident 301 target. */
    private const SLUG_OVERLAP_FLOOR = 0.6;

    public function __construct(private readonly GscUrlInventory $inventory) {}

    /**
     * @return array{
     *   redirect: list<array{from: string, to: string, code: int, impressions: int, reason: string, top_query: ?string}>,
     *   gone: list<array{from: string, code: int, impressions: int, reason: string}>,
     *   skipped_live: int,
     *   unresolved: list<array{from: string, impressions: int, top_query: ?string}>,
     * }
     */
    public function plan(Site $site): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('slug')
            ->with('targetKeyword:id,query')
            ->get(['id', 'slug', 'title', 'page_type', 'target_keyword_id']);

        $livePaths = [];        // normalized path => true
        $leafToPath = [];       // last slug segment => path (first wins)
        $keywordToPath = [];    // normalized target keyword => path (first wins)
        $locationPages = [];    // [town-slug => path]
        foreach ($pages as $page) {
            $path = $this->normalize((string) $page->slug);
            if ($path === '') {
                continue;
            }
            $livePaths[$path] = true;
            $leaf = $this->leaf($path);
            $leafToPath[$leaf] ??= $path;

            $kw = $page->targetKeyword?->query;
            if (is_string($kw) && trim($kw) !== '') {
                $keywordToPath[$this->normalizeText($kw)] ??= $path;
            }
            if (($page->page_type instanceof PageType ? $page->page_type : null) === PageType::Location) {
                $townSlug = Str::slug($this->townName((string) $page->title));
                if ($townSlug !== '') {
                    $locationPages[$townSlug] ??= $path;
                }
            }
        }

        $existingFrom = Redirect::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->pluck('from_url')
            ->map(fn ($u): string => $this->normalizeKey((string) $u))
            ->filter()
            ->flip();

        $redirect = [];
        $gone = [];
        $unresolved = [];
        $skippedLive = 0;
        $seen = [];

        foreach ($this->inventory->urlTotals($site) as $entry) {
            $path = $this->normalize($entry['url']);
            if ($path === '') {
                continue; // domain root — always live
            }
            $key = ltrim($path, '/');
            if (isset($seen[$key])) {
                continue; // one plan row per normalized path
            }
            $seen[$key] = true;

            if (isset($livePaths[$path])) {
                $skippedLive++;

                continue; // an indexed URL that IS a current page — already preserved
            }
            if ($existingFrom->has('/'.$key)) {
                continue; // already has a redirect
            }

            $topQuery = $this->inventory->topQuery($site, $entry['url']);
            $target = $this->route($path, $topQuery, $livePaths, $leafToPath, $keywordToPath, $locationPages);

            if ($target !== null && $target['code'] === 410) {
                $gone[] = ['from' => '/'.$key, 'code' => 410, 'impressions' => $entry['impressions'], 'reason' => $target['reason']];
            } elseif ($target !== null) {
                $redirect[] = [
                    'from' => '/'.$key, 'to' => $target['to'], 'code' => 301,
                    'impressions' => $entry['impressions'], 'reason' => $target['reason'], 'top_query' => $topQuery,
                ];
            } else {
                $unresolved[] = ['from' => '/'.$key, 'impressions' => $entry['impressions'], 'top_query' => $topQuery];
            }
        }

        return ['redirect' => $redirect, 'gone' => $gone, 'skipped_live' => $skippedLive, 'unresolved' => $unresolved];
    }

    /**
     * Persist the plan's 301/410 rows (idempotent upsert on from_url), never
     * shadowing a live page. Returns the number written.
     *
     * @param  array{redirect: list<array<string, mixed>>, gone: list<array<string, mixed>>, skipped_live: int, unresolved: list<array<string, mixed>>}  $plan
     */
    public function apply(Site $site, array $plan): int
    {
        $rows = [];
        foreach ($plan['redirect'] as $r) {
            $rows[] = ['from' => (string) $r['from'], 'to' => (string) $r['to'], 'code' => 301];
        }
        foreach ($plan['gone'] as $r) {
            $rows[] = ['from' => (string) $r['from'], 'to' => '', 'code' => 410];
        }
        if ($rows === []) {
            return 0;
        }

        return DB::transaction(function () use ($site, $rows): int {
            $written = 0;
            foreach ($rows as $row) {
                Redirect::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                    ['site_id' => $site->id, 'from_url' => $row['from']],
                    ['to_url' => $row['to'], 'code' => $row['code'], 'status' => 'active', 'source' => RedirectSource::Migration->value],
                );
                $written++;
            }

            return $written;
        });
    }

    /**
     * The routing cascade — most-confident match first. Returns ['to' => path,
     * 'code' => 301, 'reason' => ...] or a 410 marker, or null (leave unresolved).
     *
     * @param  array<string, bool>  $livePaths
     * @param  array<string, string>  $leafToPath
     * @param  array<string, string>  $keywordToPath
     * @param  array<string, string>  $locationPages
     * @return array{to: string, code: int, reason: string}|array{to: null, code: int, reason: string}|null
     */
    private function route(string $path, ?string $topQuery, array $livePaths, array $leafToPath, array $keywordToPath, array $locationPages): ?array
    {
        $leaf = $this->leaf($path);

        // 1. Numbered-duplicate collapse: /foo-3 → the live /…/foo (old-site pagination/dupe artifact).
        if (preg_match('/^(.*?)-\d+$/', $leaf, $m) === 1 && $m[1] !== '') {
            if (isset($leafToPath[$m[1]])) {
                return ['to' => $leafToPath[$m[1]], 'code' => 301, 'reason' => 'numbered_duplicate'];
            }
        }

        // 2. Town: a bare-town legacy slug → its canonical location page.
        $bareTown = Str::slug($this->townName(str_replace('-', ' ', $leaf)));
        if ($bareTown !== '' && isset($locationPages[$bareTown])) {
            return ['to' => $locationPages[$bareTown], 'code' => 301, 'reason' => 'town'];
        }

        // 3. Intent: the URL's top GSC query IS a live page's target keyword → route to that page.
        if ($topQuery !== null) {
            $qk = $this->normalizeText($topQuery);
            if (isset($keywordToPath[$qk])) {
                return ['to' => $keywordToPath[$qk], 'code' => 301, 'reason' => 'top_query'];
            }
        }

        // 4. Slug-token overlap: the closest live page by leaf tokens, if unambiguous and strong enough.
        $best = $this->bestSlugOverlap($leaf, $leafToPath);
        if ($best !== null) {
            return ['to' => $best, 'code' => 301, 'reason' => 'slug_overlap'];
        }

        // 5. Out-of-footprint town: a state-suffixed slug for a state we don't serve → gone.
        if (preg_match('/-([a-z]{2})$/', $leaf, $m) === 1 && in_array($m[1], self::US_STATES, true)) {
            $footprint = array_map('strtolower', (array) config('launchpad.footprint.states', []));
            if (! in_array($m[1], $footprint, true)) {
                return ['to' => null, 'code' => 410, 'reason' => 'out_of_footprint'];
            }
        }

        return null; // unconfident — leave for a human
    }

    /**
     * The single closest live page by slug-token Jaccard overlap, if it clears
     * the floor AND is unambiguous (a unique best). Null otherwise.
     *
     * @param  array<string, string>  $leafToPath
     */
    private function bestSlugOverlap(string $leaf, array $leafToPath): ?string
    {
        $tokens = $this->tokens($leaf);
        if ($tokens === []) {
            return null;
        }

        $bestScore = 0.0;
        $bestPath = null;
        $tiedAtBest = 0;
        foreach ($leafToPath as $liveLeaf => $path) {
            $other = $this->tokens($liveLeaf);
            if ($other === []) {
                continue;
            }
            $inter = count(array_intersect($tokens, $other));
            $union = count(array_unique(array_merge($tokens, $other))); // ≥ 1: both token sets are non-empty
            $score = $inter / $union;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPath = $path;
                $tiedAtBest = 1;
            } elseif ($score === $bestScore && $score > 0) {
                $tiedAtBest++;
            }
        }

        return ($bestScore >= self::SLUG_OVERLAP_FLOOR && $tiedAtBest === 1) ? $bestPath : null;
    }

    /** Leading-slash path, trailing slash + query/fragment stripped, lowercased — the plugin's key form. */
    private function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $parsed = parse_url($value, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $value;
        $path = strtolower(trim($path, '/'));

        return $path === '' ? '' : '/'.$path;
    }

    /** Normalize an already-path-ish key (existing redirect from_url) for comparison. */
    private function normalizeKey(string $value): string
    {
        return $this->normalize($value);
    }

    /** The last path segment. */
    private function leaf(string $path): string
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));

        return $parts === [] ? '' : (string) end($parts);
    }

    /** Lowercased alnum word tokens of a slug (hyphens/underscores split). */
    private function tokens(string $slug): array
    {
        $parts = preg_split('/[^a-z0-9]+/', strtolower($slug)) ?: [];

        return array_values(array_filter($parts, fn (string $t): bool => $t !== ''));
    }

    /** Normalize free text (a query / keyword) to a comparable key. */
    private function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower(trim($value))));
    }

    /** The town label without a trailing ", ST" state suffix ("Hoboken, NJ" → "Hoboken"). */
    private function townName(string $title): string
    {
        return trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?\s*$/', '', trim($title)));
    }
}
