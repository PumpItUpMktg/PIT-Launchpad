<?php

namespace App\Locations\Diagnostics;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Scopes\ActiveLocationScope;
use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Publishing\Links\InternalLinkGraph;
use App\Support\TownName;
use Illuminate\Support\Collection;

/**
 * Read-only location-integrity diagnostics (location-integrity relay, items 2-4). Turns three defect
 * classes into actionable PLANS — each mis-parented page carries the correct parent and the cost of the
 * fix (current URL, proposed URL, index status, inbound-link count) so the redirect + resubmission a
 * correction needs is visible before anyone decides.
 *
 * LIVE-ONLY throughout: `Content` soft-deletes are excluded by the default scope; `Location` rows folded
 * by NAP-reconcile are excluded by {@see ActiveLocationScope} (`merged_into_id`). Nothing here writes —
 * the only mutation in the whole relay is the guarded Roslyn removal, which lives in its command behind
 * `--execute`, not here.
 *
 * A town page's TRUE county is a census fact: the `CoverageArea` for its (name, state) carries the
 * `geo_id` whose first 5 digits are the county — independent of which location currently parents it, which
 * is what makes a drift check meaningful (deriving the county from the current parent would be circular).
 */
class LocationAudit
{
    /** @var array<string, InternalLinkGraph> per-site link graph, built once */
    private array $graphs = [];

    /** @var array<string, string> location id → display label, resolved once */
    private array $labels = [];

    /**
     * Item 2 — Spring City shape: locations serving counties that don't include their OWN (home) county,
     * with the published town pages that sit under them and where each would move.
     *
     * @return list<array{
     *   site_id: string, location_id: string, location: string, home_county_geoid: ?string,
     *   served_county_geoids: list<string>, pages: list<array<string, mixed>>
     * }>
     */
    public function countyMismatches(): array
    {
        $out = [];
        foreach ($this->liveLocations() as $location) {
            $served = $this->countyGeoids($location);
            $home = $location->home_county_geoid !== null ? (string) $location->home_county_geoid : null;

            // The defect: a home county that the location does not actually serve (Spring City: home
            // 42029 Chester, served 42077/42095 Lehigh/Northampton).
            if ($home === null || $home === '' || in_array($home, $served, true)) {
                continue;
            }

            $pages = [];
            foreach ($this->livePagesUnder($location) as $page) {
                $pages[] = $this->planRow($page, $location);
            }

            $out[] = [
                'site_id' => (string) $location->site_id,
                'location_id' => (string) $location->id,
                'location' => (string) $location->name,
                'home_county_geoid' => $home,
                'served_county_geoids' => $served,
                'pages' => $pages,
            ];
        }

        return $out;
    }

    /**
     * Item 4 — every live town page whose parent location does NOT serve the town's county, across all
     * tenants, each with the correct parent and the cost of moving it.
     *
     * @return list<array<string, mixed>>
     */
    public function townAssignmentDrift(): array
    {
        $out = [];
        foreach ($this->liveTownPages() as $page) {
            $parent = $page->parent_location_id !== null ? $this->location((string) $page->parent_location_id) : null;
            if ($parent === null) {
                continue; // a dangling parent is a different defect (handled by the tenant-lock/orphan work)
            }

            $county = $this->townCountyGeoid($page);
            if ($county === null) {
                continue; // can't resolve the town's census county (no matching CoverageArea) — not a drift claim
            }

            if (in_array($county, $this->countyGeoids($parent), true)) {
                continue; // parent serves the county → correctly assigned
            }

            $out[] = $this->planRow($page, $parent);
        }

        return $out;
    }

    /**
     * Item 4 companion — town names that exist in more than one state (Montgomery, Newark, Washington,
     * Franklin, Springfield, Union all appear in NJ and PA, several as county names). The in-state
     * collisions the cross-state Trooper/Montgomery case wouldn't surface on its own.
     *
     * @return list<array{name: string, states: list<string>, county_geoids: list<string>}>
     */
    public function sameNameAcrossStates(): array
    {
        $byName = [];
        foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->get(['name', 'state', 'geo_id']) as $area) {
            $name = trim((string) $area->name);
            $state = strtoupper(trim((string) $area->state));
            if ($name === '' || $state === '') {
                continue;
            }
            $key = TownName::key($name);
            $byName[$key]['name'] ??= $name;
            $byName[$key]['states'][$state] = true;
            if (strlen((string) $area->geo_id) >= 5) {
                $byName[$key]['counties'][substr((string) $area->geo_id, 0, 5)] = true;
            }
        }

        $out = [];
        foreach ($byName as $row) {
            if (count($row['states']) < 2) {
                continue;
            }
            $out[] = [
                'name' => (string) $row['name'],
                'states' => array_keys($row['states']),
                'county_geoids' => array_keys($row['counties'] ?? []),
            ];
        }
        usort($out, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $out;
    }

    /**
     * Item 3 — partial-insert duplicate locations: a second row at the same address carrying no county
     * data and not a storefront, with zero pages of its own (the abandoned half of a two-step insert).
     *
     * @return list<array{site_id: string, duplicate_id: string, duplicate: string, address: string, survivor_id: ?string, survivor: ?string, survivor_site_id: ?string, cross_tenant: bool, reason: string}>
     */
    public function duplicateLocations(): array
    {
        $out = [];
        // Grouping drops SiteScope, so a same-address pair spanning two tenants is caught too — that's the
        // wrong-tenant-import shape (a GBP import run against the wrong site before the lock existed), which
        // is a DIFFERENT cause from an intra-site duplicate and is labelled cross_tenant below.
        foreach ($this->liveLocations()->groupBy(fn (Location $l): string => $this->addressKey($l)) as $addressKey => $group) {
            if ($addressKey === '' || $group->count() < 2) {
                continue;
            }

            foreach ($group as $candidate) {
                if (! $this->looksLikePartialInsert($candidate)) {
                    continue;
                }
                // The survivor: another row at this address that is NOT a partial insert (has county data
                // or pages) — the real record this stub should have merged into (or belongs on).
                $survivor = $group->first(fn (Location $l): bool => $l->id !== $candidate->id && ! $this->looksLikePartialInsert($l));
                $crossTenant = $survivor !== null && (string) $survivor->site_id !== (string) $candidate->site_id;

                $out[] = [
                    'site_id' => (string) $candidate->site_id,
                    'duplicate_id' => (string) $candidate->id,
                    'duplicate' => (string) $candidate->name,
                    'address' => (string) $candidate->address,
                    'survivor_id' => $survivor !== null ? (string) $survivor->id : null,
                    'survivor' => $survivor !== null ? (string) $survivor->name : null,
                    'survivor_site_id' => $survivor !== null ? (string) $survivor->site_id : null,
                    'cross_tenant' => $crossTenant,
                    'reason' => $crossTenant
                        ? 'same address on a DIFFERENT tenant — a location created on the wrong site (a GBP import run against the wrong tenant before the lock existed), not an intra-site duplicate'
                        : 'same address, no county data, not a storefront, zero pages — an abandoned two-step insert',
                ];
            }
        }

        return $out;
    }

    /** Remove one partial-insert duplicate by id (the ONLY write in the relay; the command gates it behind --execute). */
    public function removeDuplicate(string $locationId): bool
    {
        $location = $this->location($locationId);
        if ($location === null || ! $this->looksLikePartialInsert($location)) {
            return false; // never delete a row that carries data or pages
        }

        return (bool) $location->delete();
    }

    // ── plan row (the cost of a correction) ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function planRow(Content $page, Location $currentParent): array
    {
        $county = $this->townCountyGeoid($page);
        // Does the CURRENT parent already serve the town's county? If so the page is correctly parented —
        // don't search for an alternative (which excludes the current parent and would falsely report
        // "no location serves this county"). Only look for a different owner when the current one is wrong.
        $currentServes = $county !== null && in_array($county, $this->countyGeoids($currentParent), true);
        $correct = ($county !== null && ! $currentServes)
            ? $this->locationServingCounty((string) $page->site_id, $county, (string) $currentParent->id)
            : null;

        $parenting = match (true) {
            $county === null => 'county_unknown',   // no CoverageArea match → no confident claim
            $currentServes => 'correct',            // current parent serves the county → already correctly parented
            $correct !== null => 'move',            // another location serves it → re-parent (a real move)
            default => 'no_server',                 // county resolved but no live location serves it
        };

        return [
            'content_id' => (string) $page->id,
            'site_id' => (string) $page->site_id,
            'town' => (string) $page->title,
            'town_county_geoid' => $county,
            'parenting' => $parenting,
            'current_parent' => $this->locationLabel($currentParent),
            'current_parent_counties' => $this->countyGeoids($currentParent),
            'correct_parent' => $correct !== null ? $this->locationLabel($correct) : null,
            'correct_parent_id' => $correct !== null ? (string) $correct->id : null,
            'current_url' => $this->url($page->site_id, (string) $page->slug),
            'proposed_url' => $parenting === 'move' ? $this->proposedUrl($page, $correct) : null,
            'indexed' => $this->indexStatus($page),
            'inbound_links' => $this->inboundCount($page),
        ];
    }

    /**
     * The label the URL uses for a location — its hub landing title ("{City}, {ST}"), which is distinctive,
     * NOT `Location.name` (the brand name, shared across a tenant's locations, so several rows would read
     * "Sump Pump Gurus"). Falls back to the geocoded city/state, then the bare name. Cached per location.
     */
    private function locationLabel(Location $location): string
    {
        $id = (string) $location->id;
        if (isset($this->labels[$id])) {
            return $this->labels[$id];
        }

        $hubTitle = trim((string) Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('location_id', $location->id)
            ->whereNotNull('title')
            ->value('title'));

        if ($hubTitle === '') {
            $cs = $location->cityState();
            $hubTitle = trim(trim((string) $cs['city']).', '.trim((string) $cs['state']), ', ');
        }

        return $this->labels[$id] = ($hubTitle !== '' ? $hubTitle : (string) $location->name);
    }

    /** The town's census county GEOID: match its (name, state) — parsed from the "{City}, {ST}" title — to a CoverageArea. */
    private function townCountyGeoid(Content $page): ?string
    {
        $title = trim((string) $page->title);
        $state = null;
        $name = $title;
        if (preg_match('/^(.*),\s*([A-Za-z]{2})$/', $title, $m) === 1) {
            $name = trim($m[1]);
            $state = strtoupper($m[2]);
        }

        $q = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $page->site_id)
            ->get(['name', 'state', 'geo_id'])
            ->filter(fn (CoverageArea $a): bool => TownName::key((string) $a->name) === TownName::key($name)
                && ($state === null || strtoupper(trim((string) $a->state)) === $state)
                && strlen((string) $a->geo_id) >= 5);

        $geoIds = $q->map(fn (CoverageArea $a): string => substr((string) $a->geo_id, 0, 5))->unique()->values();

        return $geoIds->count() === 1 ? (string) $geoIds->first() : null; // ambiguous (or none) → no confident claim
    }

    /** A live location on the site whose served counties include $countyGeoid, other than $excludeId. */
    private function locationServingCounty(string $siteId, string $countyGeoid, string $excludeId): ?Location
    {
        return $this->liveLocations()
            ->first(fn (Location $l): bool => (string) $l->site_id === $siteId
                && (string) $l->id !== $excludeId
                && in_array($countyGeoid, $this->countyGeoids($l), true));
    }

    /** Proposed URL under the correct parent's hub: {home}/{hubSlug}/{townSegment} (the LocationNesting shape). */
    private function proposedUrl(Content $page, Location $correctParent): ?string
    {
        $hubSlug = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $correctParent->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('location_id', $correctParent->id)
            ->whereNotNull('slug')
            ->value('slug');
        $hubSlug = trim((string) $hubSlug, '/');
        if ($hubSlug === '') {
            return null; // the correct parent has no hub landing yet — a re-parent has nowhere to nest
        }

        $current = trim((string) $page->slug, '/');
        $segment = str_contains($current, '/') ? substr($current, strrpos($current, '/') + 1) : $current;

        return $this->url($page->site_id, $hubSlug.'/'.$segment);
    }

    /** @return array{coverage_state: ?string, indexed: bool} */
    private function indexStatus(Content $page): array
    {
        $state = PageIndexState::withoutGlobalScope(SiteScope::class)->where('content_id', $page->id)->first();

        return [
            'coverage_state' => $state?->coverage_state,
            'indexed' => $state?->isIndexed() ?? false,
        ];
    }

    private function inboundCount(Content $page): int
    {
        $siteId = (string) $page->site_id;
        if (! isset($this->graphs[$siteId])) {
            $site = Site::withoutGlobalScope(VisibleSiteScope::class)->find($siteId);
            $this->graphs[$siteId] = $site !== null ? app(InternalLinkGraph::class)->build($site) : app(InternalLinkGraph::class);
        }

        return count($this->graphs[$siteId]->inbound((string) $page->id));
    }

    // ── shared queries / helpers ───────────────────────────────────────────────────────────────────

    private function url(string $siteId, string $slug): string
    {
        $domain = Site::withoutGlobalScope(VisibleSiteScope::class)->find($siteId)?->domain_url;
        $home = is_string($domain) && trim($domain) !== '' ? rtrim($domain, '/') : '';

        return $home.'/'.ltrim($slug, '/');
    }

    /** @return Collection<int, Location> */
    private function liveLocations(): Collection
    {
        // ActiveLocationScope (merged_into_id) stays applied → merged rows excluded; drop only SiteScope
        // so this spans every tenant.
        return Location::withoutGlobalScope(SiteScope::class)->get();
    }

    private function location(string $id): ?Location
    {
        return Location::withoutGlobalScope(SiteScope::class)->find($id);
    }

    /** @return Collection<int, Content> live town pages (a parent-pinned location page), all tenants. */
    private function liveTownPages(): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->whereNotNull('parent_location_id')
            ->get();
    }

    /** @return Collection<int, Content> a location's live town pages (parented to it). */
    private function livePagesUnder(Location $location): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->where('parent_location_id', $location->id)
            ->get();
    }

    /** @return list<string> */
    private function countyGeoids(Location $location): array
    {
        return is_array($location->county_geoids)
            ? array_map('strval', $location->county_geoids)
            : [];
    }

    /** A partial-insert stub: no county data, not a storefront, and zero pages of its own. */
    private function looksLikePartialInsert(Location $location): bool
    {
        if ($this->countyGeoids($location) !== [] || $location->is_storefront) {
            return false;
        }

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where(fn ($q) => $q->where('location_id', $location->id)->orWhere('parent_location_id', $location->id))
            ->count();

        return $pages === 0;
    }

    private function addressKey(Location $location): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $location->address) ?? ''));
    }
}
