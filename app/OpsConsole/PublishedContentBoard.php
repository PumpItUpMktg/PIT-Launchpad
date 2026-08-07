<?php

namespace App\OpsConsole;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Guided\LiveMetrics;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Links\InternalLinkGraph;
use App\Support\PublicUrl;

/**
 * The console's "what's live" read model. Blog POSTS get the full Live-page tracking card ({@see
 * LiveMetrics}: index coverage, GSC impressions/clicks/CTR + "found in search for" queries, Bing, GA4
 * sessions, target keyword + position) PLUS the console extras — its silo, the brick-and-mortar towns it
 * covers ({@see StorefrontTowns}), and its internal links both ways ({@see InternalLinkGraph}: what the
 * post links to, and what pages link to it). Site PAGES stay a simple index line. Read-only.
 */
class PublishedContentBoard
{
    public function __construct(
        private readonly LiveMetrics $metrics,
        private readonly StorefrontTowns $storefrontTowns,
    ) {}

    /**
     * @return array{posts: list<array<string, mixed>>, pages: list<array<string, mixed>>}
     */
    public function forSite(?string $siteId, ?string $siloId = null): array
    {
        if ($siteId === null) {
            return ['posts' => [], 'pages' => []];
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return ['posts' => [], 'pages' => []];
        }
        $domain = $site->domain_url;

        $live = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('status', ContentStatus::Published->value)
            ->with(['site', 'matchedSilo', 'silo'])
            ->orderByDesc('published_at')
            ->orderByDesc('updated_at')
            ->get();

        $posts = $live->where('kind', ContentKind::Post)
            ->when($siloId !== null, fn ($c) => $c->where('matched_silo_id', $siloId))
            ->values();
        $pages = $live->where('kind', ContentKind::Page)
            ->when($siloId !== null, fn ($c) => $c->where('silo_id', $siloId))
            ->values();

        // Build the shared context ONCE (link graph + storefront-town map) — reused across every post card.
        $graph = $posts->isNotEmpty() ? app(InternalLinkGraph::class)->build($site) : null;
        $townMap = $posts->isNotEmpty() ? $this->storefrontTowns->targetTowns($site, null, null) : [];

        return [
            'posts' => $posts->map(fn (Content $c): array => $this->postCard($c, $domain, $graph, $townMap))->all(),
            'pages' => $pages->map(fn (Content $c): array => $this->pageCard($c, $domain))->all(),
        ];
    }

    /**
     * @param  array<string, string>  $townMap
     * @return array<string, mixed>
     */
    private function postCard(Content $post, ?string $domain, ?InternalLinkGraph $graph, array $townMap): array
    {
        return [
            'id' => (string) $post->id,
            'title' => (string) $post->title,
            'url' => PublicUrl::forContent($domain, $post),
            'published_at' => $post->published_at?->toDateString(),
            'days_live' => $post->published_at !== null ? (int) $post->published_at->diffInDays(now()) : null,
            'locked' => (bool) $post->locked,
            // IndexNow submission ack (a "submitted", not an earned index) — the "Submitted to Bing" pill.
            'indexnow_at' => $post->indexnow_submitted_at?->toDateString(),
            'silo' => $post->matchedSilo?->name,
            'towns' => $this->storefrontTowns->matchTowns($post, $townMap),
            'links' => $this->links($post, $graph, $domain),
            'metrics' => $this->metrics->for($post),
        ];
    }

    /** @return array<string, mixed> */
    private function pageCard(Content $page, ?string $domain): array
    {
        return [
            'id' => (string) $page->id,
            'title' => (string) $page->title,
            'url' => PublicUrl::forContent($domain, $page),
            'page_type' => $page->page_type?->value,
            'silo' => $page->silo?->name,
            'published_at' => optional($page->published_at ?? $page->updated_at)->format('M j, Y'),
        ];
    }

    /**
     * The post's internal links both ways: what it links to (outbound) and what links to it (inbound),
     * each as {title, url}. Empty when the graph isn't built.
     *
     * @return array{outbound: list<array{title: string, url: ?string}>, inbound: list<array{title: string, url: ?string}>}
     */
    private function links(Content $post, ?InternalLinkGraph $graph, ?string $domain): array
    {
        if ($graph === null) {
            return ['outbound' => [], 'inbound' => []];
        }

        $ref = function (string $id) use ($graph, $domain): ?array {
            $c = $graph->pages->get($id);

            return $c instanceof Content ? ['title' => (string) $c->title, 'url' => PublicUrl::forContent($domain, $c)] : null;
        };

        return [
            'outbound' => array_values(array_filter(array_map($ref, $graph->outbound((string) $post->id)))),
            'inbound' => array_values(array_filter(array_map($ref, $graph->inbound((string) $post->id)))),
        ];
    }
}
