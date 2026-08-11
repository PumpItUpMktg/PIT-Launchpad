<?php

namespace App\OpsConsole;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Guided\LiveMetrics;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Links\InternalLinkGraph;
use App\Support\PublicUrl;
use Illuminate\Support\Collection;

/**
 * The console's "what's live" read model, bucketed by the surfaces the Published page shows as tabs:
 *
 *  - `blog`        — live blog POSTS (kind=post).
 *  - `core`        — the core site pages (Home / Utility / Pillar / Hub / Cluster).
 *  - `service`     — service pages (page_type=service).
 *  - `storefronts` — one entry per base {@see Location} (a brick-and-mortar / GMB hub): its landing
 *                    page (the "storefront page", page_type=location with `location_id` pinned) plus the
 *                    town pages nested under it (page_type=location with `parent_location_id` pinned).
 *                    The Storefront-Pages tab reads each entry's `hub`; the Location-Pages tab reads each
 *                    entry's `towns`, one sub-tab per storefront.
 *
 * Every item — post OR page — is the SAME rich card ({@see card()}): the Live tracking block ({@see
 * LiveMetrics}: index coverage, GSC/Bing impressions/clicks/CTR + "found in search for" queries, GA4
 * sessions, target keyword + position), the score the engine assigned (so an operator can watch whether
 * high scores actually earn rank/index), the target page it supports, its silo, brick-and-mortar towns
 * (posts), and its internal links both ways. Read-only.
 */
class PublishedContentBoard
{
    /** Page types that belong on the "Core Pages" tab — everything that isn't Service or Location. */
    private const CORE_TYPES = [PageType::Home, PageType::Utility, PageType::Pillar, PageType::Hub, PageType::Cluster];

    public function __construct(
        private readonly LiveMetrics $metrics,
        private readonly StorefrontTowns $storefrontTowns,
    ) {}

    /**
     * @return array{blog: list<array<string, mixed>>, core: list<array<string, mixed>>, service: list<array<string, mixed>>, storefronts: list<array<string, mixed>>}
     */
    public function forSite(?string $siteId, ?string $siloId = null): array
    {
        $empty = ['blog' => [], 'core' => [], 'service' => [], 'storefronts' => []];
        if ($siteId === null) {
            return $empty;
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return $empty;
        }
        $domain = $site->domain_url;

        $live = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('status', ContentStatus::Published->value)
            ->with(['site', 'matchedSilo', 'silo', 'renderJobs', 'targetKeyword.targetContent'])
            ->orderByDesc('published_at')
            ->orderByDesc('updated_at')
            ->get();

        if ($live->isEmpty()) {
            return $empty;
        }

        // Shared context built ONCE and reused across every card (link graph + storefront-town map).
        $graph = app(InternalLinkGraph::class)->build($site);
        $townMap = $this->storefrontTowns->targetTowns($site, null, null);

        $posts = $live->where('kind', ContentKind::Post)
            ->when($siloId !== null, fn (Collection $c) => $c->where('matched_silo_id', $siloId))
            ->values();

        $pages = $live->where('kind', ContentKind::Page)
            ->when($siloId !== null, fn (Collection $c) => $c->where('silo_id', $siloId))
            ->values();

        $core = $pages->filter(fn (Content $c): bool => in_array($c->page_type, self::CORE_TYPES, true))->values();
        $service = $pages->where('page_type', PageType::Service)->values();
        $locationPages = $pages->where('page_type', PageType::Location)->values();

        return [
            'blog' => $posts->map(fn (Content $c): array => $this->card($c, $domain, $graph, $townMap, true))->all(),
            'core' => $core->map(fn (Content $c): array => $this->card($c, $domain, $graph, $townMap, false))->all(),
            'service' => $service->map(fn (Content $c): array => $this->card($c, $domain, $graph, $townMap, false))->all(),
            'storefronts' => $this->groupStorefronts($locationPages, $siteId, $domain, $graph, $townMap),
        ];
    }

    /**
     * Group location pages under their base location: the pinned hub page ({@see Content::$location_id})
     * and the town pages nested beneath it ({@see Content::$parent_location_id}). Storefronts sort first,
     * then by name; a location page with neither pin lands under a trailing "Unassigned" group so it is
     * never silently dropped.
     *
     * @return list<array{location_id: string, name: string, is_storefront: bool, gbp_linked: bool, hub: array<string, mixed>|null, towns: list<array<string, mixed>>}>
     */
    private function groupStorefronts(Collection $locationPages, string $siteId, ?string $domain, InternalLinkGraph $graph, array $townMap): array
    {
        if ($locationPages->isEmpty()) {
            return [];
        }

        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->get()
            ->keyBy(fn (Location $l): string => (string) $l->id);

        /** @var array<string, array{hub: ?Content, towns: list<Content>}> $byLocation */
        $byLocation = [];
        foreach ($locationPages as $page) {
            $locId = match (true) {
                $page->location_id !== null => (string) $page->location_id,
                $page->parent_location_id !== null => (string) $page->parent_location_id,
                default => '__unassigned',
            };
            $byLocation[$locId] ??= ['hub' => null, 'towns' => []];
            if ($page->location_id !== null) {
                $byLocation[$locId]['hub'] = $page;
            } else {
                $byLocation[$locId]['towns'][] = $page;
            }
        }

        $out = [];
        foreach ($byLocation as $locId => $bucket) {
            $loc = $locId !== '__unassigned' ? $locations->get($locId) : null;
            $locName = $loc?->name;
            $hubTitle = $bucket['hub']?->title;
            $name = $locName ?? $hubTitle ?? 'Unassigned';
            $out[] = [
                'location_id' => $locId,
                'name' => trim((string) $name),
                'is_storefront' => (bool) $loc?->is_storefront,
                'gbp_linked' => $loc !== null && (trim((string) $loc->place_id) !== '' || trim((string) $loc->gbp_url) !== ''),
                'hub' => $bucket['hub'] instanceof Content ? $this->card($bucket['hub'], $domain, $graph, $townMap, false) : null,
                'towns' => array_map(fn (Content $c): array => $this->card($c, $domain, $graph, $townMap, false), $bucket['towns']),
            ];
        }

        usort($out, fn (array $a, array $b): int => ($b['is_storefront'] <=> $a['is_storefront']) ?: strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * The one card shape every tab renders. $isPost gates the brick-and-mortar town scan (a copy scan
     * that only makes sense for a blog post); pages carry no towns.
     *
     * @param  array<string, string>  $townMap
     * @return array<string, mixed>
     */
    private function card(Content $c, ?string $domain, InternalLinkGraph $graph, array $townMap, bool $isPost): array
    {
        $target = $c->targetKeyword?->targetContent;
        $siloName = $c->matchedSilo?->name;

        return [
            'id' => (string) $c->id,
            'title' => (string) $c->title,
            'url' => PublicUrl::forContent($domain, $c),
            'page_type' => $c->page_type?->value,
            'published_at' => $c->published_at?->toDateString(),
            'days_live' => $c->published_at !== null ? (int) $c->published_at->diffInDays(now()) : null,
            'locked' => (bool) $c->locked,
            // IndexNow submission ack (a "submitted", not an earned index) — the "Submitted to Bing" pill.
            'indexnow_at' => $c->indexnow_submitted_at?->toDateString(),
            // The engine's score — shown so an operator can track score → real ranking/indexing outcome.
            'score' => $c->relevance_score !== null ? (float) $c->relevance_score : null,
            // The target longtail + the page that longtail is meant to win (the "target page").
            'keyword' => $c->targetKeyword?->query,
            'target_page' => $target instanceof Content
                ? ['title' => (string) $target->title, 'url' => PublicUrl::forContent($domain, $target)]
                : null,
            'silo' => $siloName ?? $c->silo?->name,
            'towns' => $isPost ? $this->storefrontTowns->matchTowns($c, $townMap) : [],
            'image' => PostThumbnail::for($c),
            'links' => $this->links($c, $graph, $domain),
            'metrics' => $this->metrics->for($c),
        ];
    }

    /**
     * The content's internal links both ways: what it links to (outbound) and what links to it (inbound),
     * each as {title, url}.
     *
     * @return array{outbound: list<array{title: string, url: ?string}>, inbound: list<array{title: string, url: ?string}>}
     */
    private function links(Content $content, InternalLinkGraph $graph, ?string $domain): array
    {
        $ref = function (string $id) use ($graph, $domain): ?array {
            $c = $graph->pages->get($id);

            return $c instanceof Content ? ['title' => (string) $c->title, 'url' => PublicUrl::forContent($domain, $c)] : null;
        };

        return [
            'outbound' => array_values(array_filter(array_map($ref, $graph->outbound((string) $content->id)))),
            'inbound' => array_values(array_filter(array_map($ref, $graph->inbound((string) $content->id)))),
        ];
    }
}
