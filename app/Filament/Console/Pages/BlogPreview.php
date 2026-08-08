<?php

namespace App\Filament\Console\Pages;

use App\ContentEngine\Review\ReviewActions;
use App\OpsConsole\PostPreview;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Console → Operate → (hidden) Blog Preview: the read-only pre-publish render reached from the
 * Approved page's Preview button (`?content=<id>`). Shows exactly what will hit WordPress — hero
 * image, drafted body, internal links both ways, SEO, towns ({@see PostPreview}) — and offers the
 * two release controls in context (Send to Publish / Send back to Review). Not in the nav; it is a
 * detail surface, always entered with a content id.
 */
class BlogPreview extends ConsolePage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-eye';

    protected static ?string $slug = 'blog/preview';

    protected string $view = 'filament.console.blog-preview';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** The content id being previewed (from the query string). */
    public ?string $content = null;

    public function mount(): void
    {
        parent::mount();
        $this->content = request()->query('content');
    }

    public function getTitle(): string
    {
        return 'Preview';
    }

    /** @return array<string, mixed>|null The preview view model, or null if the post isn't owned/found. */
    public function getPreviewProperty(): ?array
    {
        if ($this->content === null) {
            return null;
        }
        $post = $this->ownedPost($this->content);
        if ($post === null) {
            return null;
        }

        return app(PostPreview::class)->for($post);
    }

    /** Send to Publish from the preview, then return to the Approved list. */
    public function release(): void
    {
        if ($this->content === null || ! $this->can(Capability::ApproveContent)) {
            return;
        }
        $post = $this->ownedPost($this->content);
        if ($post === null) {
            return;
        }

        $result = app(ReviewActions::class)->release($post, Auth::id());
        if ($result->isBlocked()) {
            Notification::make()->title('Can’t send to Publish yet')->body($result->blockedReason)->danger()->send();

            return;
        }

        Notification::make()->title('Sent to Publish.')->success()->send();
        $this->redirect(BlogApproved::getUrl());
    }

    /** Send back to Review from the preview, then return to the Approved list. */
    public function sendBack(): void
    {
        if ($this->content === null || ! $this->can(Capability::EditContent)) {
            return;
        }
        $post = $this->ownedPost($this->content);
        if ($post === null) {
            return;
        }

        app(ReviewActions::class)->returnToReview($post, Auth::id());

        Notification::make()->title('Sent back to Review.')->send();
        $this->redirect(BlogApproved::getUrl());
    }
}
