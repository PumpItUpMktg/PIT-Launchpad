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
 * never runs inline. An uploaded CSV/XLSX travels INSIDE the {@see ReviewImport} record (`source_contents`):
 * the web node that took the upload and the worker that runs this job do not share a local disk, so a staged
 * file path was unreadable here and every file import failed. A Google Sheet is fetched by URL. Runs the
 * {@see ReviewImporter} (resolve → dedupe → create imported/pending reviews, preserving original dates) and
 * records progress + the skipped-row report. Any failure lands the import as `failed` with a reason.
 */
class ImportReviews implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param  'csv'|'xlsx'|'sheet'  $sourceType
     * @param  string|null  $location  a Sheet URL, or a legacy file path; null when the bytes are on the record
     * @param  array<string, string>  $mapping
     */
    public function __construct(
        public readonly string $importId,
        public readonly string $sourceType,
        public readonly ?string $location,
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
            $rows = $this->rows($reader, $import);
            $importer->import($site, $rows, $this->mapping, is_string($import->import_source) ? $import->import_source : null, $import);
        } catch (Throwable $e) {
            $import->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)])->save();
            report($e);
        }
    }

    /** @return list<array<string, string>> */
    private function rows(ReviewImportReader $reader, ReviewImport $import): array
    {
        $contents = $import->source_contents;
        if ($this->sourceType === 'sheet') {
            return $reader->googleSheet((string) $this->location);
        }
        if ($this->sourceType === 'csv') {
            return $reader->csv($contents ?? (string) file_get_contents((string) $this->location));
        }

        // XLSX: the parser wants a file, so the carried bytes land in a worker-local temp file for the read.
        if ($contents === null) {
            return $reader->xlsx((string) $this->location);
        }
        $path = tempnam(sys_get_temp_dir(), 'review-import').'.xlsx';
        file_put_contents($path, (string) base64_decode($contents, true));
        try {
            return $reader->xlsx($path);
        } finally {
            @unlink($path);
        }
    }
}
