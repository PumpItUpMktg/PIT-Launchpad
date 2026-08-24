<?php

namespace App\Console\Commands;

use App\Geo\GeoPromptTopUp;
use App\Support\SiteFinder;
use Illuminate\Console\Command;

/**
 * Generate assisted weakness top-ups — extra AI-phrased prompt variants for a site's absent GEO gaps
 * (prompts cited by no engine). Bounded + neutral; the variants land tagged `assisted` and active.
 */
class TopUpGeoPromptsCommand extends Command
{
    protected $signature = 'sandhog:topup-geo-prompts {site : Site id, brand name, or domain (partial ok)}';

    protected $description = 'Generate assisted prompt variants for a site\'s absent GEO gaps.';

    public function handle(GeoPromptTopUp $topUp): int
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
        $r = $topUp->topUp($site);

        $this->line(sprintf(
            '<info>%s</info> — %d variant prompt(s) created across %d absent gap(s).',
            $site->brand_name, $r['created'], $r['gaps_addressed'],
        ));

        return self::SUCCESS;
    }
}
