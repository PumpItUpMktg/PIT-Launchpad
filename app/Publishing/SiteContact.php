<?php

namespace App\Publishing;

use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The single source of truth for a site's contact phone(s) — so every surface (home hero, header/footer
 * chrome, CTA, schema) resolves the SAME number the same way, and no tenant silently ships without one.
 *
 * Resolution, bulletproof for any onboarding path:
 *  - display phone  = the primary Location's number (NAP), else the site-level business phone;
 *  - emergency phone = the site's dedicated emergency/after-hours line, else the display phone.
 *
 * Returns null only when a site has genuinely captured no number anywhere — which the pre-publish
 * completeness nudge flags rather than letting it drop silently.
 */
final class SiteContact
{
    /** The number to show a visitor: the primary location's phone, else the site business phone. */
    public function phone(Site $site): ?string
    {
        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('created_at')
            ->value('phone');

        $location = is_string($location) ? trim($location) : '';
        if ($location !== '') {
            return $location;
        }

        $business = trim((string) $site->phone);

        return $business !== '' ? $business : null;
    }

    /** The emergency / after-hours number: the dedicated site line, else the display phone. */
    public function emergencyPhone(Site $site): ?string
    {
        $emergency = trim((string) $site->emergency_phone);

        return $emergency !== '' ? $emergency : $this->phone($site);
    }

    /** A `tel:` href for a display number (digits + leading +), or null when there's no number. */
    public function tel(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = (string) preg_replace('/[^0-9+]/', '', $phone);

        return $digits !== '' ? 'tel:'.$digits : null;
    }
}
