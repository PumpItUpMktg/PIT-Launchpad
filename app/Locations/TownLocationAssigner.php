<?php

namespace App\Locations;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Assigns each TOWN page to the physical Location that serves its town — the grouping the Live
 * Locations board renders. The source of truth is `Location.served_towns` (the cannibalization
 * guard makes a town belong to exactly ONE location per site, so the match is deterministic);
 * a single-location site assigns every town page to its only location (nothing to disambiguate).
 * Unmatched pages stay unassigned — the board surfaces them with an assign-location picker rather
 * than guessing. Idempotent; a re-run never moves a manual assignment unless the served-towns say so.
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

        // town key (lowercase name) → owning location id, from served_towns (unique by the guard).
        $owners = [];
        foreach ($locations as $location) {
            foreach ($location->served_towns ?? [] as $row) {
                $name = mb_strtolower(trim((string) ($row['name'] ?? '')));
                if ($name !== '') {
                    $owners[$name] = (string) $location->id;
                }
            }
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

    /** "Norristown, PA" / "Norristown" → "norristown" (the served_towns name key). */
    private function townKey(string $title): string
    {
        $name = trim((string) preg_replace('/,\s*[A-Za-z]{2}$/', '', trim($title)));

        return mb_strtolower($name);
    }
}
