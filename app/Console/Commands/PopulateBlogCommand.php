<?php

namespace App\Console\Commands;

use App\ContentEngine\Feeds\BlogPopulator;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Light the blog fuse for a site on demand — rebucket keywords → reconcile generated feeds → ingest
 * → candidates — and print the staged counts so a zero pinpoints WHY the blog is empty. This is the
 * synchronous "check + populate" tool (no FPM clock on the console); the operator button dispatches
 * the same chain as a queued job. Does NOT run keyword discovery (its own gated action) — assumes
 * keywords exist and drives the rest.
 */
class PopulateBlogCommand extends Command
{
    protected $signature = 'launchpad:populate-blog {site? : Site id (default: every site)} {--no-ingest : Only the cheap DB stages (rebucket + reconcile), skip the feed fetch}';

    protected $description = 'Run the silos→feeds→candidates chain for a site and report where (if anywhere) the blog pipeline is empty.';

    public function handle(BlogPopulator $populator): int
    {
        foreach ($this->sites() as $site) {
            $report = $populator->populate($site, ingest: ! $this->option('no-ingest'));

            $this->line("<info>{$site->brand_name}</info> ({$site->id})");
            $this->line(sprintf(
                '  keywords %d (silo-routed %d, re-filed %d) · feeds %d active · fetched %d → candidates %d (parked %d)',
                $report->keywordsTotal,
                $report->keywordsSiloed,
                $report->rebucketed,
                $report->feedsActive,
                $report->fetched,
                $report->candidatesCreated,
                $report->parked,
            ));
            $this->line('  → '.$report->diagnosis());
        }

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, Site>
     */
    private function sites(): iterable
    {
        $id = $this->argument('site');

        return $id !== null
            ? Site::query()->whereKey($id)->get()
            : Site::query()->get();
    }
}
