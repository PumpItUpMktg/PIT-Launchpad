<?php

namespace App\Filament\Console\Pages;

use App\JobCapture\Photos\LibraryPhotoUploader;
use App\Models\LibraryPhoto;
use App\Security\Capability;
use BackedEnum;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Console → Jobs → Photo Library: the operator's reusable stock of job photos (§ Job Capture). Upload once —
 * the originals are account-scoped and deduped by content hash — then attach any to a job from the review
 * screen, where each attachment becomes its own copy geotagged to that job's location. Tag/label a photo to
 * find a similar one later; delete removes it from the library (jobs already using a copy keep theirs).
 */
class PhotoLibrary extends ConsolePage
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Photo Library';

    protected static string|\UnitEnum|null $navigationGroup = 'Jobs';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'photo-library';

    protected string $view = 'filament.console.photo-library';

    /** @var array<int, mixed> the pending multi-file upload */
    public array $uploads = [];

    public string $filterTag = '';

    // Inline tag/label edit for one photo.
    public ?string $editingId = null;

    public string $editLabel = '';

    public string $editTags = '';

    /** Store the pending uploads into the account library (deduped). */
    public function upload(): void
    {
        if (! $this->can(Capability::EditContent)) {
            return;
        }
        $account = $this->currentSite()?->account;
        if ($account === null) {
            Notification::make()->warning()->title('Pick a working site first.')->send();

            return;
        }

        $uploader = app(LibraryPhotoUploader::class);
        $added = 0;
        foreach ($this->uploads as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $uploader->upload($account, (string) $file->get(), $file->getClientOriginalName() ?: null, (string) $this->user()->id);
                $added++;
            }
        }
        $this->uploads = [];

        Notification::make()->success()
            ->title($added.' photo'.($added === 1 ? '' : 's').' added to the library')->send();
    }

    /**
     * The account's library photos, newest first, optionally filtered by a tag.
     *
     * @return list<array{id: string, url: string, label: ?string, tags: list<string>, filename: ?string}>
     */
    public function getPhotosProperty(): array
    {
        $account = $this->currentSite()?->account;
        if ($account === null) {
            return [];
        }

        $query = LibraryPhoto::query()->where('account_id', $account->id)->latest();
        $tag = trim($this->filterTag);
        if ($tag !== '') {
            $query->whereJsonContains('tags', $tag);
        }

        return $query->limit(300)->get()->map(fn (LibraryPhoto $p): array => [
            'id' => (string) $p->id,
            'url' => $p->url(),
            'label' => $p->label,
            'tags' => is_array($p->tags) ? $p->tags : [],
            'filename' => $p->original_filename,
        ])->all();
    }

    public function startEdit(string $id): void
    {
        $photo = $this->ownedPhoto($id);
        if ($photo === null) {
            return;
        }
        $this->editingId = (string) $photo->id;
        $this->editLabel = (string) $photo->label;
        $this->editTags = implode(', ', is_array($photo->tags) ? $photo->tags : []);
    }

    public function saveEdit(): void
    {
        if (! $this->can(Capability::EditContent) || $this->editingId === null) {
            return;
        }
        $photo = $this->ownedPhoto($this->editingId);
        if ($photo === null) {
            return;
        }

        $tags = collect(explode(',', $this->editTags))
            ->map(fn (string $t): string => trim($t))->filter()->unique()->values()->all();

        $photo->forceFill([
            'label' => trim($this->editLabel) !== '' ? trim($this->editLabel) : null,
            'tags' => $tags === [] ? null : $tags,
        ])->save();

        $this->cancelEdit();
        Notification::make()->success()->title('Saved.')->send();
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editLabel = '';
        $this->editTags = '';
    }

    public function delete(string $id): void
    {
        if (! $this->can(Capability::EditContent)) {
            return;
        }
        $this->ownedPhoto($id)?->delete();
    }

    /** Load a library photo the current account owns (never another tenant's). */
    private function ownedPhoto(string $id): ?LibraryPhoto
    {
        $account = $this->currentSite()?->account;
        if ($account === null) {
            return null;
        }

        return LibraryPhoto::query()->where('account_id', $account->id)->whereKey($id)->first();
    }
}
