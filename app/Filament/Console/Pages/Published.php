<?php

namespace App\Filament\Console\Pages;

use App\Filament\Console\Concerns\SwapsPostImage;
use App\Jobs\PublishContent;
use App\OpsConsole\PublishedContentBoard;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Console → Published: the live body of work for the active site — blog posts and site pages that are
 * on WordPress — in its own area, separate from the ready-to-publish queue. Read model via
 * {@see PublishedContentBoard}; the only action is Repush (re-sync a live page, the existing idempotent
 * {@see PublishContent} job), gated on the publish capability.
 */
class Published extends ConsolePage
{
    use SwapsPostImage;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Published';

    protected static string|\UnitEnum|null $navigationGroup = 'Published';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'published';

    protected string $view = 'filament.console.published';

    public function getTitle(): string
    {
        return 'Published';
    }

    public function supportsTownFilter(): bool
    {
        return true;
    }

    /** Which tab is showing: blog | core | service | storefront | location. */
    public string $activeTab = 'blog';

    /** Location-Pages tab: which storefront's town pages are showing (base-location id). */
    public ?string $activeStorefront = null;

    /**
     * @return array{blog: list<array<string, mixed>>, core: list<array<string, mixed>>, service: list<array<string, mixed>>, storefronts: list<array<string, mixed>>}
     */
    public function getBoardProperty(): array
    {
        $board = app(PublishedContentBoard::class)->forSite($this->siteId, $this->siloId);

        // The brick-and-mortar town filter is a blog-post filter (it scans post copy); pages pass through.
        $board['blog'] = $this->filterByStorefrontTown($board['blog']);

        return $board;
    }

    /** Show a tab. The Location tab's storefront sub-tab defaults to the first with towns in the view. */
    public function showTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    /** Clear the storefront sub-tab selection when the tenant changes (it belongs to one site). */
    public function updatedSiteId(): void
    {
        parent::updatedSiteId();
        $this->activeStorefront = null;
    }

    /** Re-sync a live page/post to WordPress (idempotent by ULID). */
    public function repush(string $contentId): void
    {
        if (! $this->can(Capability::PublishContent)) {
            return;
        }
        $content = $this->ownedContent($contentId);
        if ($content === null || $content->wp_post_id === null) {
            return;
        }

        PublishContent::dispatch((string) $content->id, Auth::id());

        Notification::make()->title('Re-syncing to WordPress.')->success()->send();
    }
}
