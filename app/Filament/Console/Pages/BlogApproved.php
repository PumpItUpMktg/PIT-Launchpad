<?php

namespace App\Filament\Console\Pages;

use App\ContentEngine\Review\ReviewActions;
use App\Filament\Console\Concerns\SwapsPostImage;
use App\Operate\BlogBoard;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Console → Operate → Approved: the preview / QA stage between Review and Publish (the "page in the
 * middle"). Lists approved posts that have NOT yet been released to the push-only Publish page
 * ({@see BlogBoard::approved()}). Here an operator previews the fully-assembled post — the
 * generate-time hero render, the drafted body, its internal links and SEO (via the read-only
 * {@see BlogPreview} page) — and then releases it with Send to Publish ({@see ReviewActions::release()}),
 * or pulls it back to Review. Nothing here pushes to WordPress; that stays on the Publish page.
 *
 * The split keeps Publish self-sufficient and push-only, so it can later be handed to clients as the
 * one surface they see while operators do all QA here.
 */
class BlogApproved extends ConsolePage
{
    use SwapsPostImage;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-eye';

    protected static ?string $navigationLabel = 'Approved';

    protected static string|\UnitEnum|null $navigationGroup = 'Operate';

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'blog/approved';

    protected string $view = 'filament.console.blog-approved';

    public function getTitle(): string
    {
        return 'Approved';
    }

    public function supportsTownFilter(): bool
    {
        return true;
    }

    public function supportsScoreFilter(): bool
    {
        return true;
    }

    /** @return list<array<string, mixed>> Approved-but-not-released posts, richest-card first. */
    public function getApprovedProperty(): array
    {
        return $this->filterByScore($this->filterByStorefrontTown($this->enrichBlogCards(app(BlogBoard::class)->approved($this->siteId, $this->siloId))));
    }

    /** Release an approved post to the push-only Publish queue (operator "Send to Publish"). */
    public function release(string $contentId): void
    {
        if (! $this->can(Capability::ApproveContent)) {
            return;
        }
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        $result = app(ReviewActions::class)->release($content, Auth::id());
        if ($result->isBlocked()) {
            Notification::make()->title('Can’t send to Publish yet')->body($result->blockedReason)->danger()->send();

            return;
        }

        $note = Notification::make()->title('Sent to Publish.')->success();
        if ($result->warnings !== []) {
            $note->body(implode(' ', $result->warnings));
        }
        $note->send();
    }

    /** Pull an approved post back to Review (operator changed their mind before releasing). */
    public function sendBack(string $contentId): void
    {
        if (! $this->can(Capability::EditContent)) {
            return;
        }
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        app(ReviewActions::class)->returnToReview($content, Auth::id());

        Notification::make()->title('Sent back to Review.')->send();
    }
}
