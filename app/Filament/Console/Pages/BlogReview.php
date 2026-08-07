<?php

namespace App\Filament\Console\Pages;

use App\ContentEngine\Review\ReviewActions;
use App\Jobs\GeneratePost;
use App\Operate\BlogBoard;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Console → Operate → Review: the middle stage. Lists drafts awaiting review for the active site
 * ({@see BlogBoard::review()}) and offers the same review actions the operator queue does — Approve
 * ({@see ReviewActions::approve()}, the two-gate accept), Reject, and Regenerate
 * ({@see GeneratePost::enqueue()}). Approve moves a draft to READY-TO-PUBLISH; the actual push to
 * WordPress lives on the separate Publish page (live is kept apart from ready-to-publish, per the IA).
 */
class BlogReview extends ConsolePage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Review';

    protected static string|\UnitEnum|null $navigationGroup = 'Operate';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'blog/review';

    protected string $view = 'filament.console.blog-review';

    /** The draft currently being rejected (drives the inline reason box), and its reason. */
    public ?string $rejectingId = null;

    public string $rejectReason = '';

    public function getTitle(): string
    {
        return 'Review';
    }

    public function supportsTownFilter(): bool
    {
        return true;
    }

    /** @return list<array<string, mixed>> */
    public function getReviewProperty(): array
    {
        return $this->filterByStorefrontTown(app(BlogBoard::class)->review($this->siteId, $this->siloId));
    }

    /** Accept a draft into the ready-to-publish queue (does NOT push to WordPress). */
    public function approve(string $contentId): void
    {
        if (! $this->can(Capability::ApproveContent)) {
            return;
        }
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        $result = app(ReviewActions::class)->approve($content, Auth::id());

        if ($result->isBlocked()) {
            Notification::make()->title('Can’t approve yet')->body($result->blockedReason)->danger()->send();

            return;
        }

        $note = Notification::make()->title('Approved — ready to publish.')->success();
        if ($result->warnings !== []) {
            $note->body(implode(' ', $result->warnings));
        }
        $note->send();
    }

    /** Redraft a review item (Sonnet + fal on the worker); the slug is preserved. */
    public function regenerate(string $contentId): void
    {
        if (! $this->can(Capability::GenerateContent)) {
            return;
        }
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        GeneratePost::enqueue($content, actorId: Auth::id());

        Notification::make()->title('Regenerating — it will refresh here shortly.')->success()->send();
    }

    public function startReject(string $contentId): void
    {
        $this->rejectingId = $contentId;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectReason = '';
    }

    public function reject(): void
    {
        if (! $this->can(Capability::EditContent) || $this->rejectingId === null) {
            return;
        }
        $content = $this->ownedPost($this->rejectingId);
        if ($content === null) {
            $this->cancelReject();

            return;
        }

        app(ReviewActions::class)->reject($content, trim($this->rejectReason) !== '' ? trim($this->rejectReason) : 'Rejected in review');
        $this->cancelReject();

        Notification::make()->title('Draft rejected.')->send();
    }
}
