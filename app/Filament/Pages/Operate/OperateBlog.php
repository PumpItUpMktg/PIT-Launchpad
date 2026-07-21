<?php

namespace App\Filament\Pages\Operate;

use App\ContentEngine\BlogQueue\BlogTargetQueue;
use App\ContentEngine\Feeds\BlogPopulator;
use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentKind;
use App\Filament\Resources\ContentReviewResource;
use App\Jobs\GeneratePost;
use App\Jobs\PopulateBlog;
use App\Jobs\PublishContent;
use App\Models\BlogTarget;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\BlogBoard;
use App\Operator\ActiveTenant;
use App\Publishing\DeleteFromWordpress;
use App\Publishing\PostPublisher;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Operate · Blog — ONE surface for the whole post pipeline: Candidates → Review → Published,
 * cross-tenant with sticky site + silo filters (persisted in session, shared across the tabs by
 * construction — same component). One-click actions everywhere: Promote/Dismiss on candidate
 * cards; Approve (→ the existing approve+publish path)/Edit (full editor)/Reject (reason) on
 * review cards. Published is the relevance map: grouped by consumed keyword → the pillar page it
 * supports, bare targets first; reactive articles bucket per-silo under Freshness. Blog targets
 * are a drawer here, not a nav item.
 *
 * Tenant scope is the panel-wide active tenant ({@see ActiveTenant}, enforced by the hard gate) —
 * this page has NO tenant switcher of its own; you change tenants from the Portfolio. Only the
 * per-silo filter is local.
 *
 * @property-read array<string, string> $siloOptions
 */
class OperateBlog extends OperatePage
{
    protected static ?string $slug = 'operate/blog';

    protected static ?string $navigationLabel = 'Blog';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.operate.blog';

    #[Url]
    public string $tab = 'candidates';

    /** The active tenant — set from ActiveTenant, not switchable here (no per-page tenant picker). */
    public ?string $siteFilter = null;

    #[Url(as: 'silo')]
    public ?string $siloFilter = null;

    #[Url(as: 'targets')]
    public bool $showTargets = false;

    /** The inline reject-reason state (content id or null). */
    public ?string $rejecting = null;

    public string $rejectReason = '';

    public function mount(): void
    {
        // Scope is the panel-wide active tenant (the hard gate guarantees one is selected); the
        // silo filter stays sticky per session. No tenant switcher on this page.
        $this->siteFilter = app(ActiveTenant::class)->id();
        $this->siloFilter = $this->siloFilter ?? session('operate_blog_silo');
        if (! in_array($this->tab, ['candidates', 'review', 'published'], true)) {
            $this->tab = 'candidates';
        }
    }

    public function updatedSiloFilter(): void
    {
        session(['operate_blog_silo' => $this->siloFilter !== '' ? $this->siloFilter : null]);
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['candidates', 'review', 'published'], true)) {
            $this->tab = $tab;
        }
    }

    /** @return array<string, string> */
    public function getSiloOptionsProperty(): array
    {
        return app(BlogBoard::class)->siloOptions($this->siteFilter);
    }

    /** @return list<array<string, mixed>> */
    public function getCandidatesProperty(): array
    {
        return app(BlogBoard::class)->candidates($this->siteFilter, $this->filterSilo());
    }

    /** @return list<array<string, mixed>> */
    public function getPublishingProperty(): array
    {
        return app(BlogBoard::class)->publishing($this->siteFilter, $this->filterSilo());
    }

    /** @return list<array<string, mixed>> */
    public function getReviewProperty(): array
    {
        return app(BlogBoard::class)->review($this->siteFilter, $this->filterSilo());
    }

    /** @return list<array<string, mixed>> */
    public function getPublishedProperty(): array
    {
        return app(BlogBoard::class)->published($this->siteFilter, $this->filterSilo());
    }

    /** @return list<array<string, mixed>> */
    public function getTargetsProperty(): array
    {
        return app(BlogBoard::class)->targets($this->siteFilter, $this->filterSilo());
    }

    /**
     * "Populate blog now": run the cheap stages inline (re-file keywords → reconcile feeds) for an
     * instant readiness read, then hand the HTTP-heavy fetch to a queued job so candidates fill in
     * off the request. If the chain isn't even ready (no keywords, or none routed to a silo), say so
     * plainly instead of dispatching a fetch that can only find nothing. Requires a single tenant
     * selected — populate is per-site.
     */
    public function populateBlog(): void
    {
        if ($this->siteFilter === null) {
            Notification::make()->warning()->title('No active tenant')
                ->body('Pick a tenant from the Portfolio, then populate its blog.')->send();

            return;
        }

        $site = Site::query()->find($this->siteFilter);
        if ($site === null) {
            return;
        }

        // Cheap DB stages inline; the expensive feed fetch is deferred to the worker.
        $report = app(BlogPopulator::class)->populate($site, ingest: false);

        if (! $report->ready()) {
            Notification::make()->warning()->title('Nothing to populate yet')
                ->body($report->diagnosis())->persistent()->send();

            return;
        }

        PopulateBlog::dispatch($site->id);

        Notification::make()->success()->title('Populating the blog')
            ->body($report->diagnosis())->send();
    }

    // ── Candidate actions ───────────────────────────────────────────────────

    /** Promote → drafting, via the existing queued generate path. */
    public function promote(string $contentId): void
    {
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        GeneratePost::enqueue($content, actorId: Auth::id());
        Notification::make()->success()
            ->title("Drafting '{$content->title}'")
            ->body('Moved to the Review tab as a writing card — copy + image land there when the worker finishes.')
            ->send();
    }

    /** Dismiss at triage — recorded as a rejection so the pipeline never resurfaces it. */
    public function dismissCandidate(string $contentId): void
    {
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        app(ReviewActions::class)->reject($content, 'Dismissed at candidate triage');
        Notification::make()->success()->title('Dismissed.')->send();
    }

    /**
     * Re-draft an already-drafted review item — for posts written before the current pipeline
     * (older prompts, no image, weak first pass). Same generate path; the card flips to a
     * "writing" state and updates itself when the fresh copy + image land. Slug stays pinned.
     */
    public function regeneratePost(string $contentId): void
    {
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        GeneratePost::enqueue($content, actorId: Auth::id());
        Notification::make()->success()
            ->title("Re-drafting '{$content->title}'")
            ->body('Fresh copy + image are being generated — the card updates itself; the URL slug is kept.')
            ->send();
    }

    // ── Review actions ──────────────────────────────────────────────────────

    /** One-click Approve — the existing approve + publish path, nothing bespoke. */
    public function approve(string $contentId): void
    {
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        $actions = app(ReviewActions::class);
        $approved = $actions->approve($content, Auth::id());
        if ($approved->isBlocked()) {
            Notification::make()->danger()->title('Cannot approve')->body((string) $approved->blockedReason)->send();

            return;
        }

        $published = $actions->publish($content->refresh(), Auth::id());
        if ($published->isBlocked()) {
            Notification::make()->warning()->title('Approved — publish blocked')->body((string) $published->blockedReason)->send();

            return;
        }

        Notification::make()->success()->title("'{$content->title}' approved — publishing now.")->send();
    }

    /**
     * "Publish now" — the stalled-worker escape hatch on an in-flight post. Runs §2's publish INLINE
     * on the web request (via PostPublisher, same gate + idempotent-by-ULID push) instead of waiting
     * on the background worker. Surfaced only when a post is stuck at "queued to publish" (a dispatched
     * job that never started). Single post per click — a full backlog drains via launchpad:drain-publish.
     */
    public function publishNowSync(string $contentId): void
    {
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        $result = app(PostPublisher::class)->publish($content, Auth::id());

        if ($result->isPublished()) {
            Notification::make()->success()->title('Published')
                ->body("'{$content->title}' was rendered and pushed to WordPress.")->send();

            return;
        }

        if ($result->wasSkipped()) {
            Notification::make()->warning()->title('Skipped')->body((string) $result->message)->send();

            return;
        }

        Notification::make()->danger()->title('Publish failed')->body((string) $result->message)->send();
    }

    /** The full draft editor (existing review edit page); save returns here via back-nav. */
    public function editUrl(string $contentId): string
    {
        return ContentReviewResource::getUrl('edit', ['record' => $contentId]);
    }

    public function startReject(string $contentId): void
    {
        $this->rejecting = $contentId;
        $this->rejectReason = '';
    }

    public function reject(string $contentId): void
    {
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        app(ReviewActions::class)->reject($content, trim($this->rejectReason) !== '' ? trim($this->rejectReason) : 'Rejected from the Blog surface');
        $this->rejecting = null;
        $this->rejectReason = '';
        Notification::make()->success()->title('Rejected.')->send();
    }

    // ── Targets drawer ──────────────────────────────────────────────────────

    public function toggleTargets(): void
    {
        $this->showTargets = ! $this->showTargets;
    }

    public function dismissTarget(string $targetId): void
    {
        $target = BlogTarget::withoutGlobalScope(SiteScope::class)
            ->when($this->siteFilter !== null, fn ($q) => $q->where('site_id', $this->siteFilter))
            ->whereKey($targetId)
            ->first();
        if ($target === null) {
            return;
        }

        app(BlogTargetQueue::class)->dismiss($target);
        Notification::make()->success()->title('Target dismissed.')->send();
    }

    // ── Published-article actions ───────────────────────────────────────────

    /**
     * Re-push a live post — the idempotent §2 publish on the same ULID (same URL). Used to re-sync a
     * post after a fix (e.g. the body/silo-category repairs): it re-sends the meta-blob and re-pushes
     * the silo category. Guarded on hasDraft() so an undrafted row can never push an empty post.
     */
    public function repushPost(string $contentId): void
    {
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        if (! $content->hasDraft()) {
            Notification::make()->warning()->title('Nothing to publish')
                ->body('This post has no drafted body yet.')->send();

            return;
        }

        PublishContent::dispatch($content->id, Auth::id());
        Notification::make()->success()->title('Re-pushing')
            ->body("'{$content->title}' is being re-published to WordPress on the same URL.")->send();
    }

    /**
     * Take a live post off WordPress — §2's force-delete (frees the slug) which flips the row back to
     * approved, so it leaves the Published tab and a later Re-push recreates it on the SAME URL. A
     * failed delete leaves the post live and surfaces WordPress's reason.
     */
    public function takeDownPost(string $contentId): void
    {
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        $result = app(DeleteFromWordpress::class)->delete($content);
        if (! $result['deleted'] && $result['on_wp']) {
            Notification::make()->danger()->title('Could not take it down')->body($result['message'])->send();

            return;
        }

        Notification::make()->success()->title('Taken down')
            ->body("'{$content->title}' was removed from WordPress; Re-push recreates it on the same URL.")->send();
    }

    private function filterSilo(): ?string
    {
        return $this->siloFilter !== null && $this->siloFilter !== '' ? $this->siloFilter : null;
    }

    private function ownedPost(string $contentId): ?Content
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('kind', ContentKind::Post->value)
            ->whereKey($contentId)
            ->first();
    }
}
