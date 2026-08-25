<?php

namespace App\Console\Commands;

use App\Geo\GeoPromptSeeder;
use App\Support\SiteFinder;
use Illuminate\Console\Command;

/**
 * Auto-seed a site's GEO prompt set from its service × market × intent matrix (bounded, idempotent).
 * Operator-run: it populates the board so the coverage matrix has something to measure.
 */
class SeedGeoPromptsCommand extends Command
{
    protected $signature = 'sandhog:seed-geo-prompts {site : Site id, brand name, or domain (partial ok)}';

    protected $description = 'Auto-seed GEO prompts from a site\'s services × towns × intents (bounded).';

    public function handle(GeoPromptSeeder $seeder): int
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
        $r = $seeder->seed($site);

        $this->line(sprintf(
            '<info>%s</info> — %d prompt(s) created, %d already present (from %d service(s) × %d town(s)).',
            $site->brand_name, $r['created'], $r['skipped'], $r['services'], $r['towns'],
        ));

        return self::SUCCESS;
    }
}
