<?php

namespace App\Branding;

use App\Enums\ServiceSiloRole;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;

/**
 * Derives a human trade/industry descriptor for a Site to seed brand generation.
 * The wizard never persists the GBP `primary_category` (it only seeds the service
 * checklist), so the trade is read back from the Site's own Service Catalog —
 * pillar services first (the core offerings), falling back to all services, then
 * the brand name. The result pre-fills the interview's industry field, where the
 * operator can refine it before generating.
 *
 * Operator-context safe: it drops the SiteScope and queries the given site_id
 * explicitly, so it works for any tenant regardless of the resolved current site.
 */
class IndustryResolver
{
    public function for(Site $site): string
    {
        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get(['name', 'silo_role']);

        if ($services->isEmpty()) {
            return trim((string) ($site->brand_name ?? ''));
        }

        $pillars = $services->where('silo_role', ServiceSiloRole::Pillar);
        $named = ($pillars->isNotEmpty() ? $pillars : $services)
            ->pluck('name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values();

        return $named->isEmpty()
            ? trim((string) ($site->brand_name ?? ''))
            : $named->implode(', ');
    }
}
