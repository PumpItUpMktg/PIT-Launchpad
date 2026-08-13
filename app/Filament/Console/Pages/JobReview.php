<?php

namespace App\Filament\Console\Pages;

use App\Enums\JobStatus;
use App\Integrations\Places\PlaceCandidate;
use App\Integrations\Places\PlacesProvider;
use App\JobCapture\Capture\CouldNotPlaceJobException;
use App\JobCapture\Capture\ManualJobData;
use App\JobCapture\Capture\ManualJobIntake;
use App\JobCapture\Enhancement\DescriptionEnhancer;
use App\JobCapture\Review\JobPhotoAttacher;
use App\JobCapture\Review\JobReviewActions;
use App\Models\Job;
use App\Models\JobType;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\TenantStorage;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
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
    use WithFileUploads;

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

    // Add-a-previous-job (operator backfill) state — no device, no GPS, so the address is typed + geocoded.
    public bool $addingJob = false;

    public string $newClientName = '';

    public string $newAddress = '';

    public string $newPerformedAt = '';

    /** Service types chosen from the site's vocabulary (labels).
     *
     * @var array<int, string>
     */
    public array $newJobTypeLabels = [];

    /** Free-text service types not in the vocabulary (comma-separated). */
    public string $newJobTypesOther = '';

    public string $newDescription = '';

    /** Livewire file uploads (TemporaryUploadedFile once uploaded; loosely typed for hydration safety).
     *
     * @var array<int, mixed>
     */
    public array $newPhotos = [];

    /** Set true when the operator picks a suggestion, so the live search doesn't immediately re-open. */
    public bool $addressPicked = false;

    /** Per-review-card photo uploads, keyed by job id (each value a list of Livewire uploads).
     *
     * @var array<string, mixed>
     */
    public array $jobPhotos = [];

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

    /** Toggle the add-a-previous-job panel. */
    public function toggleAddJob(): void
    {
        $this->addingJob = ! $this->addingJob;
        if (! $this->addingJob) {
            $this->resetAddJob();
        }
    }

    /** Typing in the address box re-opens live suggestions (a prior pick no longer applies). */
    public function updatedNewAddress(): void
    {
        $this->addressPicked = false;
    }

    /**
     * Address autocomplete — Places candidates for the typed query. Min-length guarded and skipped right
     * after a pick so the dropdown doesn't re-open over the chosen value.
     *
     * @return list<string>
     */
    public function getAddressSuggestionsProperty(): array
    {
        $query = trim($this->newAddress);
        if ($this->addressPicked || mb_strlen($query) < 5) {
            return [];
        }

        return collect(app(PlacesProvider::class)->search($query))
            ->map(fn (PlaceCandidate $candidate): string => trim($candidate->address !== '' ? $candidate->address : $candidate->name))
            ->filter()
            ->unique()->take(6)->values()->all();
    }

    /** Fill the address from a chosen suggestion. */
    public function pickSuggestion(string $address): void
    {
        $this->newAddress = $address;
        $this->addressPicked = true;
    }

    /** Create a previous job from operator input — geocode the address, then run the normal pipeline. */
    public function addJob(): void
    {
        if (! $this->can(Capability::EditContent) || $this->siteId === null) {
            return;
        }
        $site = $this->workingSite();
        if ($site === null) {
            return;
        }
        if (trim($this->newClientName) === '' || trim($this->newAddress) === '') {
            Notification::make()->title('Enter at least the client name and address.')->warning()->send();

            return;
        }

        try {
            app(ManualJobIntake::class)->intake($site, new ManualJobData(
                clientName: $this->newClientName,
                address: $this->newAddress,
                performedAt: trim($this->newPerformedAt) ?: null,
                rawDescription: trim($this->newDescription) ?: null,
                jobTypes: $this->parsedJobTypes(),
                photos: $this->uploadedPhotos(),
            ));
        } catch (CouldNotPlaceJobException $e) {
            Notification::make()->title('Could not add the job')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->resetAddJob();
        $this->addingJob = false;
        Notification::make()->title('Job added — resolving location & write-up, then it lands in review.')->success()->send();
    }

    /**
     * The service types the site already knows (vocabulary) for the multi-select — labels, alphabetical.
     *
     * @return list<string>
     */
    public function getJobTypeOptionsProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return JobType::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->orderBy('label')
            ->pluck('label')
            ->filter()->unique()->values()->all();
    }

    /** AI-polish the operator's "what was done" notes in place, before the job is created. */
    public function enhanceDescription(): void
    {
        if (! $this->can(Capability::GenerateContent) || trim($this->newDescription) === '') {
            return;
        }

        $enhanced = app(DescriptionEnhancer::class)->enhance($this->newDescription, $this->selectedJobTypeLabels());
        if ($enhanced !== '') {
            $this->newDescription = $enhanced;
            Notification::make()->title('Enhanced — review and tweak before adding.')->success()->send();
        }
    }

    /** Selected vocabulary labels + free-text extras, trimmed and de-duped. @return list<string> */
    private function selectedJobTypeLabels(): array
    {
        return collect($this->newJobTypeLabels)
            ->merge(explode(',', $this->newJobTypesOther))
            ->map(fn (string $type): string => trim($type))
            ->filter()->unique()
            ->take(Job::MAX_JOB_TYPES)
            ->values()->all();
    }

    /** @return list<array{label: string}> */
    private function parsedJobTypes(): array
    {
        return array_map(fn (string $label): array => ['label' => $label], $this->selectedJobTypeLabels());
    }

    /** @return list<array{bytes: string, filename: string}> */
    private function uploadedPhotos(): array
    {
        $photos = [];
        foreach (array_slice(array_values($this->newPhotos), 0, 3) as $i => $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $photos[] = ['bytes' => (string) $file->get(), 'filename' => $file->getClientOriginalName() ?: ($i + 1).'.jpg'];
            }
        }

        return $photos;
    }

    private function resetAddJob(): void
    {
        $this->newClientName = $this->newAddress = $this->newPerformedAt = $this->newJobTypesOther = $this->newDescription = '';
        $this->newJobTypeLabels = [];
        $this->newPhotos = [];
        $this->addressPicked = false;
    }

    /** The active site as a model the operator may see — for the manual-intake service. */
    private function workingSite(): ?Site
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);

        return $site !== null && $this->user()->canSeeSite((string) $site->id) ? $site : null;
    }

    /** Attach the uploaded photos to an existing review-queue job (backfill / walk-in photographed later). */
    public function attachPhotos(string $id): void
    {
        if (! $this->can(Capability::EditContent)) {
            return;
        }
        $job = $this->ownedJob($id);
        $uploads = $this->jobPhotos[$id] ?? null;
        if ($job === null || ! is_array($uploads) || $uploads === []) {
            return;
        }

        $photos = [];
        foreach (array_slice(array_values($uploads), 0, Job::MAX_PHOTOS) as $i => $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $photos[] = ['bytes' => (string) $file->get(), 'filename' => $file->getClientOriginalName() ?: ($i + 1).'.jpg'];
            }
        }

        $added = app(JobPhotoAttacher::class)->attach($job, $photos);
        unset($this->jobPhotos[$id]);

        $added > 0
            ? Notification::make()->title($added.' photo'.($added === 1 ? '' : 's').' added.')->success()->send()
            : Notification::make()->title('No room for more photos on this job (max '.Job::MAX_PHOTOS.').')->warning()->send();
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
