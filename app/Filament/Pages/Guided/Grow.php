<?php

namespace App\Filament\Pages\Guided;

use App\ContentEngine\Drafting\GroundingReadiness;
use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentStatus;
use App\Enums\SetupStep;
use App\Filament\Pages\ProofEditor;
use App\Guided\GrowDashboard;
use App\Guided\GuidedPage;
use App\Guided\IntakeChecklist;
use App\Interview\Arrange\AutoArrangeRunner;
use App\Jobs\BuildStructure;
use App\Jobs\GeneratePage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Publishing\DeleteFromWordpress;
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
 * @property-read list<array<string, mixed>> $pages
 * @property-read list<array{key: string, label: string, count: int, pages: list<array<string, mixed>>}> $sections
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

    /** The content id whose inline reject-reason input is open (null = none). */
    public ?string $rejecting = null;

    /** The reason typed into the open reject input (optional — improves future drafts). */
    public string $rejectReason = '';

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
     * The content checklist — intake the pages can't show yet (missing items only). Informational,
     * not a publish gate: pages publish honestly without these (an empty section is omitted, never
     * fabricated), but the gaps are made loud on the page the client publishes from.
     *
     * @return list<array{key: string, label: string, unlocks: string, where: string}>
     */
    public function getChecklistProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(IntakeChecklist::class)->missing($site);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPagesProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(GrowDashboard::class)->pages($site);
    }

    /**
     * Whether anything is IN MOTION (a queued draft writing, a publish pushing) — the view polls only
     * while true, so a row's working state updates LIVE against the job's real status (the operator can
     * look away and come back; the row tells the truth) without polling an idle workbench forever.
     * tone=info is exactly the Writing/Publishing pair in the canonical vocabulary.
     */
    public function getPollingProperty(): bool
    {
        return collect($this->pages)->contains(fn (array $p): bool => ($p['tone'] ?? '') === 'info');
    }

    /**
     * The workbench grouped into Core / Service / Town lanes with per-section counts (the list the
     * view renders). The flat {@see getPagesProperty()} stays for the bulk-lane gate + counts.
     *
     * @return list<array{key: string, label: string, count: int, pages: list<array<string, mixed>>}>
     */
    public function getSectionsProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(GrowDashboard::class)->sections($site);
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
            Notification::make()->warning()->title('Not ready yet')
                ->body('This page isn\'t ready to write yet — its details are still coming together.')->send();

            return;
        }

        GeneratePage::enqueue($content, actorId: Auth::id());

        Notification::make()->success()
            ->title('Queued — generating on the worker')
            ->body("'{$content->title}' is being drafted; it will appear ready for review shortly.")
            ->send();
    }

    /** Per-page approve (a review row) — a cheap Launchpad-only acceptance; surfaces guard warnings. */
    public function approve(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        $result = app(ReviewActions::class)->approve($content, Auth::id());

        if ($result->isBlocked()) {
            Notification::make()->danger()->title('Cannot approve')->body((string) $result->blockedReason)->send();

            return;
        }

        $notification = Notification::make()->success()->title('Approved — ready to publish');
        if ($result->warnings !== []) {
            $notification->body(implode(' ', $result->warnings));
        }
        $notification->send();
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

    /**
     * Regenerate — re-draft a page in place (same honest gate + worker path as {@see generate()}).
     * Used from the overflow menu for a page that already has (or expects) a draft: a weak first draft,
     * a stale live page, or a failed one. The existing draft/content stays until the new one lands.
     */
    public function regenerate(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        if (! app(GroundingReadiness::class)->ready($content)) {
            Notification::make()->warning()->title('Not ready yet')
                ->body('This page isn\'t ready to write yet — its details are still coming together.')->send();

            return;
        }

        GeneratePage::enqueue($content, actorId: Auth::id());

        Notification::make()->success()->title('Queued — regenerating on the worker')
            ->body("'{$content->title}' is being re-drafted; the fresh version will appear ready for review shortly.")
            ->send();
    }

    /** Lock a page so a future publish never overwrites operator edits (§2 honors the flag). */
    public function lock(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        app(ReviewActions::class)->lock($content);

        Notification::make()->success()->title('Locked')
            ->body('Future publishes won\'t overwrite this page — unlock it to resume automatic updates.')->send();
    }

    /** Open the inline reject-reason input for a row (the reason is optional but sharpens future drafts). */
    public function startReject(string $contentId): void
    {
        $this->rejecting = $contentId;
        $this->rejectReason = '';
    }

    /** Dismiss the open reject-reason input without rejecting. */
    public function cancelReject(): void
    {
        $this->rejecting = null;
        $this->rejectReason = '';
    }

    /** Reject a review draft — flips it out of the review lane with an (optional) captured reason. */
    public function reject(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        $reason = trim($this->rejectReason);
        app(ReviewActions::class)->reject($content, $reason !== '' ? $reason : 'Rejected from the workbench');

        $this->rejecting = null;
        $this->rejectReason = '';

        Notification::make()->success()->title('Rejected')
            ->body("'{$content->title}' was sent back — regenerate it when you're ready to try again.")->send();
    }

    /**
     * Take a page down from WordPress — force-deletes the live post (freeing the slug) and flips the
     * page back to a republishable state (§2's {@see DeleteFromWordpress}). The plan row STAYS, so the
     * page can be regenerated or re-published on the same URL; only the live WordPress post goes away.
     */
    public function takeDown(string $contentId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null) {
            return;
        }

        $result = app(DeleteFromWordpress::class)->delete($content);

        // On WordPress but the delete didn't confirm — surface the failure and leave the page as-is.
        if (! $result['deleted'] && $result['on_wp']) {
            Notification::make()->danger()->title('Could not take it down')->body($result['message'])->send();

            return;
        }

        Notification::make()->success()->title('Taken down')
            ->body($result['on_wp']
                ? "'{$content->title}' was removed from WordPress — it stays in your plan and can be re-published on the same URL."
                : "'{$content->title}' wasn't on WordPress; it's ready to publish.")
            ->send();
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
