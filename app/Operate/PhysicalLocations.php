<?php

namespace App\Operate;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Guided\LiveMetrics;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * The Operate physical-locations directory read model: one card per base Location with the
 * territory it serves, built ENTIRELY from persisted rows (no gazetteer/network on render).
 *
 * Two guarantees the surface makes visible:
 *  - OVERLAP: a town reached by two locations (source_location_ids > 1) is flagged on every
 *    card involved, naming the other location(s) — the goal state is zero overlap.
 *  - THE HOME-COUNTY SOFT RULE: a location should serve the county it sits in and that county's
 *    towns. Advisory only — a good starting point, never a wall. Checkable offline because a
 *    county-subdivision GEOID's first five digits ARE its county GEOID.
 */
class PhysicalLocations
{
    /** Census tiers folded into the three display bands the town panel shows. */
    private const BANDS = ['larger' => ['major', 'large'], 'mid' => ['medium'], 'smaller' => ['small', 'ungrouped']];

    public function __construct(
        private readonly LiveMetrics $metrics,
        private readonly QueueHealth $queueHealth,
    ) {}

    /**
     * @return array{summary: array<string, int>, cards: list<array<string, mixed>>}
     */
    public function build(Site $site): array
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('name')
            ->get();

        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();

        $names = $locations->pluck('name', 'id');

        // The landing/hub page that IS each base location (page_type=Location, pinned location_id),
        // keyed by location — so each card can surface its page's build state and lifecycle actions.
        $landings = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('location_id')
            ->get()
            ->keyBy(fn (Content $c): string => (string) $c->location_id);

        // TOWN pages (nested under a location, not the hub) keyed "{parent_location_id}|{townKey}" so
        // each card's town panel can show each town's page status, and the pipeline monitor can total them.
        $townPages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id')
            ->whereNull('primary_service_id')
            ->whereNotNull('parent_location_id')
            ->get();
        $townPagesByKey = $townPages->keyBy(fn (Content $c): string => $c->parent_location_id.'|'.$this->townKey((string) $c->title));

        $cards = $locations
            ->map(fn (Location $location) => $this->card($location, $areas, $names, $landings->get((string) $location->id), $townPagesByKey))
            ->values()
            ->all();

        return [
            'summary' => [
                'locations' => $locations->count(),
                'towns_covered' => $areas->count(),
                'towns_selected' => $areas->where('page_selected', true)->count(),
                'overlaps' => $areas->filter(fn (CoverageArea $a) => count($this->sources($a)) > 1)->count(),
            ],
            'pipeline' => $this->townPipeline($areas, $townPages),
            'cards' => $cards,
        ];
    }

    /**
     * The town-page pipeline progress for the monitor at the top of the page — how the selected town
     * pages are moving through generate → publish, plus the live queue backlog (so a stalled worker is
     * obvious). Counts are over the whole site's town pages.
     *
     * @param  Collection<int, CoverageArea>  $areas
     * @param  Collection<int, Content>  $townPages
     * @return array{selected: int, published: int, generating: int, drafted: int, not_built: int, failed: int, queue_pending: int, stalled: bool}
     */
    private function townPipeline(Collection $areas, Collection $townPages): array
    {
        $selected = $areas->where('page_selected', true)->count();
        $published = $townPages->filter(fn (Content $c) => $c->status === ContentStatus::Published)->count();
        $generating = $townPages->filter(fn (Content $c) => $c->generationState() === 'generating')->count();
        $failed = $townPages->filter(fn (Content $c) => $c->generationState() === 'failed')->count();
        $drafted = $townPages->filter(fn (Content $c) => $c->status !== ContentStatus::Published && $c->generationState() === 'drafted')->count();
        $queue = $this->queueHealth->snapshot();

        return [
            'selected' => $selected,
            'published' => $published,
            'generating' => $generating,
            'drafted' => $drafted,
            // Selected towns with no live/drafted page yet (materialized-candidate or not built).
            'not_built' => max(0, $selected - $published - $generating - $drafted - $failed),
            'failed' => $failed,
            'queue_pending' => $queue['pending'],
            'stalled' => $queue['stalled'],
        ];
    }

    /** Normalize a town name for matching a coverage row to its page (drop a trailing ", ST", lower). */
    private function townKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }

    /**
     * The location's towns as three display bands (Larger / Mid-size / Smaller), population-ordered
     * within each — each town carrying its page STATUS + content id + page_selected, so the card's bulk
     * generate/publish panel is status-aware. Also returns the SELECTABLE count: page-selected towns not
     * yet published or generating (the size of the next "Generate + publish selected" batch).
     *
     * @param  Collection<int, CoverageArea>  $own
     * @param  Collection<string, Content>  $townPagesByKey
     * @return array{0: array<string, list<array<string, mixed>>>, 1: int}
     */
    private function townBands(Location $location, Collection $own, Collection $townPagesByKey): array
    {
        $sorted = $own->sort(function (CoverageArea $a, CoverageArea $b): int {
            $pop = ($b->population ?? -1) <=> ($a->population ?? -1);

            return $pop !== 0 ? $pop : strcmp((string) $a->name, (string) $b->name);
        })->values();

        $bands = ['larger' => [], 'mid' => [], 'smaller' => []];
        $selectable = 0;
        foreach ($sorted as $area) {
            $page = $townPagesByKey->get($location->id.'|'.$this->townKey((string) $area->name));
            $status = $this->townStatus($page);
            if ((bool) $area->page_selected && ! in_array($status, ['published', 'generating'], true)) {
                $selectable++;
            }
            $bands[$this->bandFor((string) ($area->size_tier ?? ''))][] = [
                'coverage_area_id' => (string) $area->id,
                'name' => trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', (string) $area->name)),
                'population' => $area->population,
                'page_selected' => (bool) $area->page_selected,
                'status' => $status,
                'content_id' => $page?->id,
            ];
        }

        return [$bands, $selectable];
    }

    private function bandFor(string $tier): string
    {
        foreach (self::BANDS as $band => $tiers) {
            if (in_array($tier !== '' ? $tier : 'ungrouped', $tiers, true)) {
                return $band;
            }
        }

        return 'smaller';
    }

    private function townStatus(?Content $page): string
    {
        if ($page === null) {
            return 'none';
        }
        if ($page->status === ContentStatus::Published) {
            return 'published';
        }

        return match ($page->generationState()) {
            'generating' => 'generating',
            'failed' => 'failed',
            'drafted' => 'drafted',
            default => 'built',
        };
    }

    /**
     * @param  Collection<int, CoverageArea>  $areas
     * @param  Collection<int|string, string>  $names
     * @param  Collection<string, Content>  $townPagesByKey
     * @return array<string, mixed>
     */
    private function card(Location $location, Collection $areas, Collection $names, ?Content $landing, Collection $townPagesByKey): array
    {
        $own = $areas->filter(fn (CoverageArea $a) => in_array($location->id, $this->sources($a), true))->values();
        [$townBands, $selectable] = $this->townBands($location, $own, $townPagesByKey);

        // Overlap: every town this location shares with another, naming the other location(s).
        $overlaps = $own
            ->filter(fn (CoverageArea $a) => count($this->sources($a)) > 1)
            ->map(fn (CoverageArea $a) => [
                'town' => trim((string) $a->name).($a->state ? ", {$a->state}" : ''),
                'with' => collect($this->sources($a))
                    ->reject(fn ($id) => (string) $id === (string) $location->id)
                    ->map(fn ($id) => (string) ($names[$id] ?? 'unknown location'))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        $located = $location->lat !== null && $location->lng !== null;
        $home = trim((string) $location->home_county_geoid);
        $countiesServed = collect(is_array($location->county_geoids) ? $location->county_geoids : [])
            ->map(fn ($g) => trim((string) $g))->filter()->values();

        // The soft rule, from persisted data only. County-subdivision GEOIDs prefix with their
        // county GEOID; 'place' GEOIDs don't carry a county, so the town check is best-effort.
        $servesHomeCounty = $home !== '' && $countiesServed->contains($home);
        $homeCountyTowns = $home === '' ? null : $own
            ->filter(fn (CoverageArea $a) => str_starts_with((string) $a->geo_id, $home))
            ->count();

        $advisories = [];
        if (! $located) {
            $advisories[] = 'Not located yet — locate it in Setup → Locations (or Settings → Service area) so its home county can resolve.';
        } elseif ($home === '') {
            $advisories[] = 'Home county not resolved yet — recompute the service area.';
        } elseif (! $servesHomeCounty) {
            $advisories[] = 'Doesn\'t serve the county it sits in — a good starting point is to add its home county (soft rule, your call).';
        } elseif ($homeCountyTowns === 0) {
            $advisories[] = 'Home county is ticked but no towns are computed yet — run "Update service area".';
        }

        return [
            'id' => (string) $location->id,
            'name' => trim((string) $location->name),
            'address' => $location->address,
            'phone' => $location->phone,
            'storefront' => (bool) $location->is_storefront,
            'located' => $located,
            'gbp_url' => $location->gbp_url,
            'gbp_linked' => trim((string) $location->place_id) !== '' || trim((string) $location->gbp_url) !== '',
            'serves_home_county' => $servesHomeCounty,
            'home_resolved' => $home !== '',
            'counties_served' => $countiesServed->count(),
            'home_county_towns' => $homeCountyTowns,
            'towns_covered' => $own->count(),
            'towns_selected' => $own->where('page_selected', true)->count(),
            'town_bands' => $townBands,
            'selectable' => $selectable, // selected towns not yet published/generating — the batch size
            'overlaps' => $overlaps,
            'advisories' => $advisories,
            'page' => $this->pageState($landing),
        ];
    }

    /**
     * The landing/hub page's build state for the card's lifecycle controls — reusing Content's own
     * single-source-of-truth state machine so the badge + button gating match every other surface.
     *
     * @return array<string, mixed>
     */
    private function pageState(?Content $landing): array
    {
        if ($landing === null) {
            return [
                'content_id' => null,
                'label' => 'Not created yet',
                'state' => 'none',
                'drafted' => false,
                'published' => false,
                'metrics' => null,
                'can_generate' => true,   // "Generate" find-or-creates the landing page, then drafts it
                'can_review' => false,    // no page yet — nothing to open in the proof editor
            ];
        }

        $state = $landing->generationState();          // awaiting | generating | failed | drafted
        $published = $landing->status === ContentStatus::Published;
        $drafted = $landing->hasDraft();

        return [
            'content_id' => (string) $landing->id,
            'label' => $landing->buildStateLabel(),
            'state' => $state,
            'drafted' => $drafted,
            'published' => $published,
            // The same Position / GSC / GA4 tracking block every other page card shows (pending reasons
            // when a source or datum is absent — e.g. "No target keyword — brand page" for a hub).
            'metrics' => $this->metrics->for($landing),
            'can_generate' => $state !== 'generating',
            // Review opens the proof editor for any drafted page (the same target as the core board).
            'can_review' => $drafted,
        ];
    }

    /**
     * @return list<string>
     */
    private function sources(CoverageArea $area): array
    {
        return is_array($area->source_location_ids)
            ? array_map('strval', $area->source_location_ids)
            : [];
    }
}
