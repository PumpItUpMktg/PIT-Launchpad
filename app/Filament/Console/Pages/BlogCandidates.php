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
 * Console → Operate → Candidates: the first stage of the blog pipeline. Lists the routed candidates for
 * the active site (via the existing {@see BlogBoard::candidates()}) and offers the same two triage
 * actions the operator board does — "Generate" ({@see GeneratePost::enqueue()}) and "Dismiss"
 * ({@see ReviewActions::reject()}). No new behavior; a separate, focused surface per the console IA
 * (Candidates → Review → Publish as three distinct pages).
 */
class BlogCandidates extends ConsolePage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Candidates';

    protected static string|\UnitEnum|null $navigationGroup = 'Operate';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'blog/candidates';

    protected string $view = 'filament.console.blog-candidates';

    public function getTitle(): string
    {
        return 'Candidates';
    }

    /** @return list<array<string, mixed>> */
    public function getCandidatesProperty(): array
    {
        return app(BlogBoard::class)->candidates($this->siteId, $this->siloId);
    }

    /** Generate a draft from a candidate (Sonnet + fal, on the worker). */
    public function promote(string $contentId): void
    {
        if (! $this->can(Capability::GenerateContent)) {
            return;
        }
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        GeneratePost::enqueue($content, actorId: Auth::id());

        Notification::make()->title('Generating — it will appear in Review shortly.')->success()->send();
    }

    /** Drop a candidate out of the funnel. */
    public function dismiss(string $contentId): void
    {
        if (! $this->can(Capability::EditContent)) {
            return;
        }
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        app(ReviewActions::class)->reject($content, 'Dismissed at candidate triage');

        Notification::make()->title('Candidate dismissed.')->send();
    }
}
