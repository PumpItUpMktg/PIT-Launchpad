<?php

namespace App\Publishing\Blocks;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\ContentTown;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Publishing\Links\InternalLinkGraph;
use App\Publishing\MetaBlobAssembler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The ONE source of every "which posts does this page link to" answer — the silo feed on service and hub
 * pages, the local feed on location pages, the related articles on a post, and the full index on the
 * Blog page. Both the renderer ({@see BlockContentAssembler} / {@see MetaBlobAssembler})
 * and the internal-link graph ({@see InternalLinkGraph}) read these, so the
 * graph counts exactly the links the live site carries. Before this the feeds lived in the renderer
 * only: the six newest posts in a silo were linked and the graph said `links:0`; the seventh dropped out
 * of every feed and truly had none — sixteen published posts on Sump Pump Gurus with nothing pointing
 * at them.
 */
class BlogFeeds
{
    public const FEED_LIMIT = 6;

    public const RELATED_LIMIT = 3;

    /**
     * The recent PUBLISHED posts routed to a silo — a post's silo is its routed match (`matched_silo_id`)
     * else its own `silo_id`. Recency-ordered, capped.
     *
     * @return Collection<int, Content>
     */
    public function siloPosts(string $siteId, ?string $siloId, int $limit = self::FEED_LIMIT): Collection
    {
        if ($siloId === null) {
            return new Collection;
        }

        return $this->published($siteId)
            ->where(fn ($q) => $q->where('matched_silo_id', $siloId)
                ->orWhere(fn ($q2) => $q2->whereNull('matched_silo_id')->where('silo_id', $siloId)))
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * The recent PUBLISHED posts tagged with a town (the `content_towns` rows the town tagger writes,
     * matched on the normalized town name).
     *
     * @return Collection<int, Content>
     */
    public function localPosts(string $siteId, string $townKey, int $limit = self::FEED_LIMIT): Collection
    {
        $townKey = trim($townKey);
        if ($townKey === '') {
            return new Collection;
        }
        $ids = ContentTown::query()->where('site_id', $siteId)->where('town', $townKey)->pluck('content_id');
        if ($ids->isEmpty()) {
            return new Collection;
        }

        return $this->published($siteId)
            ->whereIn('id', $ids)
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * A post's related articles: other PUBLISHED posts in its silo, the ones sharing the most title words
     * first, newest first among equals. Rendered under the article, so every post links three siblings —
     * and an older post keeps collecting inbound links as newer ones publish, instead of losing its only
     * link when it falls out of a six-post feed.
     *
     * @return Collection<int, Content>
     */
    public function related(Content $post, int $limit = self::RELATED_LIMIT): Collection
    {
        $siloId = $post->matched_silo_id ?? $post->silo_id;
        if ($siloId === null) {
            return new Collection;
        }
        $candidates = $this->published((string) $post->site_id)
            ->where('id', '!=', $post->id)
            ->where(fn ($q) => $q->where('matched_silo_id', $siloId)
                ->orWhere(fn ($q2) => $q2->whereNull('matched_silo_id')->where('silo_id', $siloId)))
            ->get();

        $own = $this->tokens((string) $post->title);

        return $candidates
            ->map(fn (Content $c): array => ['post' => $c, 'shared' => count(array_intersect($own, $this->tokens((string) $c->title)))])
            ->sortBy([
                fn (array $a, array $b): int => $b['shared'] <=> $a['shared'],
                fn (array $a, array $b): int => ($b['post']->published_at?->getTimestamp() ?? 0) <=> ($a['post']->published_at?->getTimestamp() ?? 0),
                fn (array $a, array $b): int => strcmp((string) $a['post']->title, (string) $b['post']->title),
            ])
            ->take($limit)
            ->map(fn (array $row): Content => $row['post'])
            ->values();
    }

    /**
     * The Blog page's index: EVERY published post, grouped by silo (silo name; posts routed nowhere under
     * "More articles" last), newest first inside each group, groups by name. The page every post is
     * reachable from — the durable answer to a post that fell out of every feed.
     *
     * @return list<array{silo: string, posts: Collection<int, Content>}>
     */
    public function index(string $siteId): array
    {
        $posts = $this->published($siteId)->orderByDesc('published_at')->orderBy('title')->get();
        if ($posts->isEmpty()) {
            return [];
        }
        $names = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->pluck('name', 'id');

        $groups = [];
        foreach ($posts as $post) {
            $siloId = $post->matched_silo_id ?? $post->silo_id;
            $name = $siloId !== null ? trim((string) ($names[(string) $siloId] ?? '')) : '';
            $key = $name !== '' ? $name : "\u{10FFFF}"; // routed-nowhere sorts last
            $groups[$key] ??= ['silo' => $name !== '' ? $name : 'More articles', 'posts' => new Collection];
            $groups[$key]['posts']->push($post);
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($groups);
    }

    /** @return Builder<Content> */
    private function published(string $siteId)
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('kind', ContentKind::Post->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('slug');
    }

    /** @return list<string> the title's meaningful words, lower-cased */
    private function tokens(string $title): array
    {
        $stop = ['the', 'and', 'for', 'your', 'you', 'with', 'from', 'that', 'this', 'how', 'what', 'why', 'when', 'into', 'out', 'not'];
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($title)) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $w): bool => mb_strlen($w) > 2 && ! in_array($w, $stop, true))));
    }
}
