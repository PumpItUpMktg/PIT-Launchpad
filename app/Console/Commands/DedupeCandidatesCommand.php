<?php

namespace App\Console\Commands;

use App\ContentEngine\DuplicateCandidateCollapser;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Collapse duplicate candidates already on a site's board (the same story re-ingested before
 * article-identity dedup shipped): keep the earliest of each group, reject the rest. Idempotent.
 * Going forward the funnel prevents these at ingest; this cleans up the existing backlog.
 */
class DedupeCandidatesCommand extends Command
{
    protected $signature = 'launchpad:dedupe-candidates {site : Site id, brand name, or domain (partial ok)}';

    protected $description = 'Collapse duplicate candidates on a site\'s board (keep the earliest, reject the rest).';

    public function handle(DuplicateCandidateCollapser $collapser): int
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
        $r = $collapser->collapse($site);

        $this->line(sprintf(
            '<info>%s</info> — %d duplicate group(s), %d duplicate candidate(s) rejected (earliest kept).',
            $site->brand_name, $r['groups'], $r['duplicates'],
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
