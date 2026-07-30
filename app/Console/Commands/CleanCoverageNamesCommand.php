<?php

namespace App\Console\Commands;

use App\Locations\CoverageName;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Repair the numbered-list parse artifact in coverage-town names — the "6, Havre de Grace" /
 * "2, Halls Cross Roads" shape that leaked a "6-havre-de-grace-md" page title + slug. Strips the
 * leading list marker from `coverage_areas.name` AND each `locations.served_towns[].name`, at the
 * SOURCE, so the next materialize/repush mints clean titles + slugs. The write-path can no longer
 * re-introduce it ({@see CoverageArea} normalizes on write; {@see CoverageName}).
 *
 * PREVIEW BY DEFAULT — lists what it would rename and changes nothing until `--apply`. With no site
 * argument it sweeps every tenant (the fleet-wide backfill); a site id/brand scopes it to one.
 *
 * Note: this fixes the SOURCE data. Already-materialized town PAGES keep their old title/slug until
 * re-materialized — remove the malformed drafts + regenerate (or repush) to pick up the clean names.
 */
class CleanCoverageNamesCommand extends Command
{
    protected $signature = 'launchpad:clean-coverage-names {site? : Site id or brand name (omit to sweep every tenant)}
        {--apply : Actually rewrite the names (default is a preview that changes nothing)}';

    protected $description = 'Strip the numbered-list artifact ("6, Havre de Grace" → "Havre de Grace") from coverage-town + served-town names (preview by default; --apply to fix).';

    public function handle(): int
    {
        $sites = $this->resolveSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $coverageFixed = 0;
        $servedFixed = 0;

        foreach ($sites as $site) {
            $coverageFixed += $this->cleanCoverage($site, $apply);
            $servedFixed += $this->cleanServedTowns($site, $apply);
        }

        $this->newLine();
        if (! $apply) {
            $this->comment("Preview only — nothing changed. Re-run with --apply to fix {$coverageFixed} coverage name(s) + {$servedFixed} served-town list(s).");

            return self::SUCCESS;
        }

        $this->info("Cleaned {$coverageFixed} coverage name(s) + {$servedFixed} served-town list(s). "
            .'Remove the malformed town drafts + regenerate (or repush) so the pages pick up the clean titles + slugs.');

        return self::SUCCESS;
    }

    /** @return Collection<int, Site>|null */
    private function resolveSites(): ?Collection
    {
        $arg = $this->argument('site');
        if ($arg === null) {
            return Site::query()->get();
        }

        $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error("No site matches [{$arg}].");

            return null;
        }

        return collect([$site]);
    }

    private function cleanCoverage(Site $site, bool $apply): int
    {
        $dirty = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get()
            ->filter(fn (CoverageArea $a): bool => CoverageName::isDirty((string) $a->name));

        if ($dirty->isEmpty()) {
            return 0;
        }

        $this->line("<info>{$site->brand_name}</info> — {$dirty->count()} coverage town(s) with a numbered-list artifact:");
        foreach ($dirty as $area) {
            $this->line("  • <comment>{$area->name}</comment> → ".CoverageName::clean((string) $area->name));
            if ($apply) {
                // The model's name mutator does the cleaning on save.
                $area->forceFill(['name' => (string) $area->name])->save();
            }
        }

        return $dirty->count();
    }

    private function cleanServedTowns(Site $site, bool $apply): int
    {
        $fixed = 0;
        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();

        foreach ($locations as $location) {
            $towns = is_array($location->served_towns) ? $location->served_towns : [];
            if ($towns === []) {
                continue;
            }

            $changed = false;
            foreach ($towns as $i => $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name !== '' && CoverageName::isDirty($name)) {
                    $towns[$i]['name'] = CoverageName::clean($name);
                    $changed = true;
                }
            }

            if ($changed) {
                $fixed++;
                $this->line("  • <info>{$site->brand_name}</info> · {$location->name} — cleaned served-town name(s).");
                if ($apply) {
                    $location->forceFill(['served_towns' => array_values($towns)])->save();
                }
            }
        }

        return $fixed;
    }
}
