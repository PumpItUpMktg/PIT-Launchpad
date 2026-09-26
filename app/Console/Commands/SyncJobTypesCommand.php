<?php

namespace App\Console\Commands;

use App\JobCapture\Types\JobTypeVocabulary;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Mirror each site's Service catalog into its Job Capture service list (the job-type vocabulary the phone,
 * the add-job form, the review edit, and the CSV import all pick from). Idempotent; the surfaces sync on
 * read as well, so this is the one-shot backfill for every site at once.
 */
class SyncJobTypesCommand extends Command
{
    protected $signature = 'launchpad:sync-job-types {--site= : Site id or brand name (default: every site)}';

    protected $description = 'Sync every site’s Job Capture service list from its Service catalog.';

    public function handle(JobTypeVocabulary $vocabulary): int
    {
        $filter = trim((string) $this->option('site'));
        $sites = Site::withoutGlobalScopes()
            ->when($filter !== '', fn ($q) => $q->where('id', $filter)->orWhere('brand_name', $filter))
            ->orderBy('brand_name')
            ->get();

        if ($sites->isEmpty()) {
            $this->error($filter !== '' ? "No site matches [{$filter}]." : 'No sites.');

            return self::FAILURE;
        }

        foreach ($sites as $site) {
            $created = $vocabulary->sync((string) $site->id);
            $total = count($vocabulary->options((string) $site->id));
            $this->line(sprintf('%-32s +%d new · %d services pickable', (string) $site->brand_name, $created, $total));
        }

        return self::SUCCESS;
    }
}
