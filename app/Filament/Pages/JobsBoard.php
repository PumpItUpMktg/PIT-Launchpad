<?php

namespace App\Filament\Pages;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\JobCapture\Review\JobReviewActions;
use App\Jobs\PublishJob;
use App\Jobs\UnpublishJob;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Jobs\JobPortfolio;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Jobs (operator) — the tenant's Job-Capture work in one place: the review **queue** (jobs an operator
 * still has to act on) and the **published** body of work (+ the publish pipeline). The in-panel port of
 * the Operations Console's Job Review + Published Jobs, folded onto the operator lock: tenant-locked
 * (reads {@see ActiveTenant}, no per-page site picker), operator-only. Read data + actions come from the
 * existing Job-Capture services ({@see JobPortfolio}, {@see JobReviewActions}), so the semantics live in
 * one place; the render path stays HTTP-free.
 *
 * @property-read array{summary: array<string, int>, queue: list<array<string, mixed>>, published: list<array<string, mixed>>, pipeline: list<array<string, mixed>>} $board
 */
class JobsBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Jobs';

    protected static string|\UnitEnum|null $navigationGroup = 'Build';

    protected static ?string $slug = 'jobs';

    protected string $view = 'filament.pages.jobs-board';

    public ?string $siteId = null;

    /** Which view is showing: 'queue' (review backlog) or 'published' (live body + pipeline). */
    public string $tab = 'queue';

    /** Inline reject flow — the id being rejected + its reason (mirrors the Console review screen). */
    public ?string $rejectingId = null;

    public string $rejectReason = '';

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    public function getTitle(): string
    {
        return 'Jobs';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /** @return array{summary: array<string, int>, queue: list<array<string, mixed>>, published: list<array<string, mixed>>, pipeline: list<array<string, mixed>>} */
    public function getBoardProperty(): array
    {
        return app(JobPortfolio::class)->for($this->siteId);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['queue', 'published'], true) ? $tab : 'queue';
        $this->cancelReject();
    }

    // Queue actions — thin over JobReviewActions (each resolves the job WITHIN the locked tenant) --------

    public function approve(string $id): void
    {
        $job = $this->ownedJob($id);
        if ($job === null) {
            return;
        }

        if (app(JobReviewActions::class)->approve($job)) {
            Notification::make()->title('Approved — queued for publishing.')->success()->send();
        } else {
            Notification::make()->title('Not approvable yet — it needs a write-up.')->warning()->send();
        }
    }

    public function reEnhance(string $id): void
    {
        $job = $this->ownedJob($id);
        if ($job === null) {
            return;
        }

        app(JobReviewActions::class)->reEnhance($job);
        Notification::make()->title('Re-queued for enhancement.')->success()->send();
    }

    public function startReject(string $id): void
    {
        $this->rejectingId = $id;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectReason = '';
    }

    public function confirmReject(): void
    {
        if ($this->rejectingId === null) {
            return;
        }
        $job = $this->ownedJob($this->rejectingId);
        if ($job !== null) {
            app(JobReviewActions::class)->reject($job, $this->rejectReason);
            Notification::make()->title('Rejected.')->success()->send();
        }
        $this->cancelReject();
    }

    // Published/pipeline actions — mirror the Console's PublishedJobs (idempotent by ULID) --------------

    public function retryPublish(string $id): void
    {
        $job = $this->ownedJob($id);
        if ($job === null) {
            return;
        }

        PublishJob::dispatch($job->id);
        Notification::make()->title('Re-queued the WordPress push.')->success()->send();
    }

    public function takeDown(string $id): void
    {
        $job = $this->ownedJob($id);
        if ($job === null) {
            return;
        }

        if ($job->wp_post_id !== null) {
            UnpublishJob::dispatch($job->id);
        }
        $job->forceFill(['status' => JobStatus::Approved])->save();
        Notification::make()->title('Taking the job down from WordPress.')->success()->send();
    }

    /** Resolve a job strictly within the locked tenant — never cross-tenant. */
    private function ownedJob(string $id): ?Job
    {
        if ($this->siteId === null) {
            return null;
        }

        $user = Auth::user();
        if (! $user instanceof User || ! $user->canSeeSite($this->siteId)) {
            return null;
        }

        return Job::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereKey($id)
            ->first();
    }
}
