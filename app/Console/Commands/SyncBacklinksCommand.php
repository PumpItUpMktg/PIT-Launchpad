<?php

namespace App\Console\Commands;

use App\Backlinks\BacklinkSyncer;
use App\Models\BacklinkSyncRun;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Pull the backlink index into `site_backlinks` for one site or every site (§ Links). Monthly on the
 * schedule; run by hand after a redirect clean-up or before reading the citation workspace's link column.
 * One summary call plus one call per 1,000 referring domains per site.
 */
class SyncBacklinksCommand extends Command
{
    protected $signature = 'launchpad:sync-backlinks
        {--site= : Site id or brand name}
        {--all : Every site with a domain}';

    protected $description = 'Sync each site\'s backlink profile (one row per referring domain) from the backlink index.';

    public function handle(BacklinkSyncer $syncer): int
    {
        $arg = trim((string) $this->option('site'));
        if ($arg === '' && ! $this->option('all')) {
            $this->error('Pass --site=<id or brand name> or --all.');

            return self::FAILURE;
        }

        $sites = $arg !== ''
            ? Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->get()
            : Site::query()->whereNotNull('domain_url')->where('domain_url', '!=', '')->get();

        if ($sites->isEmpty()) {
            $this->error($arg !== '' ? "No site matches [{$arg}]." : 'No sites with a domain.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($sites as $site) {
            $run = $syncer->run($site);
            if ($run->status === BacklinkSyncRun::FAILED) {
                $failed++;
                $this->error("{$site->brand_name} — sync failed: {$run->error}");

                continue;
            }
            $this->line(sprintf('<info>%s</info> (%s) — %s referring domains in the index, %s backlinks, %s broken; stored %s domain rows in %d page(s); authority %s.',
                $site->brand_name, $run->target, number_format($run->referring_domains), number_format($run->backlinks), number_format($run->broken_backlinks),
                number_format($run->rows_stored), $run->pages_fetched, $run->rank ?? '—'));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
