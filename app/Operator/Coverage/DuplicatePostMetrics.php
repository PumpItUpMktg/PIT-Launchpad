<?php

namespace App\Operator\Coverage;

use App\ContentEngine\Questions\QuestionCluster;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The GSC-metrics view of LIVE duplicate blog POSTS — the post-lane twin of {@see DuplicatePageMetrics}
 * (which is location-pages only). It surfaces the "$2.2M Sewer Grants … -what" / "… -what-2" shape: two
 * published `kind=post` rows for the same story, both live and often both indexed, competing for one query.
 *
 * WHY THIS SHAPE. The funnel has no "same story → update the existing post" path — every accepted item is a
 * `Content::create()`. When a re-reported story slips past the exact-URL dedup (`source_url_key`/`external_id`)
 * and past the (news-lane-only, single-silo, operator-gated) near-duplicate check, a second row is created and
 * `DraftingEngine::uniqueSlug` disambiguates its slug with a trailing `-N`. That numeric suffix is the
 * fingerprint: two published posts whose slugs share a base once the trailing `-N` is stripped ARE the same
 * collided story (slugs are unique, so a shared base implies one member was numbered). So the grouping key is
 * the de-numbered slug — deterministic, no embeddings, and exact for the mechanism that produced these pairs.
 *
 * KEEPER is NOT decided here. The default the resolver will take is by AGE (the earliest-published row), but
 * age picks wrong when the `-N` twin is the earner and the original is dead (the Buckingham lesson) — so this
 * report attaches GSC impressions + blended position to BOTH sides and raises `age_conflict` when the earner
 * is not the age-keeper. The operator (and the resolver) decide the direction from the earner, not the age.
 *
 * NEAR-DUPLICATES. The `-N` fingerprint misses the second shape the funnel produces: the same story drafted
 * twice under two DIFFERENT slugs ("sump-pump-vent-what-it-does…" / "sump-pump-vent-what-it-is…"). Those are
 * grouped by {@see nearGroups()}: rarity-weighted title similarity (boilerplate words weigh nothing) at or
 * above {@see TITLE_THRESHOLD} (`kind=title`), or observed cannibalisation — the smaller post earns at least
 * {@see SHARED_IMPRESSIONS_SHARE} of its impressions on queries the other also ranks for (`kind=title+queries`).
 * Groups are cliques, never chains, and a group over {@see MAX_NEAR_GROUP} members is flagged `oversized`.
 * Every group carries `kind` and human-readable `signals`; the resolver treats a near group as reportable-only
 * unless the operator opts in, because a wrong merge deletes a real post.
 *
 * READ-ONLY. Published-only (candidates / needs_review aren't live and aren't cannibalising anything; the
 * 30-day expiry clears them, and the source fix stops them reaching publish). GSC window default 28d.
 */
final class DuplicatePostMetrics
{
    private const WINDOW_DAYS = 28;

    /** Weighted title Jaccard (rarity-weighted tokens) that alone makes two posts one story. */
    public const TITLE_THRESHOLD = 0.75;

    /** Weighted title Jaccard a cannibalising pair must still clear — a sanity bar, not a match. */
    public const TITLE_WITH_QUERIES_THRESHOLD = 0.25;

    /** Shared Search Console queries (both posts with impressions) a cannibalising pair needs. */
    public const SHARED_QUERIES_MIN = 2;

    /** The smaller post must earn at least this share of its impressions on the shared queries. */
    public const SHARED_IMPRESSIONS_SHARE = 0.5;

    /** A near group bigger than this is a chain the operator must look at, never an automatic merge. */
    public const MAX_NEAR_GROUP = 4;

    /**
     * @return list<array{
     *   key: string, title: string, kind: string, signals: list<string>, oversized: bool,
     *   age_keeper_id: ?string, earner_id: ?string, age_conflict: bool,
     *   members: list<array{content_id:string, title:string, slug:string, url:?string, numbered:bool,
     *     impressions:int, clicks:int, position:?float, index:string, published_at:?string,
     *     age_keeper:bool, top_impressions:bool}>
     * }>
     */
    public function report(Site $site, int $days = self::WINDOW_DAYS): array
    {
        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'site_id', 'title', 'slug', 'published_at', 'created_at']);

        $slugGroups = $posts
            ->groupBy(fn (Content $c): string => $this->baseSlug((string) $c->slug))
            ->filter(fn (Collection $g): bool => $g->count() > 1);

        $domain = $site->domain_url;
        $days = max(1, $days);
        $gsc = $this->gscByPath($site, $days);

        // The posts no -N family claimed are candidates for the title pass.
        $claimed = $slugGroups->flatten(1)->pluck('id')->map(fn ($id): string => (string) $id)->flip();
        $rest = $posts->reject(fn (Content $c): bool => $claimed->has((string) $c->id))->values();
        $nearGroups = $this->nearGroups($site, $rest, $posts, $domain, $days);

        $groups = [];
        foreach ($slugGroups as $base => $group) {
            $groups[] = ['key' => (string) $base, 'kind' => 'slug', 'signals' => ['slug: -N twins'], 'group' => $group, 'oversized' => false];
        }
        foreach ($nearGroups as $near) {
            $groups[] = $near;
        }
        if ($groups === []) {
            return [];
        }

        $out = [];
        foreach ($groups as ['key' => $base, 'kind' => $kind, 'signals' => $signals, 'group' => $group, 'oversized' => $oversized]) {
            // Age keeper = earliest published (fall back to created_at when a row has no publish stamp).
            $ageKeeper = $group->sortBy(fn (Content $c) => $c->published_at ?? $c->created_at)->first();

            $members = [];
            foreach ($group as $post) {
                $url = PublicUrl::forContent($domain, $post);
                $metrics = $url !== null ? ($gsc[$this->normalizePath((string) parse_url($url, PHP_URL_PATH))] ?? null) : null;

                $members[] = [
                    'content_id' => (string) $post->id,
                    'title' => (string) $post->title,
                    'slug' => (string) $post->slug,
                    'url' => $url,
                    'numbered' => (string) $post->slug !== $this->baseSlug((string) $post->slug),
                    'impressions' => (int) ($metrics['impr'] ?? 0),
                    'clicks' => (int) ($metrics['clicks'] ?? 0),
                    'position' => ($metrics !== null && $metrics['impr_pos'] > 0) ? round($metrics['posw'] / $metrics['impr_pos'], 1) : null,
                    'index' => $this->indexVerdict((string) $post->id),
                    'published_at' => $post->published_at?->toDateString(),
                    'age_keeper' => (string) $post->id === (string) $ageKeeper->id,
                    'top_impressions' => false,
                ];
            }

            // Flag the earner (most impressions) — the page whose authority a 301 must preserve.
            $maxImpr = max(array_map(fn (array $m): int => $m['impressions'], $members));
            $earnerId = null;
            if ($maxImpr > 0) {
                foreach ($members as $i => $m) {
                    if ($m['impressions'] === $maxImpr) {
                        $members[$i]['top_impressions'] = true;
                        $earnerId ??= $m['content_id'];
                    }
                }
            }

            $out[] = [
                'key' => (string) $base,
                'title' => (string) $group->first()->title,
                'kind' => $kind,
                'signals' => $signals,
                'oversized' => $oversized,
                'age_keeper_id' => (string) $ageKeeper->id,
                'earner_id' => $earnerId,
                // The age rule picks wrong when a clear earner exists and it is NOT the age-keeper.
                'age_conflict' => $earnerId !== null && $earnerId !== (string) $ageKeeper->id,
                'members' => $members,
            ];
        }

        return $out;
    }

    /**
     * Near-duplicate groups among the posts no -N family claimed. Two posts pair when EITHER
     *   - their titles agree on the words that matter: weighted Jaccard ≥ {@see TITLE_THRESHOLD}, every token
     *     weighted by how rare it is across the site's post titles (boilerplate like "what every homeowner
     *     should know" or "protect your basement" weighs next to nothing, "oil" or "electrical" weighs a lot); or
     *   - they cannibalise each other in Search Console: ≥ {@see SHARED_QUERIES_MIN} shared queries carrying at
     *     least {@see SHARED_IMPRESSIONS_SHARE} of the smaller post's impressions, with the titles at least
     *     {@see TITLE_WITH_QUERIES_THRESHOLD} alike as a sanity bar.
     * Groups are CLIQUES, not chains: a post joins only when it pairs with every member already in the group
     * (a resembles b and b resembles c never puts a with c). A group above {@see MAX_NEAR_GROUP} members is
     * returned flagged `oversized` for the resolver to block.
     *
     * @param  Collection<int, Content>  $posts  the unclaimed posts
     * @param  Collection<int, Content>  $all  every published post (title weights are learned from all of them)
     * @return list<array{key: string, kind: string, signals: list<string>, group: Collection<int, Content>, oversized: bool}>
     */
    private function nearGroups(Site $site, Collection $posts, Collection $all, ?string $domain, int $days): array
    {
        if ($posts->count() < 2) {
            return [];
        }
        $weights = self::tokenWeights($all->map(fn (Content $c): string => (string) $c->title)->all());
        $tokens = [];
        $paths = [];
        foreach ($posts as $post) {
            $tokens[(string) $post->id] = self::titleTokens((string) $post->title);
            $url = PublicUrl::forContent($domain, $post);
            $paths[(string) $post->id] = $url !== null ? $this->normalizePath((string) parse_url($url, PHP_URL_PATH)) : null;
        }
        $queries = $this->queriesByPath($site, $days);

        // Every passing pair, strongest first.
        $list = $posts->values()->all();
        $pairs = [];
        for ($i = 0; $i < count($list); $i++) {
            for ($j = $i + 1; $j < count($list); $j++) {
                $a = (string) $list[$i]->id;
                $b = (string) $list[$j]->id;
                $pair = $this->pairSignal($tokens[$a], $tokens[$b], $weights, $paths[$a] !== null ? ($queries[$paths[$a]] ?? []) : [], $paths[$b] !== null ? ($queries[$paths[$b]] ?? []) : []);
                if ($pair !== null) {
                    $pairs[] = ['a' => $a, 'b' => $b, ...$pair];
                }
            }
        }
        if ($pairs === []) {
            return [];
        }
        usort($pairs, fn (array $x, array $y): int => $y['sim'] <=> $x['sim']);
        $passes = [];
        foreach ($pairs as $pr) {
            $passes[$pr['a']][$pr['b']] = $pr;
            $passes[$pr['b']][$pr['a']] = $pr;
        }

        // Clique building: a post joins a group only when it passes with EVERY member; two groups never merge.
        $groupOf = [];
        $groups = [];
        foreach ($pairs as $pr) {
            $ga = $groupOf[$pr['a']] ?? null;
            $gb = $groupOf[$pr['b']] ?? null;
            if ($ga === null && $gb === null) {
                $groups[] = [$pr['a'], $pr['b']];
                $groupOf[$pr['a']] = $groupOf[$pr['b']] = count($groups) - 1;

                continue;
            }
            if ($ga !== null && $gb !== null) {
                continue;   // both placed (same or different groups) — never chain groups together
            }
            [$g, $newcomer] = $ga !== null ? [$ga, $pr['b']] : [$gb, $pr['a']];
            $fitsAll = true;
            foreach ($groups[$g] as $member) {
                if (! isset($passes[$newcomer][$member])) {
                    $fitsAll = false;
                    break;
                }
            }
            if ($fitsAll) {
                $groups[$g][] = $newcomer;
                $groupOf[$newcomer] = $g;
            }
        }

        $byId = $posts->keyBy(fn (Content $c): string => (string) $c->id);
        $out = [];
        foreach ($groups as $ids) {
            $members = array_map(fn (string $id): Content => $byId[$id], $ids);
            $edges = [];
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $edges[] = $passes[$ids[$i]][$ids[$j]];
                }
            }
            $minSim = min(array_map(fn (array $e): float => $e['sim'], $edges));
            $byQueries = array_values(array_filter($edges, fn (array $e): bool => $e['by_queries']));
            $signals = ['title: '.number_format($minSim, 2)];
            if ($byQueries !== []) {
                $sharedAll = [];
                $minShare = 1.0;
                foreach ($byQueries as $e) {
                    foreach ($e['shared'] as $q) {
                        $sharedAll[$q] = true;
                    }
                    $minShare = min($minShare, $e['share']);
                }
                $names = array_slice(array_keys($sharedAll), 0, 3);
                $signals[] = sprintf('queries: %d shared, %d%% of the smaller post\'s impressions (%s%s)', count($sharedAll), (int) round($minShare * 100), implode(', ', $names), count($sharedAll) > 3 ? ', …' : '');
            }
            $group = new Collection($members);
            $first = $group->sortBy(fn (Content $c) => $c->published_at ?? $c->created_at)->first();
            $out[] = [
                'key' => $this->baseSlug((string) $first->slug),
                'kind' => $byQueries !== [] ? 'title+queries' : 'title',
                'signals' => $signals,
                'group' => $group,
                'oversized' => count($ids) > self::MAX_NEAR_GROUP,
            ];
        }

        return $out;
    }

    /**
     * Whether two posts pair, and why. Null when they do not.
     *
     * @param  list<string>  $ta  @param  list<string>  $tb  title tokens
     * @param  array<string, float>  $weights  token → rarity weight
     * @param  array<string, int>  $qa  @param  array<string, int>  $qb  query → impressions, per post
     * @return array{sim: float, by_title: bool, by_queries: bool, shared: list<string>, share: float}|null
     */
    private function pairSignal(array $ta, array $tb, array $weights, array $qa, array $qb): ?array
    {
        $sim = self::weightedJaccard($ta, $tb, $weights);
        if ($sim < self::TITLE_WITH_QUERIES_THRESHOLD) {
            return null;
        }
        $byTitle = $sim >= self::TITLE_THRESHOLD;

        $shared = array_values(array_intersect(array_keys($qa), array_keys($qb)));
        $byQueries = false;
        $share = 0.0;
        if (count($shared) >= self::SHARED_QUERIES_MIN) {
            $totalA = array_sum($qa);
            $totalB = array_sum($qb);
            $small = $totalA <= $totalB ? $qa : $qb;
            $smallTotal = array_sum($small);
            $onShared = 0;
            foreach ($shared as $q) {
                $onShared += $small[$q];
            }
            $share = $smallTotal > 0 ? $onShared / $smallTotal : 0.0;
            $byQueries = $share >= self::SHARED_IMPRESSIONS_SHARE;
        }
        if (! $byTitle && ! $byQueries) {
            return null;
        }

        return ['sim' => $sim, 'by_title' => $byTitle, 'by_queries' => $byQueries, 'shared' => $shared, 'share' => $share];
    }

    /**
     * Rarity weights for title tokens across the site's posts: log((N + 1) / (df + 1)). A word in most titles
     * ("sump", "pump", "homeowner", "know") drops toward zero; a word in one or two titles counts fully.
     *
     * @param  list<string>  $titles
     * @return array<string, float>
     */
    public static function tokenWeights(array $titles): array
    {
        $df = [];
        foreach ($titles as $title) {
            foreach (self::titleTokens($title) as $t) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }
        $n = count($titles);
        $out = [];
        foreach ($df as $t => $count) {
            $out[$t] = log(($n + 1) / ($count + 1));
        }

        return $out;
    }

    /**
     * Jaccard on token sets where each token counts its rarity weight (an unseen token counts as rare).
     *
     * @param  list<string>  $a  @param  list<string>  $b
     * @param  array<string, float>  $weights
     */
    public static function weightedJaccard(array $a, array $b, array $weights): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $max = $weights === [] ? 1.0 : max($weights);
        $w = fn (string $t): float => $weights[$t] ?? $max;
        $inter = 0.0;
        foreach (array_intersect($a, $b) as $t) {
            $inter += $w($t);
        }
        $union = 0.0;
        foreach (array_unique(array_merge($a, $b)) as $t) {
            $union += $w($t);
        }

        return $union > 0 ? $inter / $union : 0.0;
    }

    /** Title tokens for similarity: the question tokenizer, minus years ("2025", "2026") and bare numbers. @return list<string> */
    public static function titleTokens(string $title): array
    {
        return array_values(array_filter(QuestionCluster::tokens($title), fn (string $t): bool => preg_match('/^\\d+$/', $t) !== 1));
    }

    /**
     * The queries each path took impressions for inside the window, with the impressions, from the per-query store.
     *
     * @return array<string, array<string, int>> normalized path → query → impressions
     */
    private function queriesByPath(Site $site, int $days): array
    {
        $rows = DB::table('gsc_url_query_daily')
            ->where('site_id', $site->id)
            ->where('date', '>=', Carbon::now()->subDays($days)->toDateString())
            ->where('impressions', '>', 0)
            ->selectRaw('url, query, SUM(impressions) AS impr')
            ->groupBy('url', 'query')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $path = $this->normalizePath((string) parse_url((string) $row->url, PHP_URL_PATH));
            $q = mb_strtolower(trim((string) $row->query));
            $out[$path][$q] = ($out[$path][$q] ?? 0) + (int) $row->impr;
        }

        return $out;
    }

    /** Strip a trailing `-N` (the uniqueSlug disambiguator) so a collided pair collapses to one key. */
    private function baseSlug(string $slug): string
    {
        return (string) preg_replace('/-\d+$/', '', trim($slug, '/'));
    }

    /**
     * One aggregate over the gsc_url_daily window, keyed by normalized path (mirrors {@see DuplicatePageMetrics}).
     * `impr_pos` = impressions on rows carrying a position (blended denominator); `posw` = Σ(position × impressions).
     *
     * @return array<string, array{impr:int, clicks:int, impr_pos:int, posw:float}>
     */
    private function gscByPath(Site $site, int $days): array
    {
        $rows = DB::table('gsc_url_daily')
            ->where('site_id', $site->id)
            ->where('date', '>=', Carbon::now()->subDays($days)->toDateString())
            ->selectRaw('url,
                SUM(impressions) AS impr,
                SUM(clicks) AS clicks,
                SUM(CASE WHEN position IS NULL THEN 0 ELSE impressions END) AS impr_pos,
                SUM(CASE WHEN position IS NULL THEN 0 ELSE position * impressions END) AS posw')
            ->groupBy('url')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $path = $this->normalizePath((string) parse_url((string) $row->url, PHP_URL_PATH));
            if (! isset($out[$path])) {
                $out[$path] = ['impr' => 0, 'clicks' => 0, 'impr_pos' => 0, 'posw' => 0.0];
            }
            $out[$path]['impr'] += (int) $row->impr;
            $out[$path]['clicks'] += (int) $row->clicks;
            $out[$path]['impr_pos'] += (int) $row->impr_pos;
            $out[$path]['posw'] += (float) $row->posw;
        }

        return $out;
    }

    /** Three-state index verdict from the durable table (mirrors the Live board + the duplicate-page report). */
    private function indexVerdict(string $contentId): string
    {
        $row = PageIndexState::withoutGlobalScope(SiteScope::class)->where('content_id', $contentId)->first();
        if ($row === null) {
            return 'not checked';
        }

        return $row->isIndexed()
            ? 'indexed'
            : ($row->coverage_state !== null && $row->coverage_state !== '' ? "not indexed ({$row->coverage_state})" : 'not indexed');
    }

    /** Canonical path key: leading slash, no trailing slash, lowercased — so `/foo/` and `/foo` match. */
    private function normalizePath(?string $path): string
    {
        return mb_strtolower('/'.trim((string) $path, '/'));
    }
}
