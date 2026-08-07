<?php

namespace App\Filament\Console\Pages;

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

    /** @return array{posts: list<array<string, mixed>>, pages: list<array<string, mixed>>} */
    public function getBoardProperty(): array
    {
        return app(PublishedContentBoard::class)->forSite($this->siteId);
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
