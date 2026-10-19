<?php

namespace App\Console\Commands;

use App\Integrations\DataForSeo\DataForSeoException;
use App\Links\CompetitorTargets;
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

    public function handle(LinkProspector $prospector, CompetitorTargets $targetsFor): int
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

        $targets = $targetsFor->for($site, max(1, (int) config('launchpad.links.competitor_targets', 10)));
        if ($targets === []) {
            $this->warn("{$site->brand_name} — no local competitors in Town Rank yet; run a town-rank scan first.");

            return self::SUCCESS;
        }
        // Said BEFORE the index call, so a failed call still shows what was asked.
        $this->line("<info>{$site->brand_name}</info> — competitors asked about: ".implode(', ', array_map(
            fn (array $t): string => "{$t['domain']} (above us in {$t['appearances']} results)", $targets)));

        try {
            $r = $prospector->run($site);
        } catch (DataForSeoException $e) {
            $this->error('The backlink index refused the intersect: '.$e->getMessage());
            $this->comment('Nothing was written. If a competitor above looks wrong (a platform, not a business), say so and it will be excluded; a 5xx is their edge — run again in a minute.');

            return self::FAILURE;
        }

        $this->line(sprintf('  • the index returned %d linking domains; %d kept after the spam / authority floor — %d new prospect(s), %d refreshed.',
            $r['returned'], $r['kept'], $r['new'], $r['refreshed']));

        return self::SUCCESS;
    }
}
