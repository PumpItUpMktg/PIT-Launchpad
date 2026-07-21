<?php

namespace App\Publishing\Schema;

use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Publishing\SiteContact;

/**
 * The sitewide CORPORATE identity node — `Organization`, `@id {home}#org` — emitted on every managed
 * page (inline under a service page's `provider` and a location page's `parentOrganization`, so the
 *
 * @id reference always resolves within the same @graph; the WP plugin merges a single page node).
 *
 * This is the corporate entity, deliberately NOT a LocalBusiness: no store address, no store phone,
 * no areaServed, no hours — those belong ONLY to a location page's LocalBusiness node. The corporate
 * NAP resolves through {@see SiteContact} (the single site-wide NAP source: sites.phone +
 * corporate_* address), so the Organization telephone/address match the header/footer chrome exactly.
 *
 * The `logo` is the logo IMAGE URL (`logo_set['url']`) — never a palette hex (the old #business node
 * read `logo_set['primary']`, a color, into the logo field). Every field degrades by OMISSION.
 */
class OrganizationSchemaBuilder
{
    public function __construct(private readonly SiteContact $contact = new SiteContact) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Site $site, string $home): array
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();

        return array_filter([
            '@type' => 'Organization',
            '@id' => $this->id($home),
            'name' => $this->str($site->brand_name),
            'legalName' => $this->str($site->legal_name),
            'alternateName' => $this->str($site->dba),
            'url' => $this->str($site->domain_url),
            'logo' => $this->logoUrl($branding),
            'telephone' => $this->contact->phone($site),
            'address' => $this->corporateAddress($site),
            'sameAs' => $this->sameAs($branding),
        ], $this->present(...));
    }

    /** The stable corporate @id ({home}#org), or null when the home URL isn't absolute. */
    public function id(string $home): ?string
    {
        return str_starts_with($home, 'http') ? rtrim($home, '/').'/#org' : null;
    }

    /** The logo IMAGE url from logo_set['url'] — NOT logo_set['primary'] (a palette hex). */
    private function logoUrl(?SiteBranding $branding): ?string
    {
        $set = is_array($branding?->logo_set) ? $branding->logo_set : [];

        return $this->str($set['url'] ?? null);
    }

    /**
     * The corporate address as a PostalAddress from the site's structured corporate_* fields (never a
     * Location's). Null unless at least a street or locality is captured — a partial address is dropped.
     *
     * @return array<string, mixed>|null
     */
    private function corporateAddress(Site $site): ?array
    {
        $street = $this->str($site->corporate_street);
        $city = $this->str($site->corporate_city);
        if ($street === null && $city === null) {
            return null;
        }

        return array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $street,
            'addressLocality' => $city,
            'addressRegion' => $this->str($site->corporate_state),
            'postalCode' => $this->str($site->corporate_postal_code),
            'addressCountry' => 'US',
        ], $this->present(...));
    }

    /**
     * @return list<string>
     */
    private function sameAs(?SiteBranding $branding): array
    {
        $sameAs = is_array($branding?->same_as) ? $branding->same_as : [];

        return array_values(array_filter(array_map(fn ($u) => $this->str($u), $sameAs), $this->present(...)));
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function present(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
