<?php

namespace App\Operator\Controls;

use App\Enums\ScanCadence;
use App\GeoGrid\CoveragePlanEstimator;
use App\Jobs\RunCoverageScan;
use App\Models\CoverageScanPlan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use Illuminate\Support\Carbon;

/**
 * The operator control behind the coverage-scan scheduler (§7b Controls): read/write a per-GBP scan plan,
 * offer the site's keywords grouped by silo for selection, estimate a run's cost, and fire a plan on demand.
 * The Filament resource is thin over this. Operator context crosses tenants, so {@see SiteScope} is dropped.
 */
final class CoveragePlanControl
{
    public function __construct(private readonly CoveragePlanEstimator $estimator) {}

    /**
     * The site's grid keywords as Filament grouped select options: `['Silo name' => [keywordId => query]]`,
     * with a trailing "Ungrouped" bucket for silo-less keywords. Only `is_grid_keyword` keywords are offered.
     *
     * @return array<string, array<string, string>>
     */
    public function keywordOptions(string $siteId): array
    {
        $grouped = Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->with(['keywords' => fn ($q) => $q->where('is_grid_keyword', true)->orderBy('query')])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Silo $silo): array => [(string) $silo->name => $silo->keywords->pluck('query', 'id')->all()])
            ->filter(fn (array $kws): bool => $kws !== [])
            ->all();

        $ungrouped = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->whereNull('silo_id')->where('is_grid_keyword', true)
            ->orderBy('query')->pluck('query', 'id')->all();
        if ($ungrouped !== []) {
            $grouped['Ungrouped'] = $ungrouped;
        }

        return $grouped;
    }

    /** The plan for a location, or a fresh unsaved one. */
    public function for(Location $location): CoverageScanPlan
    {
        return CoverageScanPlan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)->where('location_id', $location->id)->first()
            ?? new CoverageScanPlan(['site_id' => $location->site_id, 'location_id' => $location->id, 'cadence' => ScanCadence::Monthly, 'enabled' => true, 'keyword_ids' => []]);
    }

    /**
     * @param  list<string>  $keywordIds
     */
    public function save(Location $location, array $keywordIds, ScanCadence $cadence, bool $enabled): CoverageScanPlan
    {
        $plan = $this->for($location);
        $plan->forceFill([
            'site_id' => $location->site_id,
            'location_id' => $location->id,
            'keyword_ids' => $keywordIds,
            'cadence' => $cadence,
            'enabled' => $enabled,
        ])->save();   // the model's saving hook reconciles next_run_at

        return $plan;
    }

    /**
     * Dispatch a coverage scan for every keyword on the plan (one queued job each) and stamp last_run_at.
     * Returns the number of jobs dispatched.
     */
    public function runNow(CoverageScanPlan $plan): int
    {
        $dispatched = 0;
        foreach ($plan->keyword_ids ?: [] as $keywordId) {
            RunCoverageScan::dispatch($plan->location_id, (string) $keywordId);
            $dispatched++;
        }
        if ($dispatched > 0) {
            $plan->forceFill(['last_run_at' => Carbon::now()])->save();
        }

        return $dispatched;
    }

    /**
     * @return array{towns: int, keywords: int, requests: int, cost: float}
     */
    public function estimate(Location $location, int $keywordCount): array
    {
        return $this->estimator->estimate($location, $keywordCount);
    }
}
