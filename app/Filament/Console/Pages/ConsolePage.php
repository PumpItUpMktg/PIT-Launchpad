<?php

namespace App\Filament\Console\Pages;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Operate\BlogBoard;
use App\OpsConsole\ConsoleContext;
use App\OpsConsole\StorefrontTowns;
use App\Security\Capability;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Shared base for every Operations Console page. It owns the two things every console surface needs:
 * the active-site selection ({@see ConsoleContext} — a switcher scoped to what the user may see) and
 * capability checks ({@see Capability}), so a page can hide a control a Site Admin may not use with a
 * single `$this->can(...)`. Purely a host for the existing operating services — no new pipeline behavior.
 */
abstract class ConsolePage extends Page
{
    /** The console's active site id (bound to the switcher; persisted via ConsoleContext). */
    public ?string $siteId = null;

    /** Optional silo filter for the blog pages (null = all silos). */
    public ?string $siloId = null;

    /** Optional brick-and-mortar area filter: a storefront county, then a town within it. */
    public ?string $county = null;

    public ?string $town = null;

    public function mount(): void
    {
        $this->siteId = app(ConsoleContext::class)->current($this->user())?->id;
    }

    /** Persist a switcher change; computed board props re-resolve on the new site automatically. */
    public function updatedSiteId(): void
    {
        $user = $this->user();
        if ($this->siteId !== null && ! app(ConsoleContext::class)->select($user, $this->siteId)) {
            // Refused (not visible) — fall back to the resolved site.
            $this->siteId = app(ConsoleContext::class)->current($user)?->id;
        }
        // Filters belong to one tenant — clear them when the tenant changes.
        $this->siloId = null;
        $this->county = null;
        $this->town = null;
    }

    /** Picking a county resets the town within it. */
    public function updatedCounty(): void
    {
        $this->town = null;
    }

    /** @return array<string, string> silo id => name for the current site (blog-page filter). */
    public function getSiloFilterOptionsProperty(): array
    {
        return app(BlogBoard::class)->siloOptions($this->siteId);
    }

    /**
     * The storefront (brick-and-mortar) counties → towns cascade for the current site.
     *
     * @return list<array{geoid: string, name: string, towns: list<array{key: string, display: string}>}>
     */
    public function getStorefrontCountiesProperty(): array
    {
        $site = $this->currentSite();

        return $site === null ? [] : app(StorefrontTowns::class)->counties($site);
    }

    /** Whether this page offers the brick-and-mortar town filter (only where posts have body/tags). */
    public function supportsTownFilter(): bool
    {
        return false;
    }

    /**
     * Enrich BlogBoard cards (each has an `id`) with the console extras every blog card shows: the
     * storefront towns the post covers (chips), plus its silo and target keyword when the card doesn't
     * already carry them (the Publish card doesn't). Loads the underlying posts ONCE for the whole list.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    protected function enrichBlogCards(array $cards): array
    {
        if ($cards === []) {
            return $cards;
        }
        $site = $this->currentSite();
        if ($site === null) {
            return array_map(fn (array $c): array => $c + ['towns' => []], $cards);
        }

        $storefront = app(StorefrontTowns::class);
        $townMap = $storefront->targetTowns($site, null, null);

        $ids = array_map(fn (array $c): string => (string) $c['id'], $cards);
        $posts = Content::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $ids)
            ->with(['matchedSilo', 'targetKeyword'])
            ->get(['id', 'title', 'body', 'matched_silo_id', 'target_keyword_id'])
            ->keyBy(fn (Content $c): string => (string) $c->id);

        return array_map(function (array $c) use ($posts, $storefront, $townMap): array {
            $post = $posts->get((string) $c['id']);
            $c['towns'] = $post instanceof Content ? $storefront->matchTowns($post, $townMap) : [];
            $c['silo'] ??= $post?->matchedSilo?->name;
            $c['keyword'] ??= $post?->targetKeyword?->query;

            return $c;
        }, $cards);
    }

    /**
     * Filter ENRICHED cards (carrying a `towns` list) down to those covering the selected storefront
     * county/town. A no-op when no area filter is set or the page doesn't support it.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    protected function filterByStorefrontTown(array $cards): array
    {
        if (! $this->supportsTownFilter() || ($this->county === null && $this->town === null)) {
            return $cards;
        }
        $site = $this->currentSite();
        if ($site === null) {
            return $cards;
        }

        $targets = array_values(app(StorefrontTowns::class)->targetTowns($site, $this->county, $this->town));
        if ($targets === []) {
            return [];
        }

        return array_values(array_filter(
            $cards,
            fn (array $c): bool => array_intersect($c['towns'] ?? [], $targets) !== [],
        ));
    }

    /** @return array<string, string> id => name for the site switcher. */
    public function getSiteOptionsProperty(): array
    {
        return app(ConsoleContext::class)->options($this->user());
    }

    public function currentSite(): ?Site
    {
        return $this->siteId !== null ? Site::query()->find($this->siteId) : null;
    }

    /** Whether the signed-in user holds a capability (drives which controls render). */
    public function can(Capability $capability): bool
    {
        return $this->user()->hasCapability($capability);
    }

    /** Only console users reach these pages; a page with no visible site is still valid (empty state). */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasCapability(Capability::ViewDashboards);
    }

    /**
     * Load a post the current user may act on: kind=post, within a site they can see. The guard every
     * mutating action runs first — the same ownership check the operator board uses, plus tenant scope.
     */
    protected function ownedPost(string $contentId): ?Content
    {
        $post = $this->ownedContent($contentId);

        return $post?->kind === ContentKind::Post ? $post : null;
    }

    /** Load any content (post OR page) the current user may act on, within a site they can see. */
    protected function ownedContent(string $contentId): ?Content
    {
        $content = Content::withoutGlobalScope(SiteScope::class)->whereKey($contentId)->first();

        if ($content === null || ! $this->user()->canSeeSite((string) $content->site_id)) {
            return null;
        }

        return $content;
    }

    protected function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
