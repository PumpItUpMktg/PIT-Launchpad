<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSiteLocation;
use App\Geo\GeoPromptSeeder;
use App\Support\SiteFinder;
use Illuminate\Console\Command;

/**
 * Auto-seed a site's GEO prompt set from its service × town × intent matrix (bounded, idempotent).
 * Operator-run: it populates the board so the coverage matrix has something to measure. `--location`
 * scopes to one brick-and-mortar shop's towns (the operator's area selection).
 */
class SeedGeoPromptsCommand extends Command
{
    use ResolvesSiteLocation;

    protected $signature = 'sandhog:seed-geo-prompts {site : Site id, brand name, or domain (partial ok)}
        {--location= : Scope to one brick-and-mortar shop (id or name)}';

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
        $locationId = $this->resolveLocationId($site);
        if ($locationId === false) {
            return self::FAILURE;
        }
        $r = $seeder->seed($site, $locationId);

        $this->line(sprintf(
            '<info>%s</info> — %d prompt(s) created, %d already present (from %d service(s) × %d town(s)).',
            $site->brand_name, $r['created'], $r['skipped'], $r['services'], $r['towns'],
        ));

        return self::SUCCESS;
    }
}
