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
     * The site's coverage-scan keyword options as Filament grouped selects: `['Silo name' => [id => query]]`,
     * with a trailing "Ungrouped" bucket for silo-less keywords. Offers each silo's BUYER-INTENT keywords —
     * everything except informational longtails (a keyword already flagged `is_grid_keyword` always shows) —
     * opportunity-ranked (flagged first, then highest opportunity) and capped per silo, so the list is the
     * main transactional terms customers search, not a deep longtail dump. No pre-flagging needed: selecting
     * a keyword into a plan flags it ({@see save()}). Cap is `geo_grid.dropdown_per_silo`.
     *
     * @return array<string, array<string, string>>
     */
    public function keywordOptions(string $siteId): array
    {
        $perSilo = max(1, (int) config('launchpad.geo_grid.dropdown_per_silo', 10));

        // is_grid_keyword=true OR intent is null OR intent isn't informational → drop only unflagged
        // informational (research / blog) keywords; keep transactional/commercial/unclassified buyer terms.
        $buyerOrFlagged = fn ($q) => $q->where(function ($w): void {
            $w->where('is_grid_keyword', true)
                ->orWhereNull('intent')
                ->orWhereRaw('lower(intent) <> ?', ['informational']);
        })
            ->orderByDesc('is_grid_keyword')
            ->orderByDesc('opportunity_score')
            ->orderBy('query');

        $grouped = Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->with(['keywords' => $buyerOrFlagged])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Silo $silo): array => [(string) $silo->name => $silo->keywords->take($perSilo)->pluck('query', 'id')->all()])
            ->filter(fn (array $kws): bool => $kws !== [])
            ->all();

        $ungrouped = $buyerOrFlagged(
            Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereNull('silo_id')
        )->limit($perSilo)->pluck('query', 'id')->all();
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

        // The plan's keywords ARE this GBP's grid keywords — flag them so the Grid column, the CLI coverage
        // scan, and the cost estimate all reflect what the plan scans. Additive: it never un-flags a keyword.
        if ($keywordIds !== []) {
            Keyword::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $location->site_id)
                ->whereIn('id', $keywordIds)
                ->where('is_grid_keyword', false)
                ->update(['is_grid_keyword' => true]);
        }

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
