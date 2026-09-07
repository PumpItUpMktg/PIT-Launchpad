<?php

namespace App\Operator\Coverage;

use App\Locations\PhysicalLocationCities;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The standing, READ-ONLY watch for the landing/town duplicate class — so it can't silently reaccumulate
 * after the guard ({@see PhysicalLocationCities} via LocalRelevance) and the one-time cleanup
 * ({@see LiveDuplicateResolver}). It looks at both ends of the pipeline:
 *
 *  - OUTPUT — live duplicate location pages already published (landing↔town + town↔town), via the
 *    resolver's own plan, so the detector and the fixer share one definition and can't drift.
 *  - INPUT — a CoverageArea flagged `page_selected` that IS a physical location's own city. The guard now
 *    stops the auto-selector from setting this, and the manifest drops it even if set, so it no longer
 *    materializes a page — but a lingering flag (a pre-guard selection, or a manual operator toggle) is
 *    the *source* smell worth surfacing so the selection can be cleaned before anything ever regresses.
 *
 * Report only — it never writes. If it ever finds something, that's the signal a guard regressed or an
 * operator mis-selected, and a human decides what to do (run the resolver, or deselect the town).
 */
final class LocationDuplicateReconciler
{
    public function __construct(
        private readonly LiveDuplicateResolver $resolver,
        private readonly PhysicalLocationCities $cities,
    ) {}

    /**
     * @return array{
     *   live_duplicates: list<array<string, mixed>>,
     *   selected_self_cities: list<array{coverage_area_id: string, name: string, state: ?string}>
     * }
     */
    public function report(Site $site): array
    {
        $set = $this->cities->forSite($site);

        $selfCities = [];
        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_selected', true)
            ->get();

        foreach ($areas as $area) {
            $name = (string) $area->getAttribute('name');
            if ($this->cities->matches($name, $area->state, $set)) {
                $selfCities[] = ['coverage_area_id' => (string) $area->id, 'name' => $name, 'state' => $area->state];
            }
        }

        return [
            'live_duplicates' => $this->resolver->plan($site),
            'selected_self_cities' => $selfCities,
        ];
    }
}
