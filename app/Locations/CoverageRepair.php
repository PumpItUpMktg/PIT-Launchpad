<?php

namespace App\Locations;

use App\Console\Commands\CleanCoverageNamesCommand;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Repairs a tenant's service-area coverage DATA — the source behind the homepage "Areas we serve" list
 * ({@see CoverageArea}) and the location-page "towns we cover" prose ({@see Location::$served_towns}).
 * Three defects, in order (the order matters — cleaning names first is what exposes the duplicates):
 *
 *  1. **Numbered-list artifact** ("1, Abingdon" → "Abingdon") via {@see CoverageName} — the same repair
 *     {@see CleanCoverageNamesCommand} does, folded in so one pass leaves clean data.
 *  2. **Out-of-territory towns** — a row whose `state` isn't one of the site's own states (e.g. Maryland
 *     towns on a New Jersey business). The county FIPS behind each removed row is also pruned from every
 *     Location's `county_geoids` so the next census recompute ({@see CoverageWriter}) can't re-add them.
 *  3. **Intra-county duplicates** — two rows with the same cleaned name in the same county (a `place` and a
 *     `county_subdivision` both "Bethlehem"); the best row is kept, the rest removed.
 *
 * A site's territory is the set of states from its physical Locations + its `corporate_state`. If none is
 * known the out-of-territory pass is SKIPPED (never guess a boundary and delete real coverage).
 *
 * `repair($site, apply: false)` computes the plan and touches nothing; `apply: true` runs it in one
 * transaction. After an apply, re-materialize / repush the homepage + location pages so they pick up the
 * cleaned set.
 */
class CoverageRepair
{
    /**
     * @return array{
     *   territory: list<string>,
     *   coverage: array{prefix_cleaned: list<string>, out_of_state: list<string>, deduped: list<string>},
     *   served: array{prefix_cleaned: int, out_of_state: int, deduped: int, locations_touched: int},
     *   counties_pruned: list<string>,
     * }
     */
    public function repair(Site $site, bool $apply): array
    {
        $territory = $this->territory($site);

        $coverage = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->get();

        // 1. Prefix clean (in memory) — names drive both later passes.
        $prefixCleaned = [];
        foreach ($coverage as $area) {
            $raw = (string) $area->getAttribute('name');
            $clean = CoverageName::clean($raw);
            if ($clean !== trim($raw)) {
                $prefixCleaned[] = $clean;
                $area->name = $clean; // mutator re-cleans harmlessly; sets the attribute for later grouping
            }
        }

        // 2. Out-of-territory — a row with a known state outside the site's states.
        $outOfState = $coverage->filter(function (CoverageArea $a) use ($territory): bool {
            $state = strtoupper(trim((string) $a->state));

            return $territory !== [] && $state !== '' && ! in_array($state, $territory, true);
        })->values();

        $remaining = $coverage->reject(fn (CoverageArea $a): bool => $outOfState->contains('id', $a->id));

        // The county FIPS (first 5 of the GEOID) behind every removed row — pruned from county_geoids so a
        // census recompute can't re-add the out-of-territory towns.
        $countiesPruned = collect($outOfState->all())
            ->map(fn (CoverageArea $a): string => substr((string) $a->geo_id, 0, 5))
            ->filter(fn (string $f): bool => strlen($f) === 5)
            ->unique()->values()->all();

        // 3. Intra-county duplicates among the REMAINING rows — same county FIPS + same cleaned name.
        $deduped = [];
        $dupeIds = [];
        $remaining->groupBy(fn (CoverageArea $a): string => substr((string) $a->geo_id, 0, 5).'|'.mb_strtolower(trim((string) $a->getAttribute('name'))))
            ->each(function ($group) use (&$deduped, &$dupeIds): void {
                if ($group->count() < 2) {
                    return;
                }
                $sorted = $group->sort($this->preferability(...))->values();
                foreach ($sorted->slice(1) as $loser) {
                    $dupeIds[] = $loser->id;
                    $deduped[] = (string) $loser->getAttribute('name');
                }
            });

        $served = $this->planServedTowns($site, $territory);

        if ($apply) {
            DB::transaction(function () use ($coverage, $prefixCleaned, $outOfState, $dupeIds, $countiesPruned, $served, $site): void {
                if ($prefixCleaned !== []) {
                    foreach ($coverage as $area) {
                        if ($area->isDirty('name')) {
                            $area->save();
                        }
                    }
                }
                CoverageArea::withoutGlobalScope(SiteScope::class)
                    ->whereIn('id', $outOfState->pluck('id')->all())->delete();
                CoverageArea::withoutGlobalScope(SiteScope::class)
                    ->whereIn('id', $dupeIds)->delete();

                $this->pruneCountyGeoids($site, $countiesPruned);
                $this->applyServedTowns($site, $served['plans']);
            });
        }

        return [
            'territory' => $territory,
            'coverage' => [
                'prefix_cleaned' => $prefixCleaned,
                'out_of_state' => $outOfState->map(fn (CoverageArea $a): string => $a->getAttribute('name').' ('.$a->state.')')->all(),
                'deduped' => $deduped,
            ],
            'served' => [
                'prefix_cleaned' => $served['prefix_cleaned'],
                'out_of_state' => $served['out_of_state'],
                'deduped' => $served['deduped'],
                'locations_touched' => count($served['plans']),
            ],
            'counties_pruned' => $countiesPruned,
        ];
    }

    /** The site's own states: every physical Location's state + the corporate state, upper-cased. */
    private function territory(Site $site): array
    {
        $locationStates = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->get()
            ->map(fn (Location $l): string => strtoupper(trim((string) $l->cityState()['state'])))
            ->all();

        return collect($locationStates)
            ->push(strtoupper(trim((string) $site->corporate_state)))
            ->filter(fn (string $s): bool => $s !== '')
            ->unique()->values()->all();
    }

    /**
     * Dedupe preference: keep a page-selected row, then the higher population, then a `place` over a
     * `county_subdivision`, then the lowest GEOID for a stable tiebreak. Returns <0 when $a should win.
     */
    private function preferability(CoverageArea $a, CoverageArea $b): int
    {
        return [(int) ! $a->page_selected, -(int) ($a->population ?? 0), $a->type->value === 'place' ? 0 : 1, (string) $a->geo_id]
            <=> [(int) ! $b->page_selected, -(int) ($b->population ?? 0), $b->type->value === 'place' ? 0 : 1, (string) $b->geo_id];
    }

    /**
     * Plan the served_towns sweep for each Location (clean names, drop out-of-territory, dedupe by
     * name|state). Returns the counts and the per-location cleaned arrays to persist.
     *
     * @return array{prefix_cleaned: int, out_of_state: int, deduped: int, plans: array<string, list<array<string, mixed>>>}
     */
    private function planServedTowns(Site $site, array $territory): array
    {
        $prefixCleaned = 0;
        $outOfState = 0;
        $deduped = 0;
        $plans = [];

        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->get();

        foreach ($locations as $location) {
            $towns = is_array($location->served_towns) ? $location->served_towns : [];
            if ($towns === []) {
                continue;
            }

            $out = [];
            $seen = [];
            $changed = false;
            foreach ($towns as $row) {
                $name = CoverageName::clean((string) ($row['name'] ?? ''));
                $state = strtoupper(trim((string) ($row['state'] ?? '')));
                if ($name === '') {
                    $changed = true; // dropping an empty/garbage row

                    continue;
                }
                if ($name !== trim((string) ($row['name'] ?? ''))) {
                    $prefixCleaned++;
                    $changed = true;
                }
                if ($territory !== [] && $state !== '' && ! in_array($state, $territory, true)) {
                    $outOfState++;
                    $changed = true;

                    continue;
                }
                $key = mb_strtolower($name).'|'.$state;
                if (isset($seen[$key])) {
                    $deduped++;
                    $changed = true;

                    continue;
                }
                $seen[$key] = true;
                $row['name'] = $name;
                $out[] = $row;
            }

            if ($changed) {
                $plans[$location->id] = $out;
            }
        }

        return ['prefix_cleaned' => $prefixCleaned, 'out_of_state' => $outOfState, 'deduped' => $deduped, 'plans' => $plans];
    }

    /** @param  array<string, list<array<string, mixed>>>  $plans */
    private function applyServedTowns(Site $site, array $plans): void
    {
        foreach ($plans as $locationId => $towns) {
            Location::withoutGlobalScope(SiteScope::class)
                ->where('id', $locationId)
                ->update(['served_towns' => $towns]);
        }
    }

    /** @param  list<string>  $counties  5-digit county FIPS to drop from every Location's territory. */
    private function pruneCountyGeoids(Site $site, array $counties): void
    {
        if ($counties === []) {
            return;
        }

        Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->get()
            ->each(function (Location $location) use ($counties): void {
                $geoids = is_array($location->county_geoids) ? $location->county_geoids : [];
                $kept = array_values(array_filter($geoids, fn ($g): bool => ! in_array((string) $g, $counties, true)));
                $home = (string) $location->home_county_geoid;
                $homeOut = in_array($home, $counties, true);
                if ($kept !== $geoids || $homeOut) {
                    $location->forceFill([
                        'county_geoids' => $kept,
                        'home_county_geoid' => $homeOut ? null : $location->home_county_geoid,
                    ])->save();
                }
            });
    }
}
