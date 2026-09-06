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
use App\Operate\QueueHealth;
use App\Operator\ActiveTenant;
use App\Publishing\DeleteFromWordpress;
use App\Publishing\PostPublisher;
use App\Publishing\RepushPublished;
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
        if (! in_array($this->tab, ['candidates', 'review', 'approved', 'published'], true)) {
            $this->tab = 'candidates';
        }
    }

    public function updatedSiloFilter(): void
    {
        session(['operate_blog_silo' => $this->siloFilter !== '' ? $this->siloFilter : null]);
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['candidates', 'review', 'approved', 'published'], true)) {
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

    /** Approved posts queued to publish (approved → rendering → pushing), not yet live. @return list<array<string, mixed>> */
    public function getApprovedProperty(): array
    {
        return app(BlogBoard::class)->approved($this->siteFilter, $this->filterSilo());
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

    /**
     * Bulk re-push every published post for the active tenant — refreshes the engine-owned meta-blob
     * (canonical / og:url / schema) without editing copy, fixing stale values baked at an earlier
     * publish (e.g. a canonical still on the old staging host after a domain move). Idempotent, no fal
     * spend; throttled into waves so the client WordPress isn't flooded. Needs the worker running.
     */
    public function repushAllPublished(): void
    {
        $this->dispatchRepush([ContentKind::Post], 'post');
    }

    /**
     * Re-push the ENTIRE site — every published post AND page — so the engine-owned meta-blob
     * (canonical / og / schema), the lead-form CTAs, and the normalized NAP are rebuilt everywhere in
     * one pass. Same guarantees as the posts-only re-push: idempotent by ULID, no image/fal cost
     * (already-rendered images are skipped), throttled into waves. This is the button form of
     * `launchpad:repush-published --kind=all`.
     */
    public function repushEntireSite(): void
    {
        $this->dispatchRepush([ContentKind::Post, ContentKind::Page], 'post + page');
    }

    /**
     * Shared re-push dispatch for the active tenant. Warns when no tenant is selected, no-ops with an
     * info notice when nothing matches, else queues the throttled waves and reports the plan.
     *
     * @param  list<ContentKind>  $kinds
     */
    private function dispatchRepush(array $kinds, string $noun): void
    {
        $site = $this->siteFilter !== null ? Site::query()->find($this->siteFilter) : null;
        if ($site === null) {
            Notification::make()->warning()->title('Pick a tenant first')
                ->body('Re-push targets one site at a time — select a tenant, then retry.')->send();

            return;
        }

        $result = app(RepushPublished::class)->dispatch(
            $site,
            $kinds,
            (int) config('launchpad.repush.chunk', 10),
            (int) config('launchpad.repush.interval_seconds', 15),
        );

        if ($result['count'] === 0) {
            Notification::make()->info()->title('Nothing to re-push')->body("No published {$noun}(s) for this tenant.")->send();

            return;
        }

        Notification::make()->success()
            ->title("Re-pushing {$result['count']} published {$noun}(s)")
            ->body(sprintf(
                'Refreshing canonical / og / schema in %d wave(s) (~%s min to fully queue). Watch the queue-health banner — the worker must be running to drain them.%s',
                $result['waves'],
                $result['minutes'],
                $result['sitemap_submitted'] ? ' Sitemap will be resubmitted to Google once the waves land.' : '',
            ))
            ->send();
    }

    // ── Review actions ──────────────────────────────────────────────────────

    /**
     * Approve — the QA gate. Sets status=approved and STOPS: nothing hits WordPress. The post lands in the
     * Approved tab where an operator publishes it as a separate, deliberate action ({@see publish()}). This
     * is the four-stage design (Candidate → Review → Approved → Publish); Approve no longer one-click-pushes.
     */
    public function approve(string $contentId): void
    {
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        $approved = app(ReviewActions::class)->approve($content, Auth::id());
        if ($approved->isBlocked()) {
            Notification::make()->danger()->title('Cannot approve')->body((string) $approved->blockedReason)->send();

            return;
        }

        Notification::make()->success()->title("'{$content->title}' approved")
            ->body('Review it in the Approved tab, then Publish when ready — nothing has been pushed to WordPress yet.')->send();
    }

    /**
     * Publish — the deliberate push from the Approved stage. Releases the post to the publish queue (which
     * re-runs the publish guard) then pushes: async on a healthy worker (the worker renders the image +
     * pushes), or INLINE when the worker is stalled so publishing still completes on the request.
     */
    public function publish(string $contentId): void
    {
        $content = $this->ownedPost($contentId);
        if ($content === null) {
            return;
        }

        $actions = app(ReviewActions::class);
        $released = $actions->release($content, Auth::id()); // re-checks the publish guard + marks released
        if ($released->isBlocked()) {
            Notification::make()->danger()->title('Cannot publish')->body((string) $released->blockedReason)->send();

            return;
        }

        $content = $content->refresh();

        // Worker down → publish INLINE (same PostPublisher path) so it doesn't hang at "queued to publish".
        if (app(QueueHealth::class)->snapshot()['stalled']) {
            $this->publishInline($content);

            return;
        }

        $published = $actions->publish($content, Auth::id());
        if ($published->isBlocked()) {
            Notification::make()->warning()->title('Publish blocked')->body((string) $published->blockedReason)->send();

            return;
        }

        Notification::make()->success()->title("'{$content->title}' — publishing now.")
            ->body('The worker is rendering the image and pushing to WordPress; it moves to Published when done.')->send();
    }

    /** Publish fell back to inline because the worker is down — render + push here and now. */
    private function publishInline(Content $content): void
    {
        $result = app(PostPublisher::class)->publish($content, Auth::id());

        if ($result->isPublished()) {
            Notification::make()->success()->title('Published')
                ->body("'{$content->title}' was published inline (the background worker is down).")->send();

            return;
        }

        if ($result->wasSkipped()) {
            Notification::make()->warning()->title('Publish skipped')->body((string) $result->message)->send();

            return;
        }

        Notification::make()->danger()->title('Publish failed')->body((string) $result->message)->send();
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
            ->body("'{$content->title}' was removed from WordPress and moved back to Candidates — regenerate/re-review it, then publish so its silo category re-resolves.")->send();
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
