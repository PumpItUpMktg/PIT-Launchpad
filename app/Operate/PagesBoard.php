<?php

namespace App\Operate;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Guided\GrowDashboard;
use App\Guided\LiveBoards;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The per-family Pages boards (operate relay, part 2): the FULL page lifecycle on one surface per
 * family — Core / Service / Location — composing the two proven read models instead of writing a
 * third: the work section is {@see GrowDashboard::sections()}'s matching lane (everything not yet
 * published, most-actionable-first, morphing primary), and the live section is the matching
 * {@see LiveBoards} board (published cards with tracking). Membership stays state-driven, so a
 * page "moves" between the two sections of ITS OWN board by status alone.
 */
class PagesBoard
{
    public function __construct(
        private readonly GrowDashboard $grow,
        private readonly LiveBoards $live,
    ) {}

    /**
     * @return array{work: list<array<string, mixed>>, live: list<array<string, mixed>>}
     */
    public function core(Site $site): array
    {
        return ['work' => $this->workLane($site, 'core'), 'live' => $this->live->core($site)];
    }

    /**
     * @return array{work: list<array<string, mixed>>, live: list<array<string, mixed>>}
     */
    public function services(Site $site): array
    {
        return ['work' => $this->workLane($site, 'service'), 'live' => $this->live->services($site)];
    }

    /**
     * Locations keeps the live side GROUPED (location card + its towns + city-service pages),
     * exactly like the Live board it supersedes. `$locationId` is the tab being viewed: every location
     * still yields a group so the tab strip is complete, but only that one's cards are built.
     *
     * @return array{work: list<array<string, mixed>>, live: array{groups: list<array<string, mixed>>, orphans: list<array<string, mixed>>, location_options: array<string, string>}}
     */
    public function locations(Site $site, ?string $locationId = null): array
    {
        // The live side is already grouped under its location; the work lane is a flat list, so tag each
        // work card with the brick-and-mortar location it belongs to — a visual link for the operator.
        return [
            'work' => $this->tagBrickMortar($site, $this->workLane($site, 'town')),
            'live' => $this->live->locations($site, $locationId),
            // Selected towns with no page yet — the build queue for this location, one town at a time.
            'eligible' => $this->eligibleTowns($site),
        ];
    }

    /**
     * Decorate each location work-card with the physical location it's tied to (`brick_mortar` label +
     * `brick_mortar_id` grouping key), and flag whether the row IS that brick-and-mortar page itself
     * (`is_brick_mortar`). A town is tied via `parent_location_id`; a location's own landing page via
     * `location_id`. Unassigned → null (the board groups those under an "Unassigned" heading).
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    private function tagBrickMortar(Site $site, array $cards): array
    {
        if ($cards === []) {
            return $cards;
        }

        $ids = array_values(array_filter(array_map(fn (array $c): string => (string) ($c['id'] ?? ''), $cards)));
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereKey($ids)
            ->get(['id', 'location_id', 'parent_location_id'])
            ->keyBy(fn (Content $c): string => (string) $c->id);

        $locationIds = $pages->flatMap(fn (Content $c): array => [$c->location_id, $c->parent_location_id])
            ->filter()->unique()->values()->all();
        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereKey($locationIds)
            ->get()->keyBy(fn (Location $l): string => (string) $l->id);

        foreach ($cards as $i => $card) {
            $page = $pages->get((string) ($card['id'] ?? ''));
            if ($page === null) {
                $cards[$i]['brick_mortar'] = null;
                $cards[$i]['brick_mortar_id'] = null;
                $cards[$i]['is_brick_mortar'] = false;

                continue;
            }
            $homeId = $page->location_id ?? $page->parent_location_id;
            $location = $homeId !== null ? $locations->get((string) $homeId) : null;
            $cards[$i]['brick_mortar'] = $location !== null ? $this->locationLabel($location) : null;
            $cards[$i]['brick_mortar_id'] = $location !== null ? (string) $location->id : null;
            $cards[$i]['is_brick_mortar'] = $page->location_id !== null;
        }

        return $cards;
    }

    /**
     * Towns selected for a page that do not have one yet, per location id.
     *
     * Selecting a town makes it ELIGIBLE; it does not create anything. Until now the board showed only what
     * had been created, so a location with 54 towns selected and 27 built looked finished — the remaining 27
     * were invisible, and the only way to reach them was "generate everything". This is that backlog, named,
     * so the operator builds one when they choose to.
     *
     * A town counts as built when a page carries its GEOID (the anchor join), whatever its status — and
     * ALSO when it is the town a GBP location's hub page is ABOUT, because that hub page already is that
     * town's page. Nothing used to say so: a hub is pinned through `location_id` with a null `geo_id`, so
     * every location's own town sat in its own build queue asking to be built a second time.
     *
     * The hub's town is its own city — `Location::cityState()`, the name the page is titled and targeted
     * on — NOT `home_geo_id`, the municipality its building physically stands in. Those differ more often
     * than they agree: of SPG's fourteen offices, four sit in a municipality their mailing address does
     * not name (a "Doylestown" office standing in Plumstead township, "Trooper" in Lower Providence).
     * The polygon under the building is a real-estate fact; what the page covers is its city. Plumstead
     * still deserves its own page, and gets to keep asking for one.
     *
     * Same-NAME siblings of a covered town are suppressed too. Doylestown borough and Doylestown township
     * are two real municipalities with two GEOIDs, but one name to a searcher — and the public areas grid
     * already collapses them to a single row ({@see ServiceAreaResolver::byCounty()}), so a second page
     * could only compete with the first for the same name. Matching a hub by name rather than GEOID is
     * right here for the same reason: the hub covers "Doylestown", not one of its two polygons.
     *
     * Suppression only ever fires against a town that HAS a page; two unbuilt same-name towns both show.
     *
     * @return array<string, list<array{coverage_area_id: string, name: string, state: string|null, population: int}>>
     */
    private function eligibleTowns(Site $site): array
    {
        $built = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('geo_id')
            ->pluck('geo_id')
            ->flip();

        // The town each hub page is about — the location's own city, which is the name that page is
        // titled and targeted on.
        $coveredNames = [];
        foreach (Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get() as $location) {
            ['city' => $city, 'state' => $state] = $location->cityState();
            $city = trim($city) !== '' ? trim($city) : trim((string) $location->name);
            if ($city !== '') {
                // An ungeocoded location has a name but no state ({@see Location::cityState()} returns
                // empty strings); its key then carries no state and matches the name alone. Within one
                // site's own service area that is the intended reach, not a cross-state accident.
                $coveredNames[$this->nameKey($city, $state)] = true;
            }
        }

        // …and the name of every town that already has a page, whether or not it is still selected.
        $builtTowns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('geo_id', $built->keys()->all())
            ->get();
        foreach ($builtTowns as $area) {
            $coveredNames[$this->nameKey((string) $area->name, (string) $area->state)] = true;
        }

        $out = [];
        $selected = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_selected', true)
            ->orderByDesc('population')
            ->get();

        foreach ($selected as $town) {
            if ($this->alreadyCovered($town, $built->all(), $coveredNames)) {
                continue;
            }
            foreach (is_array($town->source_location_ids) ? $town->source_location_ids : [] as $locationId) {
                $out[(string) $locationId][] = [
                    'coverage_area_id' => (string) $town->id,
                    'name' => (string) $town->name,
                    'state' => $town->state,
                    'population' => (int) ($town->population ?? 0),
                ];
            }
        }

        return $out;
    }

    /**
     * Whether a selected town already has a page — by its own GEOID, or because its NAME is covered
     * (a hub page about that city, or a same-name municipality that has been built). The stateless key
     * is how an ungeocoded location, which has a name but no state, still claims its own town.
     *
     * @param  array<string, mixed>  $builtGeoIds
     * @param  array<string, true>  $coveredNames
     */
    private function alreadyCovered(CoverageArea $town, array $builtGeoIds, array $coveredNames): bool
    {
        $geoId = trim((string) $town->geo_id);
        $name = (string) $town->name;

        return ($geoId !== '' && isset($builtGeoIds[$geoId]))
            || isset($coveredNames[$this->nameKey($name, (string) $town->state)])
            || isset($coveredNames[$this->nameKey($name, '')]);
    }

    /** A town's identity for same-name matching: name and state, case- and space-insensitive. */
    private function nameKey(string $name, string $state): string
    {
        return mb_strtolower(trim($name)).'|'.mb_strtolower(trim($state));
    }

    private function locationLabel(Location $location): string
    {
        ['city' => $city, 'state' => $state] = $location->cityState();
        $city = trim($city) !== '' ? trim($city) : trim((string) $location->name);
        $state = trim($state);

        return $city !== '' && $state !== '' ? "{$city}, {$state}" : ($city !== '' ? $city : 'Location');
    }

    /**
     * The location tabs in display order — the one list both the page and the view read, so the tab that
     * is shown is the tab whose cards were built.
     *
     * @return list<array{id: string, label: string}>
     */
    public function locationTabs(Site $site): array
    {
        return $this->live->locationTabs($site);
    }

    /** The site-level data-source chips for the live cards. */
    public function sources(Site $site): array
    {
        return $this->live->sources($site);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workLane(Site $site, string $key): array
    {
        foreach ($this->grow->sections($site) as $section) {
            if ($section['key'] === $key) {
                return $section['pages'];
            }
        }

        return [];
    }
}
