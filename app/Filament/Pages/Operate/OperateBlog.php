<?php

namespace App\Filament\Pages\Operate;

use App\ContentEngine\BlogQueue\BlogTargetQueue;
use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentKind;
use App\Filament\Resources\ContentReviewResource;
use App\Jobs\GeneratePost;
use App\Models\BlogTarget;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\BlogBoard;
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
 * @property-read array<string, string> $siteOptions
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

    #[Url(as: 'site')]
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
        // Sticky filters: explicit query params win, else the last session choice.
        $this->siteFilter = $this->validSite($this->siteFilter) ?? $this->validSite(session('operate_blog_site'));
        $this->siloFilter = $this->siloFilter ?? session('operate_blog_silo');
        if (! in_array($this->tab, ['candidates', 'review', 'published'], true)) {
            $this->tab = 'candidates';
        }
    }

    public function updatedSiteFilter(): void
    {
        session(['operate_blog_site' => $this->siteFilter]);
        $this->siloFilter = null; // silos are per-site — a site change resets the silo filter
        session(['operate_blog_silo' => null]);
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
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
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

    private function filterSilo(): ?string
    {
        return $this->siloFilter !== null && $this->siloFilter !== '' ? $this->siloFilter : null;
    }

    private function validSite(mixed $siteId): ?string
    {
        return is_string($siteId) && $siteId !== '' && Site::query()->whereKey($siteId)->exists() ? $siteId : null;
    }

    private function ownedPost(string $contentId): ?Content
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('kind', ContentKind::Post->value)
            ->whereKey($contentId)
            ->first();
    }
}
