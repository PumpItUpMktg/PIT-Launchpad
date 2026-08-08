<?php

namespace App\Filament\Console\Pages;

use App\ContentEngine\Review\ReviewActions;
use App\Filament\Console\Concerns\SwapsPostImage;
use App\Operate\BlogBoard;
use App\Operate\QueueHealth;
use App\Publishing\PostPublisher;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Console → Operate → Publish: the ready-to-publish queue. Lists items that are approved (and any in
 * flight) for the active site via {@see BlogBoard::publishing()} and pushes them STRAIGHT to WordPress
 * on click. Normally that's the queued, idempotent {@see ReviewActions::publish()} (→ PublishContent
 * job); if the worker is stalled it falls back to the synchronous {@see PostPublisher} escape hatch —
 * the exact behavior the operator board uses. Live (published) posts live on a separate page.
 */
class BlogPublish extends ConsolePage
{
    use SwapsPostImage;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationLabel = 'Publish';

    protected static string|\UnitEnum|null $navigationGroup = 'Operate';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'blog/publish';

    protected string $view = 'filament.console.blog-publish';

    public function getTitle(): string
    {
        return 'Publish';
    }

    public function supportsTownFilter(): bool
    {
        return true;
    }

    public function supportsScoreFilter(): bool
    {
        return true;
    }

    /** @return list<array<string, mixed>> Ready-to-publish (approved) + in-flight posts. */
    public function getPublishingProperty(): array
    {
        return $this->filterByScore($this->filterByStorefrontTown($this->enrichBlogCards(app(BlogBoard::class)->publishing($this->siteId, $this->siloId))));
    }

    /** Push a ready post straight to WordPress (queued; inline if the worker is stalled). */
    public function publish(string $contentId): void
    {
        if (! $this->can(Capability::PublishContent)) {
            return;
        }
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        // Worker down → publish inline so the click still lands; otherwise the normal idempotent job.
        if (app(QueueHealth::class)->snapshot()['stalled']) {
            $result = app(PostPublisher::class)->publish($content, Auth::id());
            match (true) {
                $result->isPublished() => Notification::make()->title('Published to WordPress.')->success()->send(),
                $result->wasSkipped() => Notification::make()->title('Skipped — the page is locked or edited in WordPress.')->warning()->send(),
                $result->isBlocked() => Notification::make()->title('Can’t publish')->body($result->message)->danger()->send(),
                default => Notification::make()->title('Publish failed')->body($result->message)->danger()->send(),
            };

            return;
        }

        $result = app(ReviewActions::class)->publish($content, Auth::id());
        if ($result->isBlocked()) {
            Notification::make()->title('Can’t publish yet')->body($result->blockedReason)->danger()->send();

            return;
        }

        Notification::make()->title('Publishing — pushing to WordPress now.')->success()->send();
    }
}
