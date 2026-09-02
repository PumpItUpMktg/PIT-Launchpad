<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Jobs\ImportReviews;
use App\Models\ReviewImport;
use App\Reviews\Import\ReviewImporter;
use App\Reviews\Import\ReviewImportReader;
use App\Support\CurrentSite;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Bulk review import (Review Capture §10): upload a CSV / XLSX or paste a Google Sheet URL, map the columns,
 * preview, and commit. The commit dispatches a queued {@see ImportReviews} job with a progress record — a big
 * sheet is never parsed in the web request. Operator-only.
 *
 * @property-read array<int, array<string, mixed>> $recentImports
 */
class ReviewImportPage extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Import reviews';

    protected static string|\UnitEnum|null $navigationGroup = 'Reviews';

    protected string $view = 'filament.pages.review-import';

    public mixed $upload = null;

    public string $sheetUrl = '';

    public string $importSource = '';

    /** @var list<string> */
    public array $columns = [];

    /** @var array<string, string> field => column */
    public array $mapping = [];

    /** @var list<array<string, string>> */
    public array $preview = [];

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /** Read the source's headers + first rows, and auto-guess the column mapping. */
    public function detect(): void
    {
        $rows = $this->readRows(limit: 5);
        if ($rows === []) {
            Notification::make()->warning()->title('No rows found in that source.')->send();

            return;
        }

        $this->columns = array_keys($rows[0]);
        $this->preview = $rows;
        $this->mapping = [];
        foreach (ReviewImporter::FIELDS as $field) {
            foreach ($this->columns as $column) {
                if (mb_strtolower(str_replace([' ', '_'], '', $column)) === str_replace('_', '', $field)) {
                    $this->mapping[$field] = $column;
                    break;
                }
            }
        }
    }

    public function import(): void
    {
        if (! self::canAccess() || CurrentSite::id() === null) {
            return;
        }
        if (empty($this->mapping['rating']) || empty($this->mapping['body']) || empty($this->mapping['reviewed_at'])) {
            Notification::make()->warning()->title('Map rating, body, and date first.')->send();

            return;
        }

        [$sourceType, $location, $filename] = $this->stageSource();
        if ($location === null) {
            Notification::make()->warning()->title('Choose a file or paste a Sheet URL.')->send();

            return;
        }

        $import = ReviewImport::create([
            'site_id' => CurrentSite::id(),
            'created_by_user_id' => Auth::id(),
            'status' => 'pending',
            'source' => $sourceType,
            'import_source' => trim($this->importSource) !== '' ? trim($this->importSource) : null,
            'filename' => $filename,
        ]);

        ImportReviews::dispatch((string) $import->id, $sourceType, $location, $this->mapping);

        $this->reset(['upload', 'sheetUrl', 'columns', 'mapping', 'preview']);
        Notification::make()->success()->title('Import queued')
            ->body('Reviews will land in the queue as pending once the job completes.')->send();
    }

    /** @return list<array<string, mixed>> */
    public function getRecentImportsProperty(): array
    {
        if (CurrentSite::id() === null) {
            return [];
        }

        return ReviewImport::query()->where('site_id', CurrentSite::id())->latest()->limit(10)->get()
            ->map(fn (ReviewImport $i): array => [
                'filename' => $i->filename ?? $i->source,
                'status' => $i->status,
                'imported' => $i->imported_count,
                'skipped' => $i->skipped_count,
                'created' => $i->created_at?->diffForHumans(),
            ])->all();
    }

    /** @return list<array<string, string>> */
    private function readRows(int $limit): array
    {
        $reader = app(ReviewImportReader::class);
        try {
            if ($this->upload instanceof TemporaryUploadedFile) {
                $rows = str_ends_with(mb_strtolower($this->upload->getClientOriginalName()), '.xlsx')
                    ? $reader->xlsx($this->upload->getRealPath())
                    : $reader->csv((string) $this->upload->get());
            } elseif (trim($this->sheetUrl) !== '') {
                $rows = $reader->googleSheet(trim($this->sheetUrl));
            } else {
                return [];
            }
        } catch (\Throwable) {
            Notification::make()->danger()->title('Could not read that source.')->send();

            return [];
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * Persist the upload for the worker and describe the source.
     *
     * @return array{0: 'csv'|'xlsx'|'sheet', 1: string|null, 2: string|null}
     */
    private function stageSource(): array
    {
        if ($this->upload instanceof TemporaryUploadedFile) {
            $isXlsx = str_ends_with(mb_strtolower($this->upload->getClientOriginalName()), '.xlsx');
            $path = $this->upload->store('review-imports', 'local');

            return [$isXlsx ? 'xlsx' : 'csv', Storage::disk('local')->path((string) $path), $this->upload->getClientOriginalName()];
        }
        if (trim($this->sheetUrl) !== '') {
            return ['sheet', trim($this->sheetUrl), 'Google Sheet'];
        }

        return ['csv', null, null];
    }
}
