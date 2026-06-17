<?php

namespace App\Console\Commands;

use App\Locations\CoverageResult;
use App\Locations\CoverageWriter;
use App\Locations\LocationCoverage;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Locations coverage gate: enumerate a site's service-area municipalities from its base
 * locations (point + radius) via Census and print the per-base sets + the deduplicated
 * union. Dry-run by default; --persist writes the CoverageArea set (the Phase-3
 * dependency). The gate is the human eyeball: real towns in range, MCDs present (not
 * just incorporated places), cross-border towns where the radius reaches, union dedupes.
 *
 *   launchpad:locations-coverage {site} [--json] [--persist]
 */
class LocationsCoverageCommand extends Command
{
    protected $signature = 'launchpad:locations-coverage
        {site : the Site id}
        {--radius= : apply this radius (miles) to ALL base locations for this run (calibration)}
        {--save : persist the --radius onto the Location records (same field the UI reads)}
        {--json : emit the raw coverage set as JSON}
        {--persist : write the union as the site CoverageArea set (default is dry-run)}';

    protected $description = 'Enumerate a site\'s service-area coverage municipalities (Census), dry-run by default.';

    public function handle(LocationCoverage $coverage, CoverageWriter $writer): int
    {
        $site = Site::query()->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $radiusOpt = $this->option('radius');
        $override = ($radiusOpt !== null && $radiusOpt !== '') ? (int) $radiusOpt : null;

        if ($this->option('save')) {
            if ($override === null) {
                $this->error('--save requires --radius=N.');

                return self::FAILURE;
            }
            Location::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->update(['coverage_radius' => $override]);
            $this->info("Saved radius {$override}mi onto the base locations.");
        }

        $result = $coverage->coverage($site, $override);

        if ($result->perBase === []) {
            $this->error('No configured base locations — each needs a geocoded point (lat/lng) and a coverage radius.');

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
            $this->info("▌ {$base->locationName} — {$base->radiusMiles}mi · ".count($base->municipalities).' municipalities');
            foreach ($base->municipalities as $m) {
                $this->line(sprintf('   %-22s %-22s %2s  %.1fmi', $m->name, $m->type->label(), $m->state ?? '', $m->distanceMiles));
            }
        }

        $this->newLine();
        $this->line(sprintf('UNION: %d municipalities (%d places · %d townships/boroughs) across %d base locations',
            $result->unionCount(), $result->placeCount(), $result->mcdCount(), count($result->perBase)));
    }
}
