<?php

namespace App\Operator\Coverage;

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
 * READ-ONLY. Published-only (candidates / needs_review aren't live and aren't cannibalising anything; the
 * 30-day expiry clears them, and the source fix stops them reaching publish). GSC window default 28d.
 */
final class DuplicatePostMetrics
{
    private const WINDOW_DAYS = 28;

    /**
     * @return list<array{
     *   key: string, title: string,
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

        $groups = $posts
            ->groupBy(fn (Content $c): string => $this->baseSlug((string) $c->slug))
            ->filter(fn (Collection $g): bool => $g->count() > 1);

        if ($groups->isEmpty()) {
            return [];
        }

        $domain = $site->domain_url;
        $gsc = $this->gscByPath($site, max(1, $days));

        $out = [];
        foreach ($groups as $base => $group) {
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
                'age_keeper_id' => (string) $ageKeeper->id,
                'earner_id' => $earnerId,
                // The age rule picks wrong when a clear earner exists and it is NOT the age-keeper.
                'age_conflict' => $earnerId !== null && $earnerId !== (string) $ageKeeper->id,
                'members' => $members,
            ];
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
