<?php

namespace App\Publishing\Blocks;

use App\Integrations\Census\Geocoder;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Contact page's location PIN — the primary Location's geocoded coordinates, for a STOREFRONT
 * only (a mobile-only business shows its coverage footprint instead; its base address is never
 * mapped). The geocode result is cached on the Location row (latitude/longitude/geocoded_at), so the
 * Geocoder seam is hit once per address, not on every re-push; a changed address clears the cache at
 * write time... until then geocoded_at guards re-tries. Fail-open: a geocoder failure returns null
 * and the page degrades to the coverage map / no map — never a broken pin.
 */
final class NapPin
{
    public function __construct(private readonly Geocoder $geocoder) {}

    /**
     * @return array{lat: float, lng: float, label: string}|null
     */
    public function for(string $siteId): ?array
    {
        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->orderBy('created_at')
            ->first();

        if ($location === null || ! (bool) $location->is_storefront) {
            return null;
        }

        $address = trim((string) $location->address);
        if ($address === '') {
            return null;
        }

        if ($location->latitude === null && $location->geocoded_at === null) {
            try {
                $result = $this->geocoder->geocode($address);
            } catch (Throwable $e) {
                Log::warning('NAP-pin geocode failed — Contact degrades to the coverage map.', ['error' => $e->getMessage()]);
                $result = null;
            }

            // Stamp geocoded_at either way so a hard-to-geocode address isn't retried on every push.
            $location->forceFill([
                'latitude' => $result?->lat,
                'longitude' => $result?->lng,
                'geocoded_at' => now(),
            ])->save();
        }

        if ($location->latitude === null || $location->longitude === null) {
            return null;
        }

        return [
            'lat' => (float) $location->latitude,
            'lng' => (float) $location->longitude,
            'label' => $address,
        ];
    }
}
