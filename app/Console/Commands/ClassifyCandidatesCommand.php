<?php

namespace App\Console\Commands;

use App\ContentEngine\CandidateBackfill;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

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
        $needle = (string) $this->argument('site');
        $matches = SiteFinder::matches($needle);

        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}]. Available sites:");
            $this->listSites(SiteFinder::all());

            return self::FAILURE;
        }

        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id or exact name:");
            $this->listSites($matches);

            return self::FAILURE;
        }

        $site = $matches->first();

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

    /** @param  Collection<int, Site>  $sites */
    private function listSites(Collection $sites): void
    {
        if ($sites->isEmpty()) {
            $this->line('  (none)');

            return;
        }

        $this->table(
            ['Brand name', 'Site id', 'Domain'],
            $sites->map(fn (Site $s): array => [$s->brand_name, $s->id, $s->domain_url])->all(),
        );
    }
}
