<?php

namespace App\Console\Commands;

use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;

/**
 * Delete GEO prompts for an area the operator isn't targeting (e.g. a state seeded by mistake, or a shop
 * being dropped) — scoped by `--state` or `--location`. PREVIEW BY DEFAULT: reports what it would delete
 * and changes nothing until `--apply`. Removes the prompts AND their snapshots. Idempotent.
 */
class PruneGeoPromptsCommand extends Command
{
    protected $signature = 'sandhog:prune-geo-prompts {site : Site id, brand name, or domain (partial ok)}
        {--state= : Delete prompts for towns in this state (e.g. MD)}
        {--location= : Delete prompts for a brick-and-mortar shop (id or name)}
        {--apply : Actually delete (default is a preview that changes nothing)}';

    protected $description = 'Delete GEO prompts for an untargeted area (by --state or --location); preview by default.';

    public function handle(): int
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

        $state = $this->option('state') !== null ? trim((string) $this->option('state')) : null;
        $locationArg = $this->option('location') !== null ? trim((string) $this->option('location')) : null;
        if (($state === null || $state === '') && ($locationArg === null || $locationArg === '')) {
            $this->error('Give a scope: --state=MD or --location="Shop Name".');

            return self::FAILURE;
        }

        $townIds = $this->targetTownIds($site, $state, $locationArg);
        if ($townIds === null) {
            return self::FAILURE;   // bad location
        }

        $prompts = GeoPrompt::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereIn('coverage_area_id', $townIds);
        $count = $prompts->count();

        if ($count === 0) {
            $this->info("{$site->brand_name} — no GEO prompts match that scope.");

            return self::SUCCESS;
        }

        $scope = $state !== null && $state !== '' ? "state {$state}" : "shop [{$locationArg}]";
        if (! $this->option('apply')) {
            $this->comment("Preview only — {$count} GEO prompt(s) for {$scope} would be deleted. Re-run with --apply to delete.");

            return self::SUCCESS;
        }

        $promptIds = (clone $prompts)->pluck('id');
        GeoSnapshot::withoutGlobalScope(SiteScope::class)->whereIn('geo_prompt_id', $promptIds)->delete();
        $prompts->delete();

        $this->info("Deleted {$count} GEO prompt(s) + their snapshots for {$scope}.");

        return self::SUCCESS;
    }

    /**
     * The coverage-town ids in the requested scope. Null on an unresolvable location.
     *
     * @return list<string>|null
     */
    private function targetTownIds(Site $site, ?string $state, ?string $locationArg): ?array
    {
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);
        if ($state !== null && $state !== '') {
            $towns->where('state', $state);
        }
        $rows = $towns->get(['id', 'source_location_ids']);

        if ($locationArg !== null && $locationArg !== '') {
            $location = Location::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where(fn ($q) => $q->where('id', $locationArg)->orWhere('name', $locationArg))
                ->first();
            if ($location === null) {
                $this->error("No shop matches [{$locationArg}] for {$site->brand_name}.");

                return null;
            }
            $rows = $rows->filter(fn (CoverageArea $t): bool => in_array($location->id, $t->source_location_ids ?? [], true))->values();
        }

        return $rows->pluck('id')->all();
    }
}
