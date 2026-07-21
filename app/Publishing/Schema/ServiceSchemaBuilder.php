<?php

namespace App\Publishing\Schema;

use App\Models\Content;
use App\Models\Location;
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
 * Shape: a `Service` whose `provider` is the sitewide corporate `Organization`
 * (#org, {@see OrganizationSchemaBuilder}) inline, so the @id resolves in the page
 *
 * @graph. A geo-neutral service page carries NO areaServed and NO storefront NAP —
 * coverage + store address live ONLY on a location page's LocalBusiness node. Every
 * field degrades by OMISSION, never fabrication — no arbitrary serviceType, no
 * partial NAP.
 *
 * Reads are SiteScope-free + keyed on site_id (publish jobs may run without a
 * resolved CurrentSite), mirroring the assembler's other cross-entity reads.
 */
class ServiceSchemaBuilder
{
    public function __construct(protected readonly OrganizationSchemaBuilder $org = new OrganizationSchemaBuilder) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Content $content, Site $site, string $home, ?string $canonical): array
    {
        $service = $this->subjectService($content, $site);
        $keyword = $this->pageKeyword($content);

        $node = array_filter([
            '@type' => 'Service',
            '@id' => $this->absolute($canonical) ? $canonical.'#service' : null,
            'name' => $service?->name,
            // serviceType = the page's PRIMARY KEYWORD (the search intent, hub+spoke relay), falling
            // back to the service name; omitted when neither resolves (the plugin then falls back to
            // the page title).
            'serviceType' => $keyword ?? $service?->name,
            'description' => $this->str($service?->description),
            // provider = the sitewide corporate Organization (#org), inline so the @id resolves in the
            // page @graph. A geo-neutral service page carries NO areaServed and NO store address —
            // coverage + storefront NAP live ONLY on location-page LocalBusiness nodes.
            'provider' => $this->org->build($site, $home),
            // An honest price range on the record ⇒ a real Offer; absent ⇒ no offers at all.
            'offers' => $this->priceOffer($service),
        ], $this->present(...));

        return $node;
    }

    /**
     * The HUB (category) node: the category Service + a hasOfferCatalog ItemList of the silo's
     * spoke services, each with its REAL page URL — the schema mirror of the hub's services grid.
     *
     * @param  list<array{name: string, url: string}>  $spokes  resolved child spokes (title + permalink)
     * @return array<string, mixed>
     */
    public function buildForHub(Content $content, Site $site, string $home, ?string $canonical, array $spokes): array
    {
        $keyword = $this->pageKeyword($content);
        $name = $keyword ?? $this->str($content->title);

        $items = [];
        foreach ($spokes as $i => $spoke) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'item' => array_filter([
                    '@type' => 'Service',
                    'name' => $spoke['name'],
                    'url' => $this->absolute($spoke['url']) ? $spoke['url'] : null,
                ], $this->present(...)),
            ];
        }

        return array_filter([
            '@type' => 'Service',
            '@id' => $this->absolute($canonical) ? $canonical.'#service' : null,
            'name' => $name,
            'serviceType' => $name,
            'provider' => $this->org->build($site, $home),
            'hasOfferCatalog' => $items !== [] ? array_filter([
                '@type' => 'OfferCatalog',
                'name' => $name,
                'itemListElement' => $items,
            ], $this->present(...)) : null,
        ], $this->present(...));
    }

    /** The page's primary keyword (Content.target_keyword_id), or null. */
    protected function pageKeyword(Content $content): ?string
    {
        return $this->str($content->targetKeyword()->withoutGlobalScope(SiteScope::class)->value('query'));
    }

    /**
     * A real Offer node from the service record's honest price range — ONLY when both bounds exist
     * (the cost decision: an empty range means NO price markup, never a blank or invented one).
     *
     * @return array<string, mixed>|null
     */
    private function priceOffer(?Service $service): ?array
    {
        $range = is_array($service?->price_range) ? $service->price_range : [];
        $low = $range['low'] ?? null;
        $high = $range['high'] ?? null;
        if (! is_numeric($low) || ! is_numeric($high) || (float) $high <= 0) {
            return null;
        }

        return [
            '@type' => 'Offer',
            'priceSpecification' => [
                '@type' => 'PriceSpecification',
                'minPrice' => (float) $low,
                'maxPrice' => (float) $high,
                'priceCurrency' => 'USD',
            ],
        ];
    }

    /**
     * The page's subject Service: its own pin (`primary_service_id`, the spoke's service) first,
     * then the silo's pillar-ordered fallback.
     */
    private function subjectService(Content $content, Site $site): ?Service
    {
        if ($content->primary_service_id !== null) {
            $pinned = Service::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->find($content->primary_service_id);
            if ($pinned !== null) {
                return $pinned;
            }
        }

        return $this->pillarService($content, $site);
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
     * A structured PostalAddress from Google address_components when present, else
     * the flat address string (schema.org accepts address as Text).
     *
     * @return array<string, mixed>|string|null
     */
    protected function postalAddress(Location $location): array|string|null
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
    protected function openingHours(Location $location): array
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
     * @return list<string>
     */
    protected function sameAs(?SiteBranding $branding): array
    {
        $sameAs = is_array($branding?->same_as) ? $branding->same_as : [];

        return array_values(array_filter(array_map(fn ($u) => $this->str($u), $sameAs), $this->present(...)));
    }

    protected function absolute(?string $url): bool
    {
        return is_string($url) && str_starts_with($url, 'http');
    }

    /** A trimmed non-empty string, or null. */
    protected function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** The single degrade-by-omission predicate: drop null / '' / []. */
    protected function present(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
