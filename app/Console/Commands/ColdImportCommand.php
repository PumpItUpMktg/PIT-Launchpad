<?php

namespace App\Console\Commands;

use App\ColdOutreach\ContactImporter;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

class ColdImportCommand extends Command
{
    protected $signature = 'launchpad:cold-import {site : site id} {file : path to a CSV export} {--source= : label for where the list came from (default: file name)}';

    protected $description = 'Import a cold-email CSV (Apollo, ZoomInfo, Clay…) into a site\'s cold outreach pipeline. Nothing is verified or sent until the pipeline runs.';

    public function handle(ContactImporter $importer): int
    {
        $site = Site::withoutGlobalScope(SiteScope::class)->find((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Unknown site.');

            return self::FAILURE;
        }
        $file = (string) $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }

        $result = $importer->importFile($site, $file, $this->option('source') !== null ? (string) $this->option('source') : null);
        foreach ($result->errors as $error) {
            $this->error($error);
        }
        $this->info($result->summary());

        return $result->errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
