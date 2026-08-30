<?php

namespace App\Citations;

use App\Integrations\Places\PlacesProvider;
use App\Models\Location;
use App\Support\Phone;

/**
 * Re-pulls a location's Google Business Profile from the Places seam and refreshes the GBP-sourced columns on the
 * `Location` (name, address, phone, coordinates, GBP url, website, hours) so the platform's cached copy of the
 * GBP never drifts. Then runs the {@see NapProfileHydrator}, which propagates the fresh values into the NAP under
 * its GBP-owned-but-editable policy (operator overrides preserved). The scheduled counterpart to the operator's
 * "Import from Google" — same data channel, run on a cadence instead of by hand.
 *
 * Only GBP-provided values are touched, and a value is never wiped by an empty one Google didn't return; the
 * operator-owned Location fields (email, storefront flag, booking url, market notes, coverage) are left alone.
 */
final class PlacesRefresher
{
    public function __construct(
        private readonly PlacesProvider $places,
        private readonly NapProfileHydrator $hydrator,
    ) {}

    public function refresh(Location $location): PlacesRefreshResult
    {
        $placeId = (string) ($location->place_id ?? '');
        if ($placeId === '') {
            return PlacesRefreshResult::noPlaceId();
        }

        $details = $this->places->details($placeId);
        if ($details === null) {
            return PlacesRefreshResult::notFound();
        }

        $candidate = [
            'name' => $details->name,
            'address' => $details->address,
            'address_components' => $details->addressComponents,
            'phone' => $details->phone !== null ? Phone::toE164($details->phone) : null,
            'lat' => $details->lat,
            'lng' => $details->lng,
            'gbp_url' => $details->gbpUrl,
            'website' => $details->website,
            'hours' => $details->hours !== [] ? $details->hours : null,
        ];

        $changed = [];
        foreach ($candidate as $field => $value) {
            if ($this->isBlank($value)) {
                continue; // never overwrite a stored value with something Google didn't return
            }
            if (! $this->equals($location->getAttribute($field), $value)) {
                $location->setAttribute($field, $value);
                $changed[] = $field;
            }
        }

        if ($changed !== []) {
            $location->save();
        }

        // Always run the hydrator: it creates a missing NAP and re-syncs GBP-tracked fields (no-op when nothing moved).
        $nap = $this->hydrator->hydrate($location);

        return PlacesRefreshResult::ran($changed, $nap);
    }

    private function equals(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return $a == $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.0000001; // lat/lng are stored as decimals
        }

        return (string) $a === (string) $b;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
