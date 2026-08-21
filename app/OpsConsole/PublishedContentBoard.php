<?php

namespace App\OpsConsole;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Guided\LiveMetrics;
use App\Jobs\WarmLiveMetrics;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Links\InternalLinkGraph;
use App\Support\PublicUrl;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The console's "what's live" read model, bucketed by the surfaces the Published pages show:
 *
 *  - `blog`        — live blog POSTS (kind=post).
 *  - `core`        — the core site pages (Home / Utility / Pillar / Hub / Cluster).
 *  - `service`     — service pages (page_type=service).
 *  - `storefronts` — one entry per base {@see Location} (a brick-and-mortar / GMB hub): its landing
 *                    page (the "storefront page", page_type=location with `location_id` pinned) plus the
 *                    town pages nested under it (page_type=location with `parent_location_id` pinned).
 *                    The Storefront-Pages page reads each entry's `hub`; the Location-Pages page reads
 *                    each entry's `towns`, one sub-tab per storefront.
 *
 * Every item — post OR page — is the SAME rich card ({@see card()}): the Live tracking block ({@see
 * LiveMetrics}: index coverage, GSC/Bing impressions/clicks/CTR + "found in search for" queries, GA4
 * sessions, target keyword + position), the score the engine assigned, the target page it supports, its
 * silo, brick-and-mortar towns (posts), and its internal links both ways. Read-only.
 *
 * ## Render budget (why this isn't just a query)
 *
 * The live tracking block fans out to the GSC / GA4 / Bing vendor seams. Those are cache-backed (6h), but
 * a COLD cache — the state right after a deploy — would otherwise fetch per card, in series, inside the
 * web request, and a content-heavy page would blow past the origin timeout (a Cloudflare 504). Two guards
 * keep a render cheap and bounded:
 *
 *  1. **Section scoping.** Each Published page renders ONE bucket, so it asks for only that bucket
 *     ({@see forSite()}'s `$section`); the other buckets are skipped entirely. Off-section cards never
 *     cost a vendor call.
 *  2. **A wall-clock budget.** Within the requested bucket, once {@see budgetSeconds()} is spent the
 *     remaining cards render DEFERRED ({@see LiveMetrics::for()} `defer: true` — a zero-cost "Refreshing…"
 *     block) and a single {@see WarmLiveMetrics} pass is queued to warm the caches off-request, so the
 *     next load is complete and fast.
 */
class PublishedContentBoard
{
    /** Page types that belong on the "Core Pages" tab — everything that isn't Service or Location. */
    private const CORE_TYPES = [PageType::Home, PageType::Utility, PageType::Pillar, PageType::Hub, PageType::Cluster];

    /** Default seconds of live-metrics fetching a single render may spend before deferring the rest. */
    private const METRICS_BUDGET_SECONDS = 6.0;

    /** Wall-clock start of the current render, for the metrics budget. */
    private float $renderStart = 0.0;

    /** The site being rendered — so the deferral path can queue the warm pass. */
    private string $renderSiteId = '';

    /** Guards the warm dispatch to one per render (the cache lock throttles across renders). */
    private bool $warmDispatched = false;

    public function __construct(
        private readonly LiveMetrics $metrics,
        private readonly StorefrontTowns $storefrontTowns,
    ) {}

    /**
     * @param  string|null  $section  which bucket the caller will render (blog|core|service|storefront|
     *                                location). Only that bucket is built; the rest stay empty. Null builds
     *                                every bucket (used by the read-model tests and any all-in-one caller).
     * @return array{blog: list<array<string, mixed>>, core: list<array<string, mixed>>, service: list<array<string, mixed>>, storefronts: list<array<string, mixed>>}
     */
    public function forSite(?string $siteId, ?string $siloId = null, ?string $section = null): array
    {
        $this->warmDispatched = false;

        $empty = ['blog' => [], 'core' => [], 'service' => [], 'storefronts' => []];
        if ($siteId === null) {
            return $empty;
        }
        $this->renderSiteId = $siteId;

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return $empty;
        }
        $domain = $site->domain_url;

        $wants = fn (string $s): bool => $section === null || $section === $s;

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

        // Start the live-metrics budget clock HERE — after the (potentially multi-second, on a large
        // tenant) link-graph + town-map build — so that setup cost never steals the metrics budget and
        // forces every card to defer to "Refreshing…". The budget is for the vendor fetches, not the graph.
        $this->renderStart = microtime(true);

        $result = $empty;

        if ($wants('blog')) {
            $posts = $live->where('kind', ContentKind::Post)
                ->when($siloId !== null, fn (Collection $c) => $c->where('matched_silo_id', $siloId))
                ->values();
            $result['blog'] = $posts->map(fn (Content $c): array => $this->card($c, $domain, $graph, $townMap, true, true))->all();
        }

        if ($wants('core') || $wants('service') || $wants('storefront') || $wants('location')) {
            $pages = $live->where('kind', ContentKind::Page)
                ->when($siloId !== null, fn (Collection $c) => $c->where('silo_id', $siloId))
                ->values();

            if ($wants('core')) {
                $core = $pages->filter(fn (Content $c): bool => in_array($c->page_type, self::CORE_TYPES, true))->values();
                $result['core'] = $core->map(fn (Content $c): array => $this->card($c, $domain, $graph, $townMap, false, true))->all();
            }

            if ($wants('service')) {
                $service = $pages->where('page_type', PageType::Service)->values();
                $result['service'] = $service->map(fn (Content $c): array => $this->card($c, $domain, $graph, $townMap, false, true))->all();
            }

            if ($wants('storefront') || $wants('location')) {
                $locationPages = $pages->where('page_type', PageType::Location)->values();
                $result['storefronts'] = $this->groupStorefronts($locationPages, $siteId, $domain, $graph, $townMap, $section);
            }
        }

        return $result;
    }

    /**
     * Group location pages under their base location: the pinned hub page ({@see Content::$location_id})
     * and the town pages nested beneath it ({@see Content::$parent_location_id}). Storefronts sort first,
     * then by name; a location page with neither pin lands under a trailing "Unassigned" group so it is
     * never silently dropped. The hub card is fully computed for the Storefront-Pages section, the town
     * cards for the Location-Pages section; the other side is deferred (never shown there).
     *
     * @return list<array{location_id: string, name: string, is_storefront: bool, gbp_linked: bool, counties: list<string>, hub: array<string, mixed>|null, towns: list<array<string, mixed>>}>
     */
    private function groupStorefronts(Collection $locationPages, string $siteId, ?string $domain, InternalLinkGraph $graph, array $townMap, ?string $section): array
    {
        if ($locationPages->isEmpty()) {
            return [];
        }

        $hubFull = $section === null || $section === 'storefront';
        $townFull = $section === null || $section === 'location';

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
                'counties' => $loc !== null ? $this->storefrontTowns->servedCountyNames($loc) : [],
                'hub' => $bucket['hub'] instanceof Content ? $this->card($bucket['hub'], $domain, $graph, $townMap, false, $hubFull) : null,
                'towns' => array_map(fn (Content $c): array => $this->card($c, $domain, $graph, $townMap, false, $townFull), $bucket['towns']),
            ];
        }

        usort($out, fn (array $a, array $b): int => ($b['is_storefront'] <=> $a['is_storefront']) ?: strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * The one card shape every tab renders. $isPost gates the brick-and-mortar town scan (a copy scan
     * that only makes sense for a blog post); pages carry no towns. $full gates the live-metrics block:
     * a full card fetches live tracking (subject to the render budget), a non-full card renders it
     * deferred ("Refreshing…") for zero cost.
     *
     * @param  array<string, string>  $townMap
     * @return array<string, mixed>
     */
    private function card(Content $c, ?string $domain, InternalLinkGraph $graph, array $townMap, bool $isPost, bool $full): array
    {
        $target = $c->targetKeyword?->targetContent;
        $siloName = $c->matchedSilo?->name;
        $metrics = $this->metricsFor($c, $full);

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
            'metrics' => $metrics,
            'index_pill' => $this->indexPill($metrics, $c->indexnow_submitted_at !== null),
        ];
    }

    /**
     * The at-a-glance indexing pill for a live page — the drip signal: grey (no index signal yet) → yellow
     * (submitted to the engines, not indexed yet) → green (confirmed in Google). "Indexed" = the real
     * URL-Inspection verdict OR earned Search impressions; "Submitted" = pinged to IndexNow, or Google
     * already returned a (non-indexed) verdict for the URL.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{state: string, label: string, title: string}
     */
    private function indexPill(array $metrics, bool $indexNowSubmitted): array
    {
        $index = is_array($metrics['index'] ?? null) ? $metrics['index'] : [];
        $gsc = is_array($metrics['gsc'] ?? null) ? $metrics['gsc'] : [];

        if (($index['indexed'] ?? false) || ($gsc['in_google'] ?? false)) {
            $crawled = empty($index['last_crawled_at']) ? '' : ' — last crawled '.$index['last_crawled_at'];

            return ['state' => 'indexed', 'label' => 'Indexed', 'title' => 'Confirmed in Google'.$crawled];
        }

        if ($indexNowSubmitted || ($index['state'] ?? null) !== null) {
            $why = empty($index['label']) ? '' : ' ('.$index['label'].')';

            return ['state' => 'submitted', 'label' => 'Submitted', 'title' => 'Submitted to search — not indexed yet'.$why];
        }

        return ['state' => 'unsubmitted', 'label' => 'Not submitted', 'title' => 'No index signal yet — submit the sitemap and run an index audit'];
    }

    /**
     * The card's live tracking block. Off-section cards ($full=false) and on-section cards past the render
     * budget are DEFERRED (zero cost); an on-section card deferred by the budget flags a warm pass.
     *
     * @return array<string, mixed>
     */
    private function metricsFor(Content $c, bool $full): array
    {
        if (! $full) {
            return $this->metrics->for($c, defer: true);
        }

        if ($this->budgetExceeded()) {
            // A card the user IS looking at fell back — queue a warm pass (once per render) so the next
            // load has the real values, then render it deferred now.
            if (! $this->warmDispatched) {
                $this->warmDispatched = true;
                $this->dispatchWarm($this->renderSiteId);
            }

            return $this->metrics->for($c, defer: true);
        }

        return $this->metrics->for($c);
    }

    private function budgetExceeded(): bool
    {
        return (microtime(true) - $this->renderStart) > $this->budgetSeconds();
    }

    private function budgetSeconds(): float
    {
        return (float) config('launchpad.published_metrics_budget_seconds', self::METRICS_BUDGET_SECONDS);
    }

    /**
     * Queue one warm pass for the site, throttled to at most once per two minutes by a hold-and-expire
     * cache lock (the job is also {@see ShouldBeUnique}).
     */
    private function dispatchWarm(string $siteId): void
    {
        $lock = Cache::lock('warm-live-metrics:'.$siteId, 120);
        if ($lock->get()) {
            // Intentionally NOT released — the 120s TTL is the throttle window.
            WarmLiveMetrics::dispatch($siteId);
        }
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
