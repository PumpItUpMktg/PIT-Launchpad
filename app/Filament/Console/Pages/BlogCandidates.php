<?php

namespace App\Filament\Console\Pages;

use App\ContentEngine\BlogQueue\ManualCandidateIntake;
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

    // Manual-entry form (a hand-typed idea → a candidate on the board).
    public string $manualTitle = '';

    public ?string $manualSiloId = null;

    public string $manualTown = '';

    public function getTitle(): string
    {
        return 'Candidates';
    }

    public function supportsScoreFilter(): bool
    {
        return true;
    }

    /** @return list<array<string, mixed>> */
    public function getCandidatesProperty(): array
    {
        return $this->filterByScore(app(BlogBoard::class)->candidates($this->siteId, $this->siloId));
    }

    /**
     * The board's candidates grouped by silo (local-first within each), each capped to a visible few with a
     * "+N more" tail — so a firehose silo doesn't render a wall of cards. Score filter applies before grouping.
     *
     * @return list<array{silo: string, total: int, local: int, visible: list<array<string, mixed>>, overflow: int}>
     */
    public function getCandidateGroupsProperty(): array
    {
        $cap = (int) config('launchpad.reactive.candidate_board_group_cap', 8);

        return app(BlogBoard::class)->group($this->getCandidatesProperty(), $cap);
    }

    /** Add a hand-typed idea as a candidate on the board (source=manual, topical, cap-exempt). */
    public function addManual(): void
    {
        if (! $this->can(Capability::EditContent)) {
            return;
        }

        $site = $this->currentSite();
        if ($site === null) {
            return;
        }

        $siloId = $this->manualSiloId;
        if (trim($this->manualTitle) === '' || $siloId === null || $siloId === '') {
            Notification::make()->title('Give the idea a title and pick a silo.')->warning()->send();

            return;
        }

        $content = app(ManualCandidateIntake::class)->create($site, $this->manualTitle, $siloId, $this->manualTown ?: null);
        if ($content === null) {
            Notification::make()->title('Could not add — check the silo belongs to this site.')->danger()->send();

            return;
        }

        $this->manualTitle = '';
        $this->manualSiloId = null;
        $this->manualTown = '';
        Notification::make()->title('Added to the board.')->success()->send();
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
