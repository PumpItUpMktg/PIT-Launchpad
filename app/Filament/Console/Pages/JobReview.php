<?php

namespace App\Filament\Console\Pages;

use App\Enums\JobStatus;
use App\JobCapture\Review\JobReviewActions;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use App\Publishing\TenantStorage;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Console → Jobs → Review: the operator's Job Capture review screen (§8), thin over {@see JobReviewActions}.
 * For each job awaiting a decision it shows the three photos (primary selectable), the raw / operator-source
 * / AI-enhanced descriptions side by side, the resolved job types + city/county, and the jittered public
 * point — with edit-in-place, re-enhance, approve, and reject. Nothing here pushes to WordPress directly:
 * approve enqueues the §9 publish. Every mutating action re-checks its capability.
 */
class JobReview extends ConsolePage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-camera';

    protected static ?string $navigationLabel = 'Job Review';

    protected static string|\UnitEnum|null $navigationGroup = 'Jobs';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'job-review';

    protected string $view = 'filament.console.job-review';

    // Single-edit state (one job's fields at a time).
    public ?string $editingId = null;

    public string $editSource = '';

    public string $editTitle = '';

    public string $editMeta = '';

    public int $editPrimary = 0;

    // Reject state.
    public ?string $rejectingId = null;

    public string $rejectReason = '';

    /**
     * The jobs awaiting a decision for the active site — review first, then stuck-captured. Presented for
     * the blade (descriptions, resolved geography, photos with URLs + alt, primary index).
     *
     * @return list<array<string, mixed>>
     */
    public function getReviewJobsProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Job::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereIn('status', [JobStatus::Review->value, JobStatus::Captured->value])
            ->with(['city', 'county', 'jobTypes'])
            ->orderByRaw('CASE status WHEN ? THEN 0 ELSE 1 END', [JobStatus::Review->value])
            ->latest()
            ->get()
            ->map(fn (Job $job): array => $this->present($job))
            ->all();
    }

    /** Load the enhanced source into the editor for one job. */
    public function startEdit(string $id): void
    {
        $job = $this->ownedJob($id);
        if ($job === null) {
            return;
        }

        $this->editingId = $id;
        $this->editSource = (string) ($job->source_description ?? $job->raw_description);
        $this->editTitle = (string) $job->post_title;
        $this->editMeta = (string) $job->meta_description;
        $this->editPrimary = (int) $job->primary_photo_index;
        $this->rejectingId = null;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    /** Save the operator's edits (source seed / title / meta / primary photo) — no AI call. */
    public function saveEdits(): void
    {
        if (! $this->can(Capability::EditContent) || $this->editingId === null) {
            return;
        }
        $job = $this->ownedJob($this->editingId);
        if ($job === null) {
            return;
        }

        app(JobReviewActions::class)->saveEdits($job, [
            'source_description' => $this->editSource,
            'post_title' => $this->editTitle,
            'meta_description' => $this->editMeta,
            'primary_photo_index' => $this->editPrimary,
        ]);

        $this->editingId = null;
        Notification::make()->title('Saved.')->success()->send();
    }

    /** Re-run enhancement against the current source seed (queued). */
    public function reEnhance(string $id): void
    {
        if (! $this->can(Capability::GenerateContent)) {
            return;
        }
        $job = $this->ownedJob($id);
        if ($job === null) {
            return;
        }

        app(JobReviewActions::class)->reEnhance($job);
        Notification::make()->title('Re-enhancing — the write-up will refresh shortly.')->success()->send();
    }

    /** Approve → enqueue the §9 WordPress publish. Blocked (with a notice) when the job has no write-up. */
    public function approve(string $id): void
    {
        if (! $this->can(Capability::ApproveContent)) {
            return;
        }
        $job = $this->ownedJob($id);
        if ($job === null) {
            return;
        }

        if (app(JobReviewActions::class)->approve($job)) {
            Notification::make()->title('Approved — publishing to WordPress.')->success()->send();
        } else {
            Notification::make()->title('Not yet enhanced')->body('Enhance the job before approving.')->warning()->send();
        }
    }

    public function startReject(string $id): void
    {
        $this->rejectingId = $id;
        $this->rejectReason = '';
        $this->editingId = null;
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
    }

    /** Reject with the entered reason (pulls a live post down if it was published). */
    public function confirmReject(): void
    {
        if (! $this->can(Capability::ApproveContent) || $this->rejectingId === null) {
            return;
        }
        $job = $this->ownedJob($this->rejectingId);
        if ($job === null) {
            return;
        }

        app(JobReviewActions::class)->reject($job, $this->rejectReason);
        $this->rejectingId = null;
        Notification::make()->title('Rejected.')->success()->send();
    }

    /** A job in a site the operator may see — the guard every mutating action runs first. */
    protected function ownedJob(string $id): ?Job
    {
        $job = Job::withoutGlobalScope(SiteScope::class)->whereKey($id)->first();

        if ($job === null || ! $this->user()->canSeeSite((string) $job->site_id)) {
            return null;
        }

        return $job;
    }

    /** @return array<string, mixed> */
    private function present(Job $job): array
    {
        $primary = $job->primary_photo_index;
        $photos = is_array($job->photos) ? $job->photos : [];

        return [
            'id' => (string) $job->id,
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'client' => (string) $job->client_name_display,
            'city' => $job->job_city_id !== null ? $job->city->name : null,
            'county' => $job->job_county_id !== null ? $job->county->name : null,
            'lat' => $job->lat_jittered !== null ? (float) $job->lat_jittered : null,
            'lng' => $job->lng_jittered !== null ? (float) $job->lng_jittered : null,
            'job_types' => $job->jobTypes->pluck('label')->all(),
            'raw' => (string) $job->raw_description,
            'source' => (string) ($job->source_description ?? $job->raw_description),
            'enhanced' => (string) $job->enhanced_description,
            'title' => (string) $job->post_title,
            'meta' => (string) $job->meta_description,
            'has_draft' => $job->hasDraft(),
            'photos' => array_map(fn (array $p, int $i): array => [
                'url' => $this->url((string) $p['r2_key']),
                'alt' => (string) ($p['alt'] ?? ''),
                'primary' => $i === $primary,
            ], $photos, array_keys($photos)),
        ];
    }

    private function url(string $key): string
    {
        if ($key === '') {
            return '';
        }
        try {
            return Storage::disk(TenantStorage::DISK)->url($key);
        } catch (Throwable) {
            return '';
        }
    }
}
