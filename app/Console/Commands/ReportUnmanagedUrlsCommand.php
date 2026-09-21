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
        $ctr = fn (int $clicks, int $impressions): string => $impressions > 0
            ? number_format($clicks / $impressions * 100, 2).'%'
            : '—';

        $this->line(sprintf('%d URL(s) Google has shown map to a page we published: %s impression(s), %s click(s), %s CTR.',
            $r['managed'], number_format($r['managed_impressions']), number_format($r['managed_clicks']),
            $ctr($r['managed_clicks'], $r['managed_impressions'])));
        $total = $r['managed_impressions'] + $r['unmanaged_impressions'];
        $this->line(sprintf('<info>%d do not</info>: %s impression(s), %s click(s), %s CTR — <info>%d%%</info> of everything this property has earned.',
            $r['unmanaged'], number_format($r['unmanaged_impressions']), number_format($r['unmanaged_clicks']),
            $ctr($r['unmanaged_clicks'], $r['unmanaged_impressions']),
            $total > 0 ? (int) round($r['unmanaged_impressions'] / $total * 100) : 0));
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

        // Where the unmanaged traffic sits decides what to do with it. A page-one winner must not be
        // touched carelessly; a page-three also-ran with real impressions is the cheapest win on the site.
        $bands = [];
        foreach ($r['position_bands'] as $band => $stats) {
            $bands[] = [$band, $stats['urls'], number_format($stats['impressions']), number_format($stats['clicks']),
                $ctr($stats['clicks'], $stats['impressions'])];
        }
        $this->newLine();
        $this->table(['Avg position', 'URLs', 'Impressions', 'Clicks', 'CTR'], $bands);

        $this->newLine();
        $this->line('<comment>Examples, most impressions first</comment>');
        foreach ($r['examples'] as $row) {
            $this->line(sprintf('  · %-18s %6s  %s', $row['bucket'], number_format($row['impressions']), $row['url']));
        }

        $dupes = ($r['buckets']['duplicate of a published page']['urls'] ?? 0)
            + ($r['buckets']['numbered twin (not ours)']['urls'] ?? 0);
        if ($dupes > 0) {
            $this->newLine();
            $this->line(sprintf('<comment>%d of these are numbered duplicates.</comment> WordPress appends -2, -3, -10 when a slug it is '
                .'asked to create already exists, so a numbered twin is never a coincidence — it is the same title published more than once.',
                $dupes));
            if (($r['buckets']['duplicate of a published page']['urls'] ?? 0) > 0) {
                $this->line('  "duplicate of a published page" is OURS: strip the suffix and it is a page Launchpad publishes, which');
                $this->line('  means WordPress refused our slug and the URL we believe in is not the URL Google indexed.');
            }
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
