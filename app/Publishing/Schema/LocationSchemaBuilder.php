<?php

namespace App\Publishing\Schema;

use App\Local\Proof\LocalReviewProvider;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;

/**
 * Builds the `LocalBusiness` JSON-LD node for a LOCATION page — composed from the page's pinned §1
 * Location AT ASSEMBLE TIME (never a stored snapshot), extending {@see ServiceSchemaBuilder} for the
 * shared address/hours/degrade helpers.
 *
 * The honesty rules, encoded:
 *  - `areaServed` = the location's own city + its served towns as named City nodes — the exact
 *    coverage claim the page makes, nothing wider.
 *  - A STOREFRONT emits its PostalAddress + GeoCoordinates + hasMap (the GBP link); a service-area
 *    business omits the street address entirely (Google's SAB guidance) — geo/hasMap only make sense
 *    with a public address, so they gate together.
 *  - `telephone` is the LOCATION's own number (the number a local caller should dial), falling back
 *    to nothing — never a different location's line.
 *  - NO review / aggregateRating properties. TODO(reviews-live): unlock these ONLY when a real
 *    {@see LocalReviewProvider} binds — marking up ratings with no on-page review
 *    source violates Google's structured-data guidelines.
 */
class LocationSchemaBuilder extends ServiceSchemaBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function buildForLocation(Content $content, Location $location, Site $site, string $home, ?string $canonical): array
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        $storefront = (bool) $location->is_storefront;

        return array_filter([
            '@type' => $this->str($branding?->entity_type) ?? 'LocalBusiness',
            // A PER-LOCATION @id (#location-{slug}) — this is one storefront, NOT the corporate entity.
            // parentOrganization links it to the sitewide #org (inline so the @id resolves in-graph).
            '@id' => $this->locationId($home, $content),
            'name' => $this->str($site->brand_name),
            'url' => $this->absolute($canonical) ? $canonical : $this->str($site->domain_url),
            'telephone' => $this->str($location->phone),
            'address' => $storefront ? $this->postalAddress($location) : null,
            'geo' => $storefront && $location->lat !== null && $location->lng !== null
                ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $location->lat, 'longitude' => (float) $location->lng]
                : null,
            'hasMap' => $storefront ? $this->str($location->gbp_url) : null,
            'openingHoursSpecification' => $this->openingHours($location),
            'areaServed' => $this->townsServed($location),
            'parentOrganization' => $this->org->build($site, $home),
            'sameAs' => $this->sameAs($branding),
        ], $this->present(...));
    }

    /** The per-location @id: {home}#location-{slug} (slashes in a nested slug flattened to dashes). */
    private function locationId(string $home, Content $content): ?string
    {
        if (! $this->absolute($home)) {
            return null;
        }
        $slug = str_replace('/', '-', trim((string) $content->slug, '/'));

        return rtrim($home, '/').'/#location'.($slug !== '' ? '-'.$slug : '');
    }

    /**
     * The page's coverage claim as City nodes: the location's own city first, then its served towns
     * (deduped, each with its state as containedInPlace). Only captured names — never invented.
     *
     * @return list<array<string, mixed>>
     */
    private function townsServed(Location $location): array
    {
        ['city' => $city, 'state' => $state] = $location->cityState();

        $rows = [];
        if (trim($city) !== '') {
            $rows[] = ['name' => trim($city), 'state' => trim($state)];
        }
        foreach ($location->served_towns ?? [] as $town) {
            $name = trim((string) ($town['name'] ?? ''));
            if ($name !== '') {
                $rows[] = ['name' => $name, 'state' => trim((string) ($town['state'] ?? ''))];
            }
        }

        $areas = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = mb_strtolower($row['name']).'|'.strtoupper($row['state']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $areas[] = array_filter([
                '@type' => 'City',
                'name' => $row['name'],
                'containedInPlace' => $row['state'] !== ''
                    ? ['@type' => 'AdministrativeArea', 'name' => $row['state']]
                    : null,
            ], $this->present(...));
        }

        return $areas;
    }
}
