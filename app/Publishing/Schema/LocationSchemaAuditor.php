<?php

namespace App\Publishing\Schema;

use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\SiteContact;

/**
 * Verifies the LocalBusiness JSON-LD NAP on a site's location (storefront) pages by composing the REAL
 * node through {@see LocationSchemaBuilder} — the exact payload that ships — and checking the five
 * failure modes that break a GBP↔site association:
 *
 *  1. parentOrganization links to #org (a branch, not a duplicate business).
 *  2. No telephone collision — the LocalBusiness line differs from the corporate #org line (a storefront
 *     resolving the corporate number is the classic "877 in both" bug).
 *  3. A storefront emits its own street address (not omitted, not the corporate address).
 *  4. The schema address is surfaced verbatim so it can be eyeballed against the GBP listing.
 *  5. areaServed lives only on the LocalBusiness, never on #org.
 *
 * Read-only — it composes and inspects, never writes. The `address matches GBP character-for-character`
 * check (4) can't be automated here (no GBP fetch), so the resolved address string is reported for a
 * human to compare.
 */
class LocationSchemaAuditor
{
    public function __construct(
        private readonly LocationSchemaBuilder $schema = new LocationSchemaBuilder,
        private readonly SiteContact $contact = new SiteContact,
    ) {}

    /**
     * @return list<array{
     *   page: string, slug: string, storefront: bool,
     *   telephone: ?string, address: ?string, org_telephone: ?string,
     *   flags: list<string>, ok: bool,
     * }>
     */
    public function audit(Site $site): array
    {
        $home = is_string($site->domain_url) ? rtrim($site->domain_url, '/').'/' : '/';
        $orgPhone = $this->contact->phone($site);

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('location_id')
            ->orderBy('title')
            ->get();

        $out = [];
        foreach ($pages as $page) {
            $location = Location::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->find($page->location_id);
            if ($location === null) {
                continue;
            }

            $node = $this->schema->buildForLocation($page, $location, $site, $home, null);
            $storefront = (bool) $location->is_storefront;
            $telephone = $this->stringOrNull($node['telephone'] ?? null);
            $address = $this->addressLine($node['address'] ?? null);

            $flags = [];
            // 1. parentOrganization → #org.
            if (! $this->present($node['parentOrganization']['@id'] ?? null)) {
                $flags[] = 'no parentOrganization @id (unrelated entity, not a branch)';
            }
            // 2. telephone collision / missing.
            if ($telephone === null) {
                $flags[] = 'no telephone on the LocalBusiness';
            } elseif ($orgPhone !== null && $this->digits($telephone) === $this->digits($orgPhone)) {
                $flags[] = "telephone collides with the corporate #org line ({$telephone})";
            }
            // 3. storefront must carry its own address.
            if ($storefront && $address === null) {
                $flags[] = 'storefront with NO address in the schema (populate the Location address from GBP)';
            }
            // 5. areaServed must not be on #org.
            if (isset($node['parentOrganization']['areaServed'])) {
                $flags[] = 'areaServed leaked onto the #org node';
            }

            $out[] = [
                'page' => (string) $page->title,
                'slug' => (string) $page->slug,
                'storefront' => $storefront,
                'telephone' => $telephone,
                'address' => $address,
                'org_telephone' => $orgPhone,
                'flags' => $flags,
                'ok' => $flags === [],
            ];
        }

        return $out;
    }

    private function addressLine(mixed $address): ?string
    {
        if (is_string($address)) {
            return $this->stringOrNull($address);
        }
        if (! is_array($address)) {
            return null;
        }
        $parts = array_filter([
            $this->stringOrNull($address['streetAddress'] ?? null),
            $this->stringOrNull($address['addressLocality'] ?? null),
            $this->stringOrNull($address['addressRegion'] ?? null),
            $this->stringOrNull($address['postalCode'] ?? null),
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private function stringOrNull(mixed $value): ?string
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
