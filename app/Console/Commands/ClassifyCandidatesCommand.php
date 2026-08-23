<?php

namespace App\Console\Commands;

use App\ContentEngine\CandidateBackfill;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Backfill the §6a timeliness pill (Local / Time-sensitive / Evergreen) onto candidates
 * ingested before the feature shipped, by re-running the Haiku relevance scorer over each
 * undrafted candidate. --drop-competitors also rejects competitor-announcement rows the
 * old funnel let through. Idempotent and safe to re-run.
 */
class ClassifyCandidatesCommand extends Command
{
    protected $signature = 'launchpad:classify-candidates {site : Site id or brand name}
        {--drop-competitors : Also reject candidates the scorer flags as a competitor\'s own announcement}';

    protected $description = 'Backfill the timeliness pill onto existing candidates (and optionally drop competitor announcements).';

    public function handle(CandidateBackfill $backfill): int
    {
        $site = Site::withoutGlobalScopes()
            ->where('id', $this->argument('site'))->orWhere('brand_name', $this->argument('site'))->first();

        if ($site === null) {
            $this->error("No site matches [{$this->argument('site')}].");

            return self::FAILURE;
        }

        $r = $backfill->backfill($site, (bool) $this->option('drop-competitors'));

        $this->line(sprintf(
            '<info>%s</info> — %d candidate(s) scanned, %d classified, %d competitor announcement(s) found%s.',
            $site->brand_name,
            $r['scanned'],
            $r['classified'],
            $r['competitors'],
            $this->option('drop-competitors') ? " ({$r['dropped']} dropped)" : ' (kept — pass --drop-competitors to remove)',
        ));

        return self::SUCCESS;
    }
}
