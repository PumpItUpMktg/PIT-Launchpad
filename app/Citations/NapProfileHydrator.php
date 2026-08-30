<?php

namespace App\Citations;

use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;

/**
 * Derives a location's canonical NAP profile from the GBP/Places data already imported onto the `Location`
 * (business name, structured `address_components`, phone, hours, primary category) — so selecting a Google
 * Business Profile fills the NAP instead of leaving the operator to re-type it.
 *
 * Non-destructive by design. The NAP is "the one authoritative submission payload," so hydration NEVER
 * overwrites an operator's value: it creates the profile when none exists, and on an existing profile fills
 * ONLY fields that are currently blank. It also refuses to create a half-built row — if Google is missing any
 * of the NOT-NULL columns it skips and reports which, leaving manual entry as the path.
 */
final class NapProfileHydrator
{
    /** The NOT-NULL NAP columns — all must be derivable before we create a fresh profile. */
    private const REQUIRED = ['business_name', 'address_1', 'city', 'state', 'postal', 'phone_primary'];

    public function hydrate(Location $location): NapHydrationResult
    {
        $derived = $this->deriveFromLocation($location);

        $existing = LocationNapProfile::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)
            ->first();

        if ($existing === null) {
            $missing = array_values(array_filter(
                self::REQUIRED,
                fn (string $field): bool => ! array_key_exists($field, $derived),
            ));
            if ($missing !== []) {
                return NapHydrationResult::skippedMissing($missing);
            }

            $profile = new LocationNapProfile($derived);
            $profile->site_id = $location->site_id;
            $profile->location_id = $location->id;
            $profile->save();

            return NapHydrationResult::createdWith(array_keys($derived));
        }

        $filled = [];
        foreach ($derived as $field => $value) {
            if ($this->isBlank($existing->getAttribute($field))) {
                $existing->setAttribute($field, $value);
                $filled[] = $field;
            }
        }

        if ($filled === []) {
            return NapHydrationResult::noop();
        }

        $existing->save();

        return NapHydrationResult::updatedWith($filled);
    }

    /**
     * Map the GBP-backed Location onto the NAP's discrete fields, dropping anything Google didn't supply. Also
     * the fill source for the operator-facing "Autofill from GBP" form action.
     *
     * @return array<string, mixed>
     */
    public function deriveFromLocation(Location $location): array
    {
        $parts = $this->addressParts($location->address_components ?? []);

        $derived = [
            'business_name' => (string) $location->name,
            'address_1' => $parts['address_1'],
            'address_2' => $parts['address_2'],
            'city' => $parts['city'],
            'state' => $parts['state'],
            'postal' => $parts['postal'],
            'phone_primary' => (string) ($location->phone ?? ''),
            'hours' => is_array($location->hours) && $location->hours !== [] ? $location->hours : null,
            'categories' => $location->primary_category !== null && $location->primary_category !== ''
                ? [(string) $location->primary_category]
                : null,
        ];

        return array_filter($derived, fn ($value): bool => ! $this->isBlank($value));
    }

    /**
     * Pull street / unit / city / state / postal out of Google's structured address components. State uses the
     * short_name (two-letter code); everything else the long_name. Falls back through the usual locality types.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array{address_1: string, address_2: string, city: string, state: string, postal: string}
     */
    private function addressParts(array $components): array
    {
        $pick = function (array $types, string $key = 'long_name') use ($components): string {
            foreach ($components as $component) {
                $componentTypes = $component['types'] ?? [];
                if (! is_array($componentTypes)) {
                    continue;
                }
                foreach ($types as $type) {
                    if (in_array($type, $componentTypes, true)) {
                        return trim((string) ($component[$key] ?? ''));
                    }
                }
            }

            return '';
        };

        $streetNumber = $pick(['street_number']);
        $route = $pick(['route']);

        return [
            'address_1' => trim($streetNumber.' '.$route),
            'address_2' => $pick(['subpremise']),
            'city' => $pick(['locality', 'postal_town', 'sublocality']),
            'state' => $pick(['administrative_area_level_1'], 'short_name'),
            'postal' => $pick(['postal_code']),
        ];
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
