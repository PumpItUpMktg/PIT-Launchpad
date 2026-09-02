<?php

namespace App\Jobs;

use App\Models\ReviewImport;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Reviews\Import\ReviewImporter;
use App\Reviews\Import\ReviewImportReader;
use App\Support\CurrentSite;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Parses a bulk review upload and imports it, off the web request (Review Capture §10) — a 5,000-row sheet
 * never runs inline. Reads the source (CSV/XLSX file path, or a Google Sheet URL), runs the {@see ReviewImporter}
 * (resolve → dedupe → create imported/pending reviews, preserving original dates), and records progress + the
 * skipped-row report on the {@see ReviewImport}. Any failure lands the import as `failed` with a reason.
 */
class ImportReviews implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param  'csv'|'xlsx'|'sheet'  $sourceType
     * @param  array<string, string>  $mapping
     */
    public function __construct(
        public readonly string $importId,
        public readonly string $sourceType,
        public readonly string $location, // file path (csv/xlsx) or URL (sheet)
        public readonly array $mapping,
    ) {}

    public function handle(ReviewImportReader $reader, ReviewImporter $importer): void
    {
        $import = ReviewImport::query()->withoutGlobalScope(SiteScope::class)->find($this->importId);
        if ($import === null) {
            return;
        }
        $site = Site::query()->withoutGlobalScopes()->find($import->site_id);
        if ($site === null) {
            $import->forceFill(['status' => 'failed', 'error' => 'Site not found'])->save();

            return;
        }

        CurrentSite::set((string) $site->id);
        $import->forceFill(['status' => 'processing'])->save();

        try {
            $rows = match ($this->sourceType) {
                'csv' => $reader->csv((string) file_get_contents($this->location)),
                'xlsx' => $reader->xlsx($this->location),
                'sheet' => $reader->googleSheet($this->location),
            };
            $importer->import($site, $rows, $this->mapping, is_string($import->import_source) ? $import->import_source : null, $import);
        } catch (Throwable $e) {
            $import->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)])->save();
            report($e);
        }
    }
}
