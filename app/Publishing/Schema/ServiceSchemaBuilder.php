<?php

namespace App\Publishing\Schema;

use App\Models\Content;
use App\Models\Location;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteBranding;

/**
 * Builds the `Service` JSON-LD node (the page-type schema node the companion
 * plugin drops into its @graph via seo.schema_payload) for a service page —
 * composed from §1 entities AT ASSEMBLE TIME, never a stored snapshot, so the
 * schema can't drift from the live data (the seoTitle lesson).
 *
 * Shape: a `Service` with an INLINE `LocalBusiness` provider carrying a stable
 * `@id` ({home}#business) so a later sitewide-node migration can switch pages to
 * an @id reference without reshaping. Every field degrades by OMISSION, never
 * fabrication — no invented radius, no arbitrary serviceType, no partial NAP.
 *
 * Reads are SiteScope-free + keyed on site_id (publish jobs may run without a
 * resolved CurrentSite), mirroring the assembler's other cross-entity reads.
 */
class ServiceSchemaBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Content $content, Site $site, string $home, ?string $canonical): array
    {
        $service = $this->pillarService($content, $site);

        $node = array_filter([
            '@type' => 'Service',
            '@id' => $this->absolute($canonical) ? $canonical.'#service' : null,
            'name' => $service?->name,
            // serviceType = the silo's pillar service; omitted when the silo has no
            // service (name then falls back to the page title in the plugin).
            'serviceType' => $service?->name,
            'description' => $this->str($service?->description),
            'provider' => $this->provider($site, $home),
            'areaServed' => $this->areaServed($site),
        ], $this->present(...));

        return $node;
    }

    /**
     * The silo's pillar Service (silo_role=pillar first, then stable name/ULID
     * order — so a no-pillar silo deterministically falls back to its first
     * service, and a silo with no service resolves to null → serviceType omitted).
     */
    private function pillarService(Content $content, Site $site): ?Service
    {
        if ($content->silo_id === null) {
            return null;
        }

        return Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereHas('silos', fn ($q) => $q->withoutGlobalScope(SiteScope::class)->whereKey($content->silo_id))
            ->orderByRaw("CASE WHEN silo_role = 'pillar' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->orderBy('id')
            ->first();
    }

    /**
     * The inline LocalBusiness provider with a stable @id. NAP/geo/hours come from
     * the NAP cascade; everything is omitted when its source is absent.
     *
     * @return array<string, mixed>
     */
    private function provider(Site $site, string $home): array
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        $nap = $this->nap($site, $branding);

        return array_filter([
            '@type' => $this->str($branding?->entity_type) ?? 'LocalBusiness',
            '@id' => $this->absolute($home) ? rtrim($home, '/').'/#business' : null,
            'name' => $this->str($site->brand_name) ?? $this->str($nap['name'] ?? null),
            'legalName' => $this->str($site->legal_name),
            'alternateName' => $this->str($site->dba),
            'url' => $this->str($site->domain_url),
            'logo' => $this->str(is_array($branding?->logo_set) ? ($branding->logo_set['primary'] ?? null) : null),
            'telephone' => $this->str($nap['telephone'] ?? null),
            'address' => $nap['address'] ?? null,
            'geo' => $nap['geo'] ?? null,
            'openingHoursSpecification' => $nap['hours'] ?? null,
            'sameAs' => $this->sameAs($branding),
        ], $this->present(...));
    }

    /**
     * The NAP cascade: a storefront location with a complete address+geo →
     * any complete location → the branding canonical_nap (address/phone, NO geo)
     * → nothing. A partial NAP is never emitted.
     *
     * @return array{name?: string, telephone?: ?string, address?: mixed, geo?: array<string, mixed>, hours?: list<array<string, mixed>>}
     */
    private function nap(Site $site, ?SiteBranding $branding): array
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

        $complete = $locations->filter(fn (Location $l) => $this->hasAddress($l) && $l->lat !== null && $l->lng !== null);
        $chosen = $complete->firstWhere('is_storefront', true) ?? $complete->first();

        if ($chosen instanceof Location) {
            return array_filter([
                'telephone' => $this->str($chosen->phone),
                'address' => $this->postalAddress($chosen),
                'geo' => ['@type' => 'GeoCoordinates', 'latitude' => (float) $chosen->lat, 'longitude' => (float) $chosen->lng],
                'hours' => $this->openingHours($chosen),
            ], $this->present(...));
        }

        // Fallback: branding canonical_nap — address + phone, but no geo (so no
        // GeoCoordinates is fabricated).
        $canonicalNap = is_array($branding?->canonical_nap) ? $branding->canonical_nap : [];
        $address = $this->str($canonicalNap['address'] ?? null);

        return array_filter([
            'name' => $this->str($canonicalNap['name'] ?? null),
            'telephone' => $this->str($canonicalNap['phone'] ?? null),
            'address' => $address,
        ], $this->present(...));
    }

    private function hasAddress(Location $location): bool
    {
        return (is_array($location->address_components) && $location->address_components !== [])
            || $this->str($location->address) !== null;
    }

    /**
     * A structured PostalAddress from Google address_components when present, else
     * the flat address string (schema.org accepts address as Text).
     *
     * @return array<string, mixed>|string|null
     */
    private function postalAddress(Location $location): array|string|null
    {
        $components = is_array($location->address_components) ? $location->address_components : [];

        if ($components === []) {
            return $this->str($location->address);
        }

        $part = function (string $type, string $field = 'long_name') use ($components): ?string {
            foreach ($components as $c) {
                if (in_array($type, $c['types'] ?? [], true)) {
                    return $this->str($c[$field] ?? null);
                }
            }

            return null;
        };

        $street = trim(((string) $part('street_number')).' '.((string) $part('route')));

        $address = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $street !== '' ? $street : $this->str($location->address),
            'addressLocality' => $part('locality'),
            'addressRegion' => $part('administrative_area_level_1', 'short_name'),
            'postalCode' => $part('postal_code'),
        ], $this->present(...));

        return count($address) > 1 ? $address : $this->str($location->address);
    }

    /**
     * openingHoursSpecification from the location's hours map ({mon:{open,close},
     * sat:'closed', …}) — one spec per open day, closed days omitted.
     *
     * @return list<array<string, mixed>>
     */
    private function openingHours(Location $location): array
    {
        $days = [
            'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
            'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
        ];
        $hours = is_array($location->hours) ? $location->hours : [];

        $specs = [];
        foreach ($days as $key => $name) {
            $day = $hours[$key] ?? null;
            if (! is_array($day) || ! isset($day['open'], $day['close'])) {
                continue; // 'closed' or missing → omit
            }
            $specs[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => $name,
                'opens' => (string) $day['open'],
                'closes' => (string) $day['close'],
            ];
        }

        return $specs;
    }

    /**
     * areaServed = the covered markets as named City nodes (+ containedInPlace
     * AdministrativeArea when the market carries a region). No GeoCircle — no
     * radius field exists, and a fabricated one would over-claim coverage.
     *
     * @return list<array<string, mixed>>
     */
    private function areaServed(Site $site): array
    {
        $markets = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('is_covered', true)
            ->orderBy('name')
            ->get();

        $areas = [];
        foreach ($markets as $market) {
            $name = $this->str($market->name);
            if ($name === null) {
                continue;
            }
            $areas[] = array_filter([
                '@type' => 'City',
                'name' => $name,
                'containedInPlace' => ($region = $this->str($market->region)) !== null
                    ? ['@type' => 'AdministrativeArea', 'name' => $region]
                    : null,
            ], $this->present(...));
        }

        return $areas;
    }

    /**
     * @return list<string>
     */
    private function sameAs(?SiteBranding $branding): array
    {
        $sameAs = is_array($branding?->same_as) ? $branding->same_as : [];

        return array_values(array_filter(array_map(fn ($u) => $this->str($u), $sameAs), $this->present(...)));
    }

    private function absolute(?string $url): bool
    {
        return is_string($url) && str_starts_with($url, 'http');
    }

    /** A trimmed non-empty string, or null. */
    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** The single degrade-by-omission predicate: drop null / '' / []. */
    private function present(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
