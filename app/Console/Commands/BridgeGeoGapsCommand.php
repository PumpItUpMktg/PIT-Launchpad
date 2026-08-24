<?php

namespace App\Console\Commands;

use App\Geo\GeoGapBridge;
use App\Support\SiteFinder;
use Illuminate\Console\Command;

/**
 * Bridge a site's absent GEO gaps into directed content candidates — each gap (a prompt no AI engine
 * cites) becomes one kind=post candidate on the review queue, ready for the operator to generate and
 * publish. Bounded + idempotent; nothing is drafted or published here (generation is never automatic).
 */
class BridgeGeoGapsCommand extends Command
{
    protected $signature = 'sandhog:bridge-geo-gaps {site : Site id, brand name, or domain (partial ok)}';

    protected $description = 'Turn a site\'s absent GEO gaps into directed content candidates.';

    public function handle(GeoGapBridge $bridge): int
    {
        $needle = (string) $this->argument('site');
        $matches = SiteFinder::matches($needle);

        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return self::FAILURE;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return self::FAILURE;
        }

        $site = $matches->first();
        $r = $bridge->bridge($site);

        $this->line(sprintf(
            '<info>%s</info> — %d candidate(s) created, %d reused, across %d absent gap(s).',
            $site->brand_name, $r['created'], $r['reused'], $r['gaps'],
        ));

        return self::SUCCESS;
    }
}
