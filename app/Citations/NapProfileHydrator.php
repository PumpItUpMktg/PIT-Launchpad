<?php

namespace App\Citations;

use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;

/**
 * Makes the Google Business Profile the source of truth for a location's canonical NAP. The fields a directory
 * is matched against — business name, address, phone, hours, category, website — are seeded verbatim from the
 * GBP/Places data imported onto the `Location`, so the submission payload matches the GBP by construction and
 * the operator verifies rather than types.
 *
 * GBP-owned-but-editable: the GBP-sourced fields keep tracking the GBP on every re-sync UNLESS the operator has
 * deliberately overridden one, in which case their value is preserved. Override detection is snapshot-based —
 * `LocationNapProfile.gbp_synced` records the last GBP value written per field; if the stored value still equals
 * that snapshot the field follows the GBP, otherwise it's treated as an operator override and left alone. On a
 * legacy row with no snapshot the first sync fills only blanks (never clobbers pre-existing manual data), then
 * begins tracking. Fields with no GBP equivalent (verification email, secondary phone, descriptions, logo,
 * photos) are never touched.
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
            $profile->gbp_synced = $derived; // snapshot: these values came from the GBP
            $profile->save();

            return NapHydrationResult::createdWith(array_keys($derived));
        }

        return $this->syncExisting($existing, $derived);
    }

    /**
     * Re-sync an existing NAP against the GBP, honoring operator overrides.
     *
     * @param  array<string, mixed>  $derived
     */
    private function syncExisting(LocationNapProfile $nap, array $derived): NapHydrationResult
    {
        $snapshot = is_array($nap->gbp_synced) ? $nap->gbp_synced : [];
        $changed = [];

        foreach ($derived as $field => $value) {
            $current = $nap->getAttribute($field);

            if (! array_key_exists($field, $snapshot)) {
                // No history for this field: fill only if blank, so a pre-existing manual value is preserved.
                if ($this->isBlank($current)) {
                    $nap->setAttribute($field, $value);
                    $changed[] = $field;
                }
            } elseif ($this->valuesEqual($current, $snapshot[$field])) {
                // Operator hasn't diverged from the last GBP value → keep tracking the GBP.
                if (! $this->valuesEqual($current, $value)) {
                    $nap->setAttribute($field, $value);
                    $changed[] = $field;
                }
            }
            // else: the stored value differs from the last GBP value → operator override, preserve it.

            $snapshot[$field] = $value; // snapshot always reflects what the GBP currently says
        }

        $nap->gbp_synced = $snapshot;

        if ($changed === [] && ! $nap->isDirty('gbp_synced')) {
            return NapHydrationResult::noop();
        }

        $nap->save();

        return $changed === [] ? NapHydrationResult::noop() : NapHydrationResult::updatedWith($changed);
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
            'website_url' => (string) ($location->website ?? ''),
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

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return $a == $b;
        }

        return (string) $a === (string) $b;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
