<?php

namespace App\Locations;

use App\Build\BuildManifestAssembler;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;

/**
 * Assigns each TOWN page to the physical Location that serves its town — the grouping the Live
 * Locations board renders and the brick-and-mortar tag the operator reads.
 *
 * The source of truth is the SAME coverage that decided the town was worth a page in the first
 * place: `CoverageArea.source_location_ids` (the GBP-derived reach computed at intake — one owning
 * location per town while the cannibalization guard keeps overlap at zero). This is authoritative
 * because it is exactly what {@see BuildManifestAssembler} reads to materialize the page,
 * so every page-worthy town already carries its owning GBP. `Location.served_towns` (the
 * hand-curated GBP service-area list) is a FALLBACK for towns not in a computed coverage area, and a
 * single-location site assigns every town page to its only location (nothing to disambiguate).
 *
 * Unmatched pages stay unassigned — the board surfaces them with an assign-location picker rather
 * than guessing (a town page with no owning coverage is usually a stale page that no shop reaches).
 * Idempotent; a re-run never moves a manual assignment unless the coverage/served-towns say so.
 */
class TownLocationAssigner
{
    /**
     * @return array{assigned: int, unmatched: list<string>}
     */
    public function assign(Site $site): array
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();

        // town key (lowercase name) → owning location id. Coverage areas win (the intake-computed
        // GBP reach that materialized the page); served_towns fills any gap.
        $owners = $this->servedTownOwners($locations);
        foreach ($this->coverageOwners($site) as $key => $ownerId) {
            $owners[$key] = $ownerId; // coverage is authoritative — overwrite the served-towns guess
        }

        $townPages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id') // the pinned location LANDING page is the location, not a town under it
            ->get();

        $assigned = 0;
        $unmatched = [];
        foreach ($townPages as $page) {
            $ownerId = $owners[$this->townKey((string) $page->title)] ?? null;

            // A single-location site has nothing to disambiguate — every town belongs to it.
            if ($ownerId === null && $locations->count() === 1) {
                $ownerId = (string) $locations->first()->id;
            }

            if ($ownerId === null) {
                if ($page->parent_location_id === null) {
                    $unmatched[] = (string) $page->title;
                }

                continue; // keep any manual assignment; surface the truly unassigned
            }

            if ((string) $page->parent_location_id !== $ownerId) {
                $page->forceFill(['parent_location_id' => $ownerId])->save();
                $assigned++;
            }
        }

        return ['assigned' => $assigned, 'unmatched' => $unmatched];
    }

    /**
     * town key → owning location id, from the intake-computed coverage areas. `source_location_ids`
     * records which physical location(s) reach the town; the first is the primary owner for grouping
     * (overlap is held at zero by the cannibalization guard, so there is normally exactly one).
     *
     * @return array<string, string>
     */
    private function coverageOwners(Site $site): array
    {
        $owners = [];
        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get(['name', 'source_location_ids']);

        foreach ($areas as $area) {
            $sources = is_array($area->source_location_ids)
                ? array_values(array_filter(array_map('strval', $area->source_location_ids), fn (string $id): bool => $id !== ''))
                : [];
            $key = mb_strtolower(trim((string) $area->name));
            if ($key === '' || $sources === []) {
                continue;
            }
            $owners[$key] ??= $sources[0]; // first coverage row for a name wins
        }

        return $owners;
    }

    /**
     * town key → owning location id, from the hand-curated GBP service-area lists (the fallback).
     *
     * @param  Collection<int, Location>  $locations
     * @return array<string, string>
     */
    private function servedTownOwners(Collection $locations): array
    {
        $owners = [];
        foreach ($locations as $location) {
            foreach ($location->served_towns ?? [] as $row) {
                $name = mb_strtolower(trim((string) ($row['name'] ?? '')));
                if ($name !== '') {
                    $owners[$name] = (string) $location->id;
                }
            }
        }

        return $owners;
    }

    /** "Norristown, PA" / "Norristown" → "norristown" (the town-name key). */
    private function townKey(string $title): string
    {
        $name = trim((string) preg_replace('/,\s*[A-Za-z]{2}$/', '', trim($title)));

        return mb_strtolower($name);
    }
}
