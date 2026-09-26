<?php

namespace App\Filament\Pages;

use App\Enums\JobStatus;
use App\Filament\Concerns\ReviewsJobs;
use App\Jobs\PublishJob;
use App\Jobs\UnpublishJob;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Jobs\JobPortfolio;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\WithFileUploads;

/**
 * Jobs (operator) — the tenant's Job-Capture work in one place: the review **queue** (the full workbench:
 * edit, services, photos, re-place, approve/reject, add a previous job, CSV import) and the **published**
 * body of work (+ the publish pipeline). Tenant-locked (reads {@see ActiveTenant}, no per-page site
 * picker), operator-only. Read data comes from {@see JobPortfolio}; the actions are the shared
 * {@see ReviewsJobs} workbench, so the semantics live in one place; the render path stays HTTP-free.
 *
 * @property-read array{summary: array<string, int>, queue: list<array<string, mixed>>, published: list<array<string, mixed>>, pipeline: list<array<string, mixed>>} $board
 */
class JobsBoard extends Page
{
    use ReviewsJobs, WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Jobs';

    protected static string|\UnitEnum|null $navigationGroup = 'Build';

    protected static ?string $slug = 'jobs';

    protected string $view = 'filament.pages.jobs-board';

    public ?string $siteId = null;

    /** Which view is showing: 'queue' (review backlog) or 'published' (live body + pipeline). */
    public string $tab = 'queue';

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
        return Auth::user()?->canOperate() ?? false;
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

    public function can(Capability $capability): bool
    {
        return Auth::user()?->hasCapability($capability) ?? false;
    }

    protected function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** Resolve a job strictly within the locked tenant — never cross-tenant. */
    protected function ownedJob(string $id): ?Job
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
