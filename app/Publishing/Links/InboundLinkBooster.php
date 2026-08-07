<?php

namespace App\Publishing\Links;

use App\ContentEngine\Linking\PostLinkInjector;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Redirects\GscUrlInventory;

/**
 * Accelerates a newly-published blog POST's indexing by weaving an inbound internal link to it FROM the
 * strongest already-indexed pages in its OWN silo. Google discovers and re-crawls a URL largely by
 * following links from pages it already crawls often; a fresh post with no inbound internal link waits on
 * the sitemap. Dropping a link from a page Google already earns impressions on lets the post inherit that
 * crawl path and index materially faster.
 *
 * The counterpart to {@see PostLinkInjector} (which links the post OUT to its
 * pillar + towns): this links the mesh IN to the post. It is deliberately tight:
 *
 *  - SILO-SCOPED — only pages in the post's own silo are considered (topical relevance, no link scheme).
 *  - GSC-RANKED — sources are ordered by real Search-Console impressions ({@see GscUrlInventory}); a page
 *    with zero impressions is not "already indexed" and never qualifies.
 *  - NATURAL ANCHOR ONLY — it wraps a phrase the source page ALREADY contains (via {@see LinkInjector},
 *    first unlinked whole-word occurrence). It never fabricates a sentence — no natural mention, no link.
 *  - CAPPED — at most `max_sources` (2) pages are linked per post.
 *  - SAFE — publish-protected (locked / locally-edited) pages are skipped; the repush is the platform's
 *    normal idempotent-by-ULID publish.
 *
 * Rollout gate ({@see config launchpad.internal_linking.inbound_boost.mode}): `revivals` fires only for
 * regenerated legacy posts (the initial wave); `all` generalizes to every post; `off` disables it. Runs
 * as a publish hook and never blocks the publish it follows — the post is already live.
 */
final class InboundLinkBooster
{
    public function __construct(
        private readonly LinkInjector $injector,
        private readonly GscUrlInventory $inventory,
    ) {}

    /** The shortest anchor phrase we'll wrap — mirrors the auditor's floor, guards against junk one-word links. */
    private const MIN_ANCHOR_LENGTH = 6;

    /**
     * Link the just-published post from up to `max_sources` of its silo's strongest indexed pages.
     *
     * @return list<string> ids of the source pages that were linked + repushed (empty when nothing matched)
     */
    public function boost(Content $post): array
    {
        $mode = (string) config('launchpad.internal_linking.inbound_boost.mode', 'revivals');
        if ($mode === 'off' || $post->kind !== ContentKind::Post) {
            return [];
        }
        // Rollout gate: the initial wave links only regenerated legacy posts; `all` generalizes it.
        if ($mode === 'revivals' && ! $this->isRevival($post)) {
            return [];
        }

        $siloId = $post->matched_silo_id ?? $post->silo_id;
        if ($siloId === null) {
            return [];
        }

        $site = Site::withoutGlobalScopes()->find($post->site_id);
        if (! $site instanceof Site) {
            return [];
        }

        $anchors = $this->anchorPhrases($post);
        $targetPath = $this->path($post);
        if ($anchors === [] || $targetPath === '/') {
            return [];
        }

        $max = max(1, (int) config('launchpad.internal_linking.inbound_boost.max_sources', 2));

        $linked = [];
        foreach ($this->rankedSources($site, (string) $siloId, (string) $post->id) as $page) {
            if (count($linked) >= $max) {
                break;
            }
            foreach ($anchors as $anchor) {
                if ($this->injector->inject($page, $anchor, $targetPath)) {
                    PublishContent::dispatch((string) $page->id);
                    $linked[] = (string) $page->id;
                    break;
                }
            }
        }

        return $linked;
    }

    /** A post regenerated from old-site URLs by the legacy reviver (either meta shape). */
    private function isRevival(Content $post): bool
    {
        $meta = $post->meta ?? [];

        return $meta['revived_from_urls'] ?? false
            ? true
            : is_string($meta['revived_from_url'] ?? null);
    }

    /**
     * Candidate link-text phrases a source page must already contain, best first. The reviver's winning
     * GSC query (`meta.revived_query` — the exact phrase the old page ranked for, most likely to appear in
     * a sibling silo page) leads; the post's own target keyword follows. Deduped, junk-short dropped.
     * Matching is case-insensitive ({@see LinkInjector}), so casing here doesn't matter.
     *
     * @return list<string>
     */
    private function anchorPhrases(Content $post): array
    {
        $meta = $post->meta ?? [];
        $candidates = [
            is_string($meta['revived_query'] ?? null) ? $meta['revived_query'] : '',
            (string) ($post->targetKeyword->query ?? ''),
        ];

        $seen = [];
        $anchors = [];
        foreach ($candidates as $candidate) {
            $phrase = trim($candidate);
            $key = mb_strtolower($phrase);
            if (mb_strlen($phrase) < self::MIN_ANCHOR_LENGTH || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $anchors[] = $phrase;
        }

        return $anchors;
    }

    /**
     * Published PAGES in the post's silo, ordered by GSC impressions (desc), keeping only those Google
     * already shows (impressions ≥ 1) and that we may repush (not locked / locally-edited). A page with
     * no impression history is not "already indexed" and is excluded — the whole point is to link from
     * crawl-strong sources.
     *
     * @return list<Content>
     */
    private function rankedSources(Site $site, string $siloId, string $excludeId): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('status', ContentStatus::Published->value)
            ->where('silo_id', $siloId)
            ->whereNotNull('wp_post_id')
            ->whereKeyNot($excludeId)
            ->get();

        if ($pages->isEmpty()) {
            return [];
        }

        $impressions = [];
        foreach ($this->inventory->urlTotals($site) as $row) {
            $impressions[$this->normalizePath($row['url'])] = $row['impressions'];
        }

        $min = max(1, (int) config('launchpad.internal_linking.inbound_boost.min_source_impressions', 1));

        return $pages
            ->map(fn (Content $page): array => [
                'page' => $page,
                'impressions' => $impressions[$this->normalizePath((string) $page->slug)] ?? 0,
            ])
            ->filter(fn (array $row): bool => ! $row['page']->isPublishProtected() && $row['impressions'] >= $min)
            ->sortByDesc('impressions')
            ->map(fn (array $row): Content => $row['page'])
            ->values()
            ->all();
    }

    /** The post's own link path — leading slash, mirrors the corrective {@see InternalLinkFixer}. */
    private function path(Content $post): string
    {
        return '/'.ltrim((string) $post->slug, '/');
    }

    /** Path key for matching a GSC url against a page slug: path only, trimmed of slashes, lowercased. */
    private function normalizePath(string $value): string
    {
        $parsed = parse_url(trim($value), PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $value;

        return mb_strtolower(trim($path, '/'));
    }
}
