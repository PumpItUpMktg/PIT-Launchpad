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
 * grouped by TITLE similarity (token-set Jaccard on {@see QuestionCluster::tokens()}, years dropped, single
 * linkage): at or above {@see TITLE_THRESHOLD} on its own (`kind=title`), or at or above
 * {@see TITLE_WITH_QUERIES_THRESHOLD} when the two posts also take impressions for at least
 * {@see SHARED_QUERIES_MIN} of the same Search Console queries inside the window (`kind=title+queries` — the
 * cannibalisation itself, observed). Every group carries `kind` and human-readable `signals`; the resolver
 * treats a near group as reportable-only unless the operator opts in, because a wrong merge deletes a real post.
 *
 * READ-ONLY. Published-only (candidates / needs_review aren't live and aren't cannibalising anything; the
 * 30-day expiry clears them, and the source fix stops them reaching publish). GSC window default 28d.
 */
final class DuplicatePostMetrics
{
    private const WINDOW_DAYS = 28;

    /** Title Jaccard that alone makes two posts one story. */
    public const TITLE_THRESHOLD = 0.6;

    /** Title Jaccard that makes two posts one story when they also share ranking queries. */
    public const TITLE_WITH_QUERIES_THRESHOLD = 0.4;

    /** Shared Search Console queries (both posts with impressions) needed for the lower title bar. */
    public const SHARED_QUERIES_MIN = 2;

    /**
     * @return list<array{
     *   key: string, title: string, kind: string, signals: list<string>,
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
        $nearGroups = $this->nearGroups($site, $rest, $domain, $days);

        $groups = [];
        foreach ($slugGroups as $base => $group) {
            $groups[] = ['key' => (string) $base, 'kind' => 'slug', 'signals' => ['slug: -N twins'], 'group' => $group];
        }
        foreach ($nearGroups as $near) {
            $groups[] = $near;
        }
        if ($groups === []) {
            return [];
        }

        $out = [];
        foreach ($groups as ['key' => $base, 'kind' => $kind, 'signals' => $signals, 'group' => $group]) {
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
     * Near-duplicate groups among the posts no -N family claimed: single-linkage on title similarity, the lower
     * bar admitted only with shared ranking queries.
     *
     * @param  Collection<int, Content>  $posts
     * @return list<array{key: string, kind: string, signals: list<string>, group: Collection<int, Content>}>
     */
    private function nearGroups(Site $site, Collection $posts, ?string $domain, int $days): array
    {
        if ($posts->count() < 2) {
            return [];
        }
        $tokens = [];
        $paths = [];
        foreach ($posts as $post) {
            $tokens[(string) $post->id] = self::titleTokens((string) $post->title);
            $url = PublicUrl::forContent($domain, $post);
            $paths[(string) $post->id] = $url !== null ? $this->normalizePath((string) parse_url($url, PHP_URL_PATH)) : null;
        }
        $queries = $this->queriesByPath($site, $days);

        // Union-find over pairs that pass; remember why each edge passed.
        $parent = [];
        foreach ($posts as $post) {
            $parent[(string) $post->id] = (string) $post->id;
        }
        $find = function (string $x) use (&$parent, &$find): string {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        $edges = [];
        $list = $posts->values()->all();
        for ($i = 0; $i < count($list); $i++) {
            for ($j = $i + 1; $j < count($list); $j++) {
                $a = (string) $list[$i]->id;
                $b = (string) $list[$j]->id;
                $sim = QuestionCluster::jaccard($tokens[$a], $tokens[$b]);
                if ($sim < self::TITLE_WITH_QUERIES_THRESHOLD) {
                    continue;
                }
                $shared = [];
                if ($paths[$a] !== null && $paths[$b] !== null) {
                    $shared = array_values(array_intersect($queries[$paths[$a]] ?? [], $queries[$paths[$b]] ?? []));
                }
                $byTitle = $sim >= self::TITLE_THRESHOLD;
                $byQueries = count($shared) >= self::SHARED_QUERIES_MIN;
                if (! $byTitle && ! $byQueries) {
                    continue;
                }
                $edges[] = ['a' => $a, 'b' => $b, 'sim' => $sim, 'shared' => $shared, 'by_title' => $byTitle];
                $parent[$find($a)] = $find($b);
            }
        }
        if ($edges === []) {
            return [];
        }

        $byRoot = [];
        foreach ($list as $post) {
            $byRoot[$find((string) $post->id)][] = $post;
        }
        $out = [];
        foreach ($byRoot as $root => $members) {
            if (count($members) < 2) {
                continue;
            }
            $ids = array_map(fn (Content $c): string => (string) $c->id, $members);
            $mine = array_values(array_filter($edges, fn (array $e): bool => in_array($e['a'], $ids, true) && in_array($e['b'], $ids, true)));
            $maxSim = max(array_map(fn (array $e): float => $e['sim'], $mine));
            $sharedAll = [];
            foreach ($mine as $e) {
                foreach ($e['shared'] as $q) {
                    $sharedAll[$q] = true;
                }
            }
            $anyByQueries = count(array_filter($mine, fn (array $e): bool => count($e['shared']) >= self::SHARED_QUERIES_MIN)) > 0;
            $signals = ['title: '.number_format($maxSim, 2)];
            if ($sharedAll !== []) {
                $names = array_slice(array_keys($sharedAll), 0, 3);
                $signals[] = 'queries: '.count($sharedAll).' shared ('.implode(', ', $names).(count($sharedAll) > 3 ? ', …' : '').')';
            }
            $group = new Collection($members);
            $first = $group->sortBy(fn (Content $c) => $c->published_at ?? $c->created_at)->first();
            $out[] = [
                'key' => $this->baseSlug((string) $first->slug),
                'kind' => $anyByQueries ? 'title+queries' : 'title',
                'signals' => $signals,
                'group' => $group,
            ];
        }

        return $out;
    }

    /** Title tokens for similarity: the question tokenizer, minus years ("2025", "2026") and bare numbers. @return list<string> */
    public static function titleTokens(string $title): array
    {
        return array_values(array_filter(QuestionCluster::tokens($title), fn (string $t): bool => preg_match('/^\d+$/', $t) !== 1));
    }

    /**
     * The queries each path took impressions for inside the window, from the per-query store.
     *
     * @return array<string, list<string>> normalized path → queries
     */
    private function queriesByPath(Site $site, int $days): array
    {
        $rows = DB::table('gsc_url_query_daily')
            ->where('site_id', $site->id)
            ->where('date', '>=', Carbon::now()->subDays($days)->toDateString())
            ->where('impressions', '>', 0)
            ->select('url', 'query')
            ->distinct()
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $path = $this->normalizePath((string) parse_url((string) $row->url, PHP_URL_PATH));
            $out[$path][mb_strtolower(trim((string) $row->query))] = true;
        }

        return array_map(fn (array $set): array => array_keys($set), $out);
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
