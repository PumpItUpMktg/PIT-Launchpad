<?php

namespace App\Locations;

use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Ensures every base Location that has something honest to say gets its landing/hub page — the build
 * step that makes "a page per GBP/service location" automatic (previously only the manual
 * launchpad:generate-location command created one). Runs alongside {@see TownLocationAssigner} at
 * materialize; idempotent (the factory is keyed on location_id).
 *
 * A location with no city AND no served towns is skipped — nothing honest to build a local page from
 * (same guard as the command).
 */
final class LocationLandingSync
{
    public function __construct(private readonly LocationLandingFactory $factory) {}

    public function sync(Site $site): void
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();

        foreach ($locations as $location) {
            if ($this->hasSomethingToSay($location)) {
                $this->factory->findOrCreate($location);
            }
        }
    }

    private function hasSomethingToSay(Location $location): bool
    {
        ['city' => $city] = $location->cityState();
        if ($city === '') {
            $city = trim((string) $location->name);
        }

        $towns = count(array_filter(
            $location->served_towns ?? [],
            fn (array $t): bool => trim((string) ($t['name'] ?? '')) !== '',
        ));

        return $city !== '' || $towns > 0;
    }
}
