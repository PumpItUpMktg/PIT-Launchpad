<?php

namespace App\Locations;

use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Promotes a site's location-page Locations from service-area to STOREFRONT (`is_storefront = true`) so
 * their own street address renders on the location page AND in the LocalBusiness schema — both gate the
 * address on `is_storefront` (a mobile/SAB business hides its base address by design; a real office with
 * a public GBP address should show it).
 *
 * Only a Location that already HAS an address (a flat `address` OR structured `address_components`) is
 * promoted — flipping the flag on an address-less row would just yield an empty storefront. Rows meant to
 * be offices but missing an address are reported separately: their address must be populated (from GBP or
 * by hand) before the schema can carry it.
 *
 * PREVIEW BY DEFAULT — `plan($site, apply: false)` computes without touching anything; `apply: true`
 * flips the flag in one transaction. After an apply, repush the affected location pages so the address
 * ships.
 */
class StorefrontPromoter
{
    /**
     * @return array{promote: list<array{id: string, name: string, address: string}>, missing: list<array{id: string, name: string}>, already: int}
     */
    public function plan(Site $site, bool $apply): array
    {
        $locations = $this->pinnedLocations($site);

        $promote = [];
        $missing = [];
        $already = 0;

        foreach ($locations as $location) {
            if ((bool) $location->is_storefront) {
                $already++;

                continue;
            }

            $address = $this->addressLine($location);
            if ($address !== null) {
                $promote[] = ['id' => $location->id, 'name' => (string) $location->name, 'address' => $address];
            } else {
                $missing[] = ['id' => $location->id, 'name' => (string) $location->name];
            }
        }

        if ($apply && $promote !== []) {
            DB::transaction(function () use ($promote): void {
                Location::withoutGlobalScope(SiteScope::class)
                    ->whereIn('id', array_column($promote, 'id'))
                    ->update(['is_storefront' => true]);
            });
        }

        return ['promote' => $promote, 'missing' => $missing, 'already' => $already];
    }

    /**
     * The site's Locations that back a location page (page_type=location, pinned via location_id) — the
     * exact set the audit reports and the storefront gate applies to.
     *
     * @return Collection<int, Location>
     */
    private function pinnedLocations(Site $site): Collection
    {
        $ids = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('location_id')
            ->pluck('location_id')
            ->unique()
            ->all();

        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();
    }

    /** A human address line from the flat string, else assembled from GBP components; null when neither exists. */
    private function addressLine(Location $location): ?string
    {
        $flat = trim((string) $location->address);
        if ($flat !== '') {
            return $flat;
        }

        $components = is_array($location->address_components) ? $location->address_components : [];
        if ($components === []) {
            return null;
        }

        $part = function (string $type) use ($components): string {
            foreach ($components as $c) {
                if (in_array($type, $c['types'] ?? [], true)) {
                    return trim((string) ($c['long_name'] ?? ''));
                }
            }

            return '';
        };

        $line = trim(trim($part('street_number').' '.$part('route')).', '.$part('locality'), ', ');

        return $line === '' ? null : $line;
    }
}
