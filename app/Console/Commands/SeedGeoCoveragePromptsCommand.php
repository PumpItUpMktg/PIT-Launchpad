<?php

namespace App\Console\Commands;

use App\Geo\GeoCoveragePromptSeeder;
use App\Support\SiteFinder;
use Illuminate\Console\Command;

/**
 * Seed the GEO coverage-check lane — brand-anchored "does {brand} offer {service} in {town}?" prompts per
 * service × published town, to catch when an AI has wrong/missing facts about a shop's service area. These
 * are an accuracy check (reported apart from the cited% visibility metric), not a visibility number.
 */
class SeedGeoCoveragePromptsCommand extends Command
{
    protected $signature = 'sandhog:seed-geo-coverage-prompts {site : Site id, brand name, or domain (partial ok)}';

    protected $description = 'Seed brand-anchored GEO coverage-check prompts for a site.';

    public function handle(GeoCoveragePromptSeeder $seeder): int
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

        if ($r['created'] === 0 && $r['skipped'] === 0 && trim((string) $site->brand_name) === '') {
            $this->warn("{$site->brand_name} — coverage checks name the business, so a brand name is required (none set).");

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '<info>%s</info> — %d coverage-check prompt(s) created, %d already present (from %d service(s) × %d town(s)).',
            $site->brand_name, $r['created'], $r['skipped'], $r['services'], $r['towns'],
        ));

        return self::SUCCESS;
    }
}
