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
     * A town counts as built when a page carries its GEOID (the anchor join), whatever its status.
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

        $out = [];
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_selected', true)
            ->orderByDesc('population')
            ->get();

        foreach ($towns as $town) {
            if (trim($town->geo_id) !== '' && $built->has($town->geo_id)) {
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

    private function locationLabel(Location $location): string
    {
        ['city' => $city, 'state' => $state] = $location->cityState();
        $city = trim($city) !== '' ? trim($city) : trim((string) $location->name);
        $state = trim($state);

        return $city !== '' && $state !== '' ? "{$city}, {$state}" : ($city !== '' ? $city : 'Location');
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
