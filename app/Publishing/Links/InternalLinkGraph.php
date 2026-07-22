<?php

namespace App\Publishing\Links;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\Chrome\SiteProfileAssembler;
use Illuminate\Support\Collection;

/**
 * The site's internal-link graph, reconstructed from the CONTROL PLANE (no live WordPress fetch) — it
 * mirrors exactly what the composer emits when a page is published. Edges come from four sources, the
 * same ones the page templates render:
 *
 *  - NESTING: a child page ↔ its parent hub (service→silo-hub, town→location-hub via parent_content_id) —
 *    the hub grid down + the breadcrumb up.
 *  - SIBLING SPINE: a service spoke → its same-silo sibling spokes (the "related services" list).
 *  - POST LINKS: a blog post → its routed silo's pillar + the location pages of the towns it names.
 *  - INLINE BODY LINKS: any internal <a href="/slug"> woven into the drafted copy.
 *
 * It also records which pages the sitewide header/footer CHROME links (service hubs, company pages,
 * legal) — those always have an inbound link even if no page body points at them, so they are never
 * false-flagged as orphans.
 */
final class InternalLinkGraph
{
    /** @var Collection<string, Content> published pages + posts, keyed by id */
    public Collection $pages;

    /** @var array<string, array<string, true>> id → set of outbound target ids */
    private array $out = [];

    /** @var array<string, array<string, true>> id → set of inbound source ids */
    private array $in = [];

    /** @var array<string, true> slug set the chrome links (an implicit inbound) */
    private array $chromeSlugs = [];

    /** @var array<string, string> lowercased visible text per page id (for opportunity mining) */
    private array $text = [];

    /** @var array<string, string> slug → content id (published pages) */
    private array $slugToId = [];

    public function __construct(private readonly SiteProfileAssembler $profile) {}

    public function build(Site $site): self
    {
        $this->pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->get()
            ->keyBy(fn (Content $c): string => (string) $c->id);

        foreach ($this->pages as $id => $page) {
            $this->out[$id] = [];
            $this->in[$id] = [];
            $this->text[$id] = $this->visibleText($page);
            $slug = $this->slugKey((string) $page->slug);
            if ($slug !== '') {
                $this->slugToId[$slug] = (string) $id;
            }
        }

        foreach ($this->pages as $page) {
            $this->nestingEdges($page);
            $this->siloEdges($page, $site);
            $this->locationGridEdges($page, $site);
            $this->postEdges($page, $site);
            $this->inlineEdges($page, $site);
        }

        $this->chromeSlugs = $this->chromeLinkedSlugs($site);

        return $this;
    }

    /** @return list<string> outbound target ids for a page */
    public function outbound(string $id): array
    {
        return array_keys($this->out[$id] ?? []);
    }

    /** @return list<string> inbound source ids for a page */
    public function inbound(string $id): array
    {
        return array_keys($this->in[$id] ?? []);
    }

    /** Does the sitewide chrome (header/footer nav) link this page? Then it is never an orphan. */
    public function isChromeLinked(Content $page): bool
    {
        return isset($this->chromeSlugs[$this->slugKey((string) $page->slug)]);
    }

    public function text(string $id): string
    {
        return $this->text[$id] ?? '';
    }

    /** Does a page already link to a target id (any edge source)? */
    public function linksTo(string $fromId, string $toId): bool
    {
        return isset($this->out[$fromId][$toId]);
    }

    // ── edge builders ──────────────────────────────────────────────────────

    private function nestingEdges(Content $page): void
    {
        $parentId = (string) ($page->parent_content_id ?? '');
        if ($parentId !== '' && $this->pages->has($parentId)) {
            $this->edge((string) $page->id, $parentId); // child → hub (breadcrumb up)
            $this->edge($parentId, (string) $page->id); // hub → child (the grid down)
        }
    }

    /**
     * The silo spine — the links the hub/spoke composer actually emits, keyed on SILO MEMBERSHIP (not
     * URL nesting): a hub grids down to its spokes; a spoke's "related services" links up to the hub
     * (always) + up to 3 same-silo siblings.
     */
    private function siloEdges(Content $page, Site $site): void
    {
        if ($page->silo_id === null || $page->kind !== ContentKind::Page) {
            return;
        }

        if ($page->page_type === PageType::Hub) {
            $spokes = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('status', ContentStatus::Published->value)
                ->where('kind', ContentKind::Page->value)->where('page_type', PageType::Service->value)
                ->where('silo_id', $page->silo_id)->orderBy('title')->limit(12)->pluck('id');
            foreach ($spokes as $spokeId) {
                $this->edge((string) $page->id, (string) $spokeId); // hub → spoke (the grid)
            }

            return;
        }

        if ($page->page_type === PageType::Service) {
            $hubId = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('status', ContentStatus::Published->value)
                ->where('kind', ContentKind::Page->value)->where('page_type', PageType::Hub->value)
                ->where('silo_id', $page->silo_id)->value('id');
            if ($hubId !== null) {
                $this->edge((string) $page->id, (string) $hubId); // spoke → hub ("All X")
            }
            $siblings = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('status', ContentStatus::Published->value)
                ->where('kind', ContentKind::Page->value)->where('page_type', PageType::Service->value)
                ->where('silo_id', $page->silo_id)->whereKeyNot($page->id)->orderBy('title')->limit(3)->pluck('id');
            foreach ($siblings as $siblingId) {
                $this->edge((string) $page->id, (string) $siblingId); // spoke → sibling
            }
        }
    }

    /** A location hub grids down to the town pages it parents (the "areas we serve" list). */
    private function locationGridEdges(Content $page, Site $site): void
    {
        if ($page->page_type !== PageType::Location || $page->location_id === null) {
            return;
        }
        $towns = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('status', ContentStatus::Published->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id')
            ->where('parent_location_id', $page->location_id)
            ->pluck('id');
        foreach ($towns as $townId) {
            $this->edge((string) $page->id, (string) $townId);
        }
    }

    private function postEdges(Content $page, Site $site): void
    {
        if ($page->kind !== ContentKind::Post) {
            return;
        }

        // → the routed silo's pillar
        $siloId = $page->matched_silo_id ?? $page->silo_id;
        if ($siloId !== null) {
            $pillarId = (string) (Silo::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->whereKey($siloId)->value('pillar_content_id') ?? '');
            if ($pillarId !== '' && $this->pages->has($pillarId)) {
                $this->edge((string) $page->id, $pillarId);
            }
        }

        // → the location page of each town the post names
        $towns = is_array($page->meta['towns'] ?? null) ? $page->meta['towns'] : [];
        foreach ($towns as $town) {
            $key = $this->townKey((string) $town);
            if ($key === '') {
                continue;
            }
            foreach ($this->pages as $candidate) {
                if ($candidate->page_type === PageType::Location
                    && $candidate->primary_service_id === null
                    && $this->townKey((string) $candidate->title) === $key) {
                    $this->edge((string) $page->id, (string) $candidate->id);
                    break;
                }
            }
        }
    }

    private function inlineEdges(Content $page, Site $site): void
    {
        if (preg_match_all('/href="([^"]+)"/i', $this->rawHtml($page), $m) === false) {
            return;
        }
        foreach ($m[1] as $href) {
            $slug = $this->hrefToSlug((string) $href, $site);
            if ($slug !== '' && isset($this->slugToId[$slug]) && $this->slugToId[$slug] !== (string) $page->id) {
                $this->edge((string) $page->id, $this->slugToId[$slug]);
            }
        }
    }

    private function edge(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        $this->out[$from][$to] = true;
        $this->in[$to][$from] = true;
    }

    /** @return array<string, true> the slugs the chrome nav links (service hubs, company pages, legal). */
    private function chromeLinkedSlugs(Site $site): array
    {
        $profile = $this->profile->assemble($site);
        $slugs = [];
        foreach (['services', 'company', 'nav', 'legal_links'] as $group) {
            foreach ((array) ($profile[$group] ?? []) as $link) {
                $slug = $this->slugKey((string) ($link['url'] ?? ''));
                if ($slug !== '') {
                    $slugs[$slug] = true;
                }
            }
        }

        return $slugs;
    }

    // ── text / url helpers ─────────────────────────────────────────────────

    /** The page's visible copy (title + drafted body / slot strings), lowercased, tags stripped. */
    private function visibleText(Content $page): string
    {
        $raw = trim((string) $page->title).' '.$this->rawHtml($page);

        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', strip_tags($raw))));
    }

    /** The page's drafted HTML — the post body, plus every string leaf of a page's slot payload. */
    private function rawHtml(Content $page): string
    {
        $parts = [is_string($page->body) ? $page->body : ''];
        $this->collectStrings(is_array($page->slot_payload) ? $page->slot_payload : [], $parts);

        return implode(' ', array_filter($parts, fn (string $p): bool => trim($p) !== ''));
    }

    /** @param array<int|string, mixed> $node @param list<string> $out */
    private function collectStrings(array $node, array &$out): void
    {
        foreach ($node as $value) {
            if (is_string($value)) {
                $out[] = $value;
            } elseif (is_array($value)) {
                $this->collectStrings($value, $out);
            }
        }
    }

    /** Resolve an href to a same-site page slug (internal only), or '' for an external / non-page link. */
    private function hrefToSlug(string $href, Site $site): string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return '';
        }
        $home = is_string($site->domain_url) ? rtrim($site->domain_url, '/') : '';
        if ($home !== '' && str_starts_with($href, $home)) {
            $href = substr($href, strlen($home));
        } elseif (str_starts_with($href, 'http')) {
            return ''; // a different site
        }

        return $this->slugKey($href);
    }

    /** Canonical slug key: last non-empty path segment, lowercased (nested slugs compare by their leaf). */
    private function slugKey(string $value): string
    {
        $path = trim((string) parse_url(trim($value), PHP_URL_PATH), '/');
        if ($path === '') {
            return '';
        }
        $segments = array_values(array_filter(explode('/', $path), fn (string $s): bool => $s !== ''));

        return $segments === [] ? '' : mb_strtolower(end($segments));
    }

    private function townKey(string $value): string
    {
        $name = trim(explode(',', trim($value), 2)[0]);

        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}\s]/u', '', $name));
    }
}
