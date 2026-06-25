<?php

namespace App\Filament\Pages\Guided;

use App\ContentEngine\Drafting\GroundingReadiness;
use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentStatus;
use App\Enums\SetupStep;
use App\Filament\Pages\ProofEditor;
use App\Guided\GrowDashboard;
use App\Guided\GuidedPage;
use App\Interview\Arrange\AutoArrangeRunner;
use App\Jobs\BuildStructure;
use App\Jobs\GeneratePage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Step 5 · the Active landing. Its primary content is the PAGES WORKBENCH — every planned page with
 * its build-out state + a morphing primary ([Generate] → [Review] → [Publish] → [View]), the surface
 * that makes on-demand generation reachable — plus the bulk Approve/Publish lanes ({@see GrowDashboard}).
 * The build-out counts ride above it as a header strip. The town queue (coverage layer + drip) and
 * fresh-content feed (news engine) are clearly-labeled "activates later" scaffolds, NOT primary. The
 * re-run controls (re-ground volume / re-arrange) reuse the engine with the §10 decision-preservation
 * twin (confirmed decisions survive).
 *
 * @property-read array{live: int, building: int, planned: int} $stats
 * @property-read list<array{id: string, title: string, permalink: string, state: string, tone: string, action: ?string, live_url: ?string, bulk: ?string}> $pages
 * @property-read array<int, array{title: string, status: string, silo: string}> $news
 */
class Grow extends GuidedPage
{
    protected static ?string $slug = 'grow';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Grow';

    protected string $view = 'filament.guided.grow';

    /**
     * Content ids ticked for a bulk lane (wire:model on the row checkboxes).
     *
     * @var array<int, string>
     */
    public array $selected = [];

    public function step(): SetupStep
    {
        return SetupStep::Grow;
    }

    /**
     * @return array{live: int, building: int, planned: int}
     */
    public function getStatsProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? ['live' => 0, 'building' => 0, 'planned' => 0] : app(GrowDashboard::class)->stats($site);
    }

    /**
     * @return list<array{id: string, title: string, permalink: string, state: string, tone: string, action: ?string, live_url: ?string, bulk: ?string}>
     */
    public function getPagesProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(GrowDashboard::class)->pages($site);
    }

    /**
     * @return array<int, array{title: string, status: string, silo: string}>
     */
    public function getNewsProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(GrowDashboard::class)->news($site);
    }

    /** The proof step (structured preview + review/approve) URL for a draft-ready row. */
    public function reviewUrl(string $contentId): string
    {
        return ProofEditor::getUrl(['content' => $contentId]);
    }

    /**
     * Per-page on-demand generate (planned → generating). Queues a Launchpad-only draft on the
     * worker (no WordPress contact — that's Publish); only this page generates.
     */
    public function generate(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        // Honest gate: never queue a page that can't ground — it would only produce an empty draft.
        if (! app(GroundingReadiness::class)->ready($content)) {
            Notification::make()->warning()->title('Grounding pending')
                ->body('This page has no resolvable grounding yet, so it can\'t be generated.')->send();

            return;
        }

        GeneratePage::enqueue($content, actorId: Auth::id());

        Notification::make()->success()
            ->title('Queued — generating on the worker')
            ->body("'{$content->title}' is being drafted; it will appear ready for review shortly.")
            ->send();
    }

    /** Per-page publish (the morphing primary for an approved row) — §2's idempotent compose-and-push. */
    public function publish(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        $result = app(ReviewActions::class)->publish($content, Auth::id());

        if ($result->isBlocked()) {
            Notification::make()->danger()->title('Cannot publish')->body($result->blockedReason)->send();

            return;
        }

        $notification = Notification::make()->success()->title('Publishing — composing and pushing to WordPress');
        if ($result->warnings !== []) {
            $notification->body(implode(' ', $result->warnings));
        }
        $notification->send();
    }

    /** Bulk Approve the ticked draft-ready pages — a cheap state flip (no WordPress contact). */
    public function bulkApprove(): void
    {
        $pages = $this->selectedPages();
        if ($pages->isEmpty()) {
            return;
        }

        $results = app(ReviewActions::class)->bulkApprove($pages, Auth::id());
        $blocked = count(array_filter($results, fn ($r) => $r->isBlocked()));
        $approved = count($results) - $blocked;

        $this->selected = [];
        Notification::make()->success()->title("Approved {$approved}, blocked {$blocked}")->send();
    }

    /** Bulk Publish the ticked approved pages — dispatches N idempotent compose-and-push jobs. */
    public function bulkPublish(): void
    {
        $approved = $this->selectedPages()->filter(fn (Content $c) => $c->status === ContentStatus::Approved);
        if ($approved->isEmpty()) {
            Notification::make()->warning()->title('Nothing to publish')
                ->body('Only approved pages can be published — approve them first.')->send();

            return;
        }

        $results = app(ReviewActions::class)->bulkPublish($approved, Auth::id());
        $blocked = count(array_filter($results, fn ($r) => $r->isBlocked()));
        $queued = count($results) - $blocked;

        $this->selected = [];
        Notification::make()->success()->title("Queued {$queued} to publish")
            ->body($blocked > 0 ? "{$blocked} blocked." : 'Publishing in the background.')->send();
    }

    /** The ticked rows, resolved to owned `kind=page` Content (tenant-guarded). */
    private function selectedPages(): Collection
    {
        $ids = array_values(array_filter($this->selected, 'is_string'));
        if ($ids === []) {
            return collect();
        }

        $site = $this->getSite();

        return $site === null ? collect() : Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('id', $ids)
            ->get();
    }

    /** Resolve a page id to an owned `kind=page` Content for the current site, or null. */
    private function ownedPage(string $contentId): ?Content
    {
        $site = $this->getSite();
        if ($site === null) {
            return null;
        }

        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereKey($contentId)
            ->first();
    }

    /** Re-arrange the structure — confirmed decisions are preserved (§10 twin). */
    public function reArrange(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }
        app(AutoArrangeRunner::class)->run($site);
        Notification::make()->title('Re-arranged — your confirmed decisions were preserved.')->success()->send();
    }

    /** Re-ground volume (and re-arrange) on the queue; decisions preserved. */
    public function reGround(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }
        BuildStructure::dispatch($site->id);
        Notification::make()->title('Re-grounding volume — this runs in the background.')->success()->send();
    }
}
