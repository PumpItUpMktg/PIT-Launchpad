<?php

namespace App\Filament\Concerns;

use App\Integrations\Places\PlaceCandidate;
use App\Integrations\Places\PlacesProvider;
use App\JobCapture\Capture\CouldNotPlaceJobException;
use App\JobCapture\Capture\CsvJobImporter;
use App\JobCapture\Capture\ManualJobData;
use App\JobCapture\Capture\ManualJobIntake;
use App\JobCapture\Enhancement\DescriptionEnhancer;
use App\JobCapture\Photos\LibraryPhotoAttacher;
use App\JobCapture\Review\JobPhotoAttacher;
use App\JobCapture\Review\JobRelocator;
use App\JobCapture\Review\JobReviewActions;
use App\JobCapture\Types\JobTypeVocabulary;
use App\Models\Job;
use App\Models\LibraryPhoto;
use App\Models\Site;
use App\Models\User;
use App\Security\Capability;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Job Capture review workbench — the Livewire state + actions shared by every page that reviews jobs
 * (the admin Build → Jobs board and the legacy Console review page): edit-in-place (write-up, title, meta,
 * primary photo, client, work date, services), re-enhance, approve, reject, re-place, add a previous job,
 * the text-only CSV import + template, per-job photo upload, and attach-from-library. Thin over the
 * Job Capture services; every mutating action re-checks its capability and resolves its job through the
 * host page's tenant guard ({@see ownedJob()}). The host page provides `$siteId`, the guard, the working
 * site, and the capability check; it must also `use WithFileUploads` for the upload fields.
 *
 * @property-read list<string> $jobTypeOptions
 * @property-read list<string> $addressSuggestions
 * @property-read list<string> $placeSuggestions
 * @property-read list<array{id: string, url: string, label: ?string}> $libraryPhotos
 */
trait ReviewsJobs
{
    // Single-edit state (one job's fields at a time).
    public ?string $editingId = null;

    public string $editSource = '';

    public string $editTitle = '';

    public string $editMeta = '';

    public int $editPrimary = 0;

    public string $editClientName = '';

    public string $editPerformedAt = '';

    /** Services applied to the job being edited — vocabulary labels + a free-text "other" list.
     *
     * @var array<int, string>
     */
    public array $editJobTypeLabels = [];

    public string $editJobTypesOther = '';

    // Reject state.
    public ?string $rejectingId = null;

    public string $rejectReason = '';

    // Re-place state: the job being moved and the typed address (with the same live Places suggestions).
    public ?string $placingId = null;

    public string $placeAddress = '';

    public bool $placePicked = false;

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

    /** The CSV bulk-import upload (a single Livewire file). */
    public mixed $csvFile = null;

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
        $this->editClientName = (string) $job->client_name_full;
        $this->editPerformedAt = $job->performed_at?->toDateString() ?? '';
        $options = $this->jobTypeOptions;
        $applied = $job->jobTypes()->pluck('label')->all();
        $this->editJobTypeLabels = array_values(array_intersect($applied, $options));
        $this->editJobTypesOther = implode(', ', array_diff($applied, $options));
        $this->rejectingId = null;
        $this->placingId = null;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    /** Save the operator's edits (source seed / title / meta / primary photo / client / date / services) — no AI call. */
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
            'client_name_full' => $this->editClientName,
            'performed_at' => trim($this->editPerformedAt),
            'job_types' => $this->labelsFrom($this->editJobTypeLabels, $this->editJobTypesOther),
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
        $this->placingId = null;
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

    /** Open the re-place panel for one job, pre-filled with the address it is currently placed at (if typed). */
    public function startPlace(string $id): void
    {
        $job = $this->ownedJob($id);
        if ($job === null) {
            return;
        }

        $this->placingId = $id;
        $this->placeAddress = (string) $job->address_true;
        $this->placePicked = true; // don't pop suggestions over the current value until the operator types
        $this->editingId = null;
        $this->rejectingId = null;
    }

    public function cancelPlace(): void
    {
        $this->placingId = null;
        $this->placeAddress = '';
        $this->placePicked = false;
    }

    /** Typing in the re-place box re-opens live suggestions. */
    public function updatedPlaceAddress(): void
    {
        $this->placePicked = false;
    }

    /** @return list<string> */
    public function getPlaceSuggestionsProperty(): array
    {
        return $this->placePicked ? [] : $this->suggestionsFor($this->placeAddress);
    }

    public function pickPlaceSuggestion(string $address): void
    {
        $this->placeAddress = $address;
        $this->placePicked = true;
    }

    /**
     * Move the job to the typed address: geocode → new true point; jitter, town/county, and every photo's
     * GPS are reset and re-resolved off the request ({@see JobRelocator}). The write-up is left alone.
     */
    public function place(): void
    {
        if (! $this->can(Capability::EditContent) || $this->placingId === null) {
            return;
        }
        $job = $this->ownedJob($this->placingId);
        if ($job === null) {
            return;
        }
        if (trim($this->placeAddress) === '') {
            Notification::make()->title('Enter the job’s street address.')->warning()->send();

            return;
        }

        try {
            $point = app(JobRelocator::class)->relocate($job, $this->placeAddress);
        } catch (CouldNotPlaceJobException $e) {
            Notification::make()->title('Could not re-place the job')->body($e->getMessage())->danger()->send();

            return;
        }

        $wasPushed = $job->wp_post_id !== null;
        $this->cancelPlace();
        Notification::make()->title('Re-placed at '.$point->matchedAddress)
            ->body('Town, county, the map pin, and the photo GPS refresh in a moment.'
                .($wasPushed ? ' Re-approve to republish the live page.' : '')
                .' If the write-up names the old town, Re-enhance it.')
            ->success()->send();
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
     * Address autocomplete — Places candidates for the typed query. Skipped right after a pick so the
     * dropdown doesn't re-open over the chosen value.
     *
     * @return list<string>
     */
    public function getAddressSuggestionsProperty(): array
    {
        return $this->addressPicked ? [] : $this->suggestionsFor($this->newAddress);
    }

    /**
     * Places candidates for a typed address query (min-length guarded), shared by the add-job and re-place
     * boxes.
     *
     * @return list<string>
     */
    private function suggestionsFor(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 5) {
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
     * The services the tech / operator can pick from — the site's vocabulary, mirrored from its Service
     * catalog on read ({@see JobTypeVocabulary}) so the list is never empty on a real site. Labels, A–Z.
     *
     * @return list<string>
     */
    public function getJobTypeOptionsProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return array_values(array_unique(array_column(app(JobTypeVocabulary::class)->options($this->siteId), 'label')));
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
        return $this->labelsFrom($this->newJobTypeLabels, $this->newJobTypesOther);
    }

    /**
     * Merge picked labels with a comma-separated "other" list, trimmed, de-duped, capped.
     *
     * @param  array<int, string>  $picked
     * @return list<string>
     */
    private function labelsFrom(array $picked, string $other): array
    {
        return collect($picked)
            ->merge(explode(',', $other))
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

    /** The active site as a model the user may see — for intake + the photo library. */
    protected function workingSite(): ?Site
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);

        return $site !== null && $this->user()->canSeeSite((string) $site->id) ? $site : null;
    }

    /** Import previous jobs in bulk from an uploaded CSV — each row becomes a captured job in this queue. */
    public function importCsv(): void
    {
        if (! $this->can(Capability::EditContent) || $this->siteId === null) {
            return;
        }
        $site = $this->workingSite();
        if ($site === null || ! $this->csvFile instanceof TemporaryUploadedFile) {
            Notification::make()->title('Choose a CSV file first.')->warning()->send();

            return;
        }

        $result = app(CsvJobImporter::class)->import($site, (string) $this->csvFile->get());
        $this->csvFile = null;

        $skipped = count($result['skipped']);
        $body = $skipped > 0
            ? $skipped.' row'.($skipped === 1 ? '' : 's').' skipped ('.collect($result['skipped'])->take(3)->map(fn (array $s): string => 'row '.$s['row'].': '.$s['reason'])->implode('; ').($skipped > 3 ? '…' : '').')'
            : null;
        if ($result['truncated']) {
            $body = trim(($body ?? '').' Only the first '.CsvJobImporter::MAX_ROWS.' rows were imported — split larger files.');
        }

        Notification::make()
            ->title($result['imported'].' job'.($result['imported'] === 1 ? '' : 's').' imported — resolving & enhancing, then they land here.')
            ->body($body)
            ->{$result['imported'] > 0 ? 'success' : 'warning'}()
            ->send();
    }

    /** Download the CSV template (columns + an example row) for the bulk import. */
    public function downloadTemplate(): StreamedResponse
    {
        $csv = app(CsvJobImporter::class)->template();

        return response()->streamDownload(fn () => print ($csv), 'job-import-template.csv', ['Content-Type' => 'text/csv']);
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

    /**
     * The working account's reusable library photos, for the per-job "attach from library" picker.
     *
     * @return list<array{id: string, url: string, label: ?string}>
     */
    public function getLibraryPhotosProperty(): array
    {
        $account = $this->workingSite()?->account;
        if ($account === null) {
            return [];
        }

        return LibraryPhoto::query()->where('account_id', $account->id)->latest()->limit(24)->get()
            ->map(fn (LibraryPhoto $p): array => ['id' => (string) $p->id, 'url' => $p->url(), 'label' => $p->label])
            ->all();
    }

    /** Attach one library photo to a job — its own copy, geotagged to this job's point. */
    public function attachFromLibrary(string $jobId, string $photoId): void
    {
        if (! $this->can(Capability::EditContent)) {
            return;
        }
        $job = $this->ownedJob($jobId);
        if ($job === null) {
            return;
        }

        $added = app(LibraryPhotoAttacher::class)->attach($job, [$photoId]);

        $added > 0
            ? Notification::make()->title('Added from library.')->success()->send()
            : Notification::make()->title('No room for more photos on this job (max '.Job::MAX_PHOTOS.').')->warning()->send();
    }

    /** A job the current user may act on — the host page's tenant guard. */
    abstract protected function ownedJob(string $id): ?Job;

    abstract public function can(Capability $capability): bool;

    abstract protected function user(): User;
}
