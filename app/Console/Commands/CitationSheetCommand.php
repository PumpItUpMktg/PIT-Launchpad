<?php

namespace App\Console\Commands;

use App\Citations\CitationSheetExport;
use App\Citations\CitationWorkbook;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * launchpad:citation-sheet — the citation tracker, per office, filled from the platform: the canonical NAP
 * block and every applicable directory with what the scan knows (status, live URL, where to create one).
 */
class CitationSheetCommand extends Command
{
    protected $signature = 'launchpad:citation-sheet
        {--site= : The site ULID}
        {--location= : One location ULID (default: every office)}
        {--format=json : json (every office in one document) | csv (one office — the tracker tab\'s layout) | xlsx (the whole work order: a tab per office)}
        {--out= : xlsx only — where to write the workbook (default: storage/app/<brand>-citations-<date>.xlsx)}';

    protected $description = 'Export the citation tracker per office from the platform: the NAP block + every directory with the scan\'s status, live URL and notes.';

    public function handle(CitationSheetExport $export): int
    {
        $site = Site::withoutGlobalScopes()->find((string) $this->option('site'));
        if ($site === null) {
            $this->error('Pass --site=<ulid>.');

            return self::FAILURE;
        }
        $locationId = $this->option('location') !== null ? (string) $this->option('location') : null;
        if ((string) $this->option('format') === 'xlsx') {
            $out = trim((string) $this->option('out'));
            $path = $out !== '' ? $out : storage_path('app/'.CitationWorkbook::filename($site));
            app(CitationWorkbook::class)->build($site, $locationId, $path);
            $this->info("Workbook written: {$path}");

            return self::SUCCESS;
        }
        $data = $export->forSite($site, $locationId);
        if ((string) $this->option('format') === 'csv') {
            if (count($data['locations']) !== 1) {
                $this->error('CSV is one office at a time — pass --location=<ulid>. Offices: '.implode(', ', array_map(fn (array $l): string => $l['name'].' ('.$l['id'].')', $data['locations'])));

                return self::FAILURE;
            }
            $this->output->write(CitationSheetExport::csv($data['locations'][0]));

            return self::SUCCESS;
        }
        $this->output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
