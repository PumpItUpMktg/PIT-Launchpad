<?php

namespace App\Console\Commands;

use App\Locations\CountyCoverage;
use App\Locations\CoverageResult;
use App\Locations\CoverageWriter;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Locations coverage gate: enumerate a site's service-area municipalities from its base
 * locations' selected counties (every county subdivision joined to ACS population) and
 * print the per-base sets + the deduplicated union. Dry-run by default; --persist writes
 * the CoverageArea set (the Phase-3 dependency). The gate is the human eyeball: real towns
 * in the served counties, the Large/Medium/Small split looks right, union dedupes.
 *
 *   launchpad:locations-coverage {site} [--json] [--persist]
 */
class LocationsCoverageCommand extends Command
{
    protected $signature = 'launchpad:locations-coverage
        {site : the Site id}
        {--json : emit the raw coverage set as JSON}
        {--persist : write the union as the site CoverageArea set (default is dry-run)}';

    protected $description = 'Enumerate a site\'s county-based service-area coverage (Census), dry-run by default.';

    public function handle(CountyCoverage $coverage, CoverageWriter $writer): int
    {
        $site = Site::query()->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $result = $coverage->coverage($site);

        if ($result->perBase === []) {
            $this->error('No coverage — each base location needs a geocoded point and at least one selected county.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($result);
        }

        if ($this->option('persist')) {
            $count = $writer->write($site, $result);
            $this->newLine();
            $this->info("Persisted coverage set: {$count} municipalities.");
        } else {
            $this->newLine();
            $this->comment('Dry run — nothing written. Re-run with --persist to save the coverage set.');
        }

        return self::SUCCESS;
    }

    private function render(CoverageResult $result): void
    {
        foreach ($result->perBase as $base) {
            $this->newLine();
            $this->info("▌ {$base->locationName} — ".count($base->municipalities).' municipalities');
            foreach ($base->municipalities as $m) {
                $this->line(sprintf('   %-22s %-22s %2s  %s', $m->name, $m->type->label(), $m->state ?? '', $m->bucket()->value));
            }
        }

        $buckets = CoverageResult::bucketCounts($result->union);
        $this->newLine();
        $this->line(sprintf('UNION: %d municipalities (%d places · %d townships/boroughs) across %d base locations',
            $result->unionCount(), $result->placeCount(), $result->mcdCount(), count($result->perBase)));
        $this->line(sprintf('GROUPING: %d large · %d medium · %d small · %d ungrouped',
            $buckets['large'], $buckets['medium'], $buckets['small'], $buckets['unknown']));
    }
}
