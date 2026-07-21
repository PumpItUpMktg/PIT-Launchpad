<?php

namespace App\Publishing;

use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The single source of truth for a site's SITE-WIDE contact NAP — so every surface (home hero, header/
 * footer chrome, main-phone CTA) resolves the SAME corporate number/address the same way, and no tenant
 * silently ships without one.
 *
 * The corporate NAP is captured in the business intake and lives at the SITE level (`sites.phone` +
 * the `corporate_*` address fields) — it is the number/address for the ENTIRE site, NOT a physical
 * location's. A multi-location tenant's individual location pages keep their own NAP (resolved
 * separately from the pinned Location); only when a site has no corporate value captured do these
 * readers fall back to the earliest Location so a legacy single-location tenant never ships empty.
 *
 * Resolution:
 *  - display phone   = the corporate business phone (`sites.phone`), else the earliest Location's phone;
 *  - address         = the corporate address line, else the earliest Location's address;
 *  - emergency phone = the site's dedicated emergency/after-hours line, else the display phone.
 *
 * Returns null only when a site has genuinely captured nothing anywhere — which the pre-publish
 * completeness nudge flags rather than letting it drop silently.
 */
final class SiteContact
{
    /** The site-wide number to show a visitor: the corporate business phone, else the primary location's. */
    public function phone(Site $site): ?string
    {
        $business = trim((string) $site->phone);
        if ($business !== '') {
            return $business;
        }

        // Legacy fallback: a tenant whose number was only ever captured on a Location (e.g. quick-create).
        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('created_at')
            ->value('phone');
        $location = is_string($location) ? trim($location) : '';

        return $location !== '' ? $location : null;
    }

    /** The site-wide address: the corporate address line, else the primary location's address. */
    public function address(Site $site): ?string
    {
        $corporate = trim((string) $site->corporateAddressLine());
        if ($corporate !== '') {
            return $corporate;
        }

        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('created_at')
            ->value('address');
        $location = is_string($location) ? trim($location) : '';

        return $location !== '' ? $location : null;
    }

    /** The emergency / after-hours number: the dedicated site line, else the display phone. */
    public function emergencyPhone(Site $site): ?string
    {
        $emergency = trim((string) $site->emergency_phone);

        return $emergency !== '' ? $emergency : $this->phone($site);
    }

    /** A `tel:` href for a display number, via the shared {@see PhoneNumber} formatter. */
    public function tel(?string $phone): ?string
    {
        return PhoneNumber::tel($phone);
    }

    /** The site-wide phone as human-readable display ("(877) 786-7834"). */
    public function display(Site $site): ?string
    {
        return PhoneNumber::display($this->phone($site));
    }
}
