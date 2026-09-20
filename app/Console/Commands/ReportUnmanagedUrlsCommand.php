<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Operator\Coverage\UnmanagedUrls;
use Illuminate\Console\Command;

/**
 * What Search Console counts that the Indexing board does not — the URLs Google holds on this property
 * which Launchpad never published.
 *
 *   launchpad:report-unmanaged-urls --site=... [--examples=25]
 *
 * Search Console's "all known pages" spans the whole property: category archives (one per silo, created
 * by our own publish pipeline), tag archives, pagination, author pages, anything added in WP by hand.
 * The board counts the pages Launchpad published, on purpose. Google publishes no API for its Page
 * Indexing report, so this cannot mirror that total exactly — it enumerates every URL Google has actually
 * SHOWN and names the ones that are not ours, which accounts for the visible half with real data.
 *
 * Read-only and HTTP-free.
 */
class ReportUnmanagedUrlsCommand extends Command
{
    protected $signature = 'launchpad:report-unmanaged-urls
        {--site= : Site id or brand name}
        {--examples=25 : How many example URLs to list}';

    protected $description = 'List the indexed URLs on this property that Launchpad did not publish (archives, tags, pagination).';

    public function handle(UnmanagedUrls $unmanaged): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('No site found — pass --site= with a site id or brand name.');

            return self::FAILURE;
        }

        $r = $unmanaged->for($site, max(1, (int) $this->option('examples')));

        $this->info($site->brand_name.' — URLs Google has shown that Launchpad did not publish');
        $this->newLine();
        $this->line(sprintf('%d URL(s) Google has shown map to a page we published; <info>%d do not</info>, '
            .'carrying %s impression(s) between them.',
            $r['managed'], $r['unmanaged'], number_format($r['unmanaged_impressions'])));
        $this->line('Search Console counts the whole property. The Indexing board counts what Launchpad published,');
        $this->line('on purpose — so the two totals are not meant to match, and the difference lives here.');
        $this->newLine();

        if ($r['unmanaged'] === 0) {
            $this->line('<info>Nothing outside the published set has earned an impression.</info>');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($r['buckets'] as $bucket => $stats) {
            $rows[] = [$bucket, $stats['urls'], number_format($stats['impressions'])];
        }
        $this->table(['Shape', 'URLs', 'Impressions'], $rows);

        $this->newLine();
        $this->line('<comment>Examples, most impressions first</comment>');
        foreach ($r['examples'] as $row) {
            $this->line(sprintf('  · %-18s %6s  %s', $row['bucket'], number_format($row['impressions']), $row['url']));
        }

        $this->newLine();
        $this->line('This only sees URLs that have EARNED an impression — an indexed archive nobody ever reaches');
        $this->line('is invisible here and still counts in Search Console. Google exposes no API for that report,');
        $this->line('so the remainder cannot be enumerated from our side; export it from the Page indexing screen.');

        return self::SUCCESS;
    }

    private function resolveSite(): ?Site
    {
        $id = $this->option('site');
        if (! is_string($id) || $id === '') {
            return null;
        }

        return Site::query()->find($id) ?? Site::query()->where('brand_name', $id)->first();
    }
}
