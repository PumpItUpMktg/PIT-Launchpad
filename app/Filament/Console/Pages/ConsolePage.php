<?php

namespace App\Filament\Console\Pages;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Operate\BlogBoard;
use App\OpsConsole\ConsoleContext;
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
        // A silo belongs to one site — clear the filter when the tenant changes.
        $this->siloId = null;
    }

    /** @return array<string, string> silo id => name for the current site (blog-page filter). */
    public function getSiloFilterOptionsProperty(): array
    {
        return app(BlogBoard::class)->siloOptions($this->siteId);
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
