<?php

namespace App\Console\Commands;

use App\Links\LinkProspector;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * The competitor link intersect for one site (§ Links): which domains link to the local competitors Town
 * Rank finds above us, and not to us. One index call. Prints the competitors used and what the list gained.
 */
class FindLinkProspectsCommand extends Command
{
    protected $signature = 'launchpad:find-link-prospects
        {--site= : Site id or brand name (required)}';

    protected $description = 'Find domains that link to the site\'s local competitors but not to the site, and keep them as link prospects.';

    public function handle(LinkProspector $prospector): int
    {
        $arg = trim((string) $this->option('site'));
        if ($arg === '') {
            $this->error('--site is required (id or brand name).');

            return self::FAILURE;
        }
        $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error("No site matches [{$arg}].");

            return self::FAILURE;
        }

        $r = $prospector->run($site);
        if ($r['targets'] === []) {
            $this->warn("{$site->brand_name} — no local competitors in Town Rank yet; run a town-rank scan first.");

            return self::SUCCESS;
        }

        $this->line("<info>{$site->brand_name}</info> — competitors asked about: ".implode(', ', array_map(
            fn (array $t): string => "{$t['domain']} (above us in {$t['appearances']} results)", $r['targets'])));
        $this->line(sprintf('  • the index returned %d linking domains; %d kept after the spam / authority floor — %d new prospect(s), %d refreshed.',
            $r['returned'], $r['kept'], $r['new'], $r['refreshed']));

        return self::SUCCESS;
    }
}
