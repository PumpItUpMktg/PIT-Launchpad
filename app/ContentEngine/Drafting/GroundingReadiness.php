<?php

namespace App\ContentEngine\Drafting;

use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiteBranding;
use App\Standard\StandardPageIntake;

/**
 * Can this planned page resolve to REAL grounding — i.e. would {@see PageGroundingAssembler} feed the
 * drafter actual intake entities, not an empty prompt? It mirrors the assembler's resolution so the
 * surfaces can gate generation honestly: a page generates for real only when grounded; everything
 * else shows "grounding pending" rather than faking an empty draft (DraftGuard would reject it anyway).
 *
 * - **Service-family pages** (service / hub / pillar / cluster) need ≥1 resolvable §1 Service —
 *   silo-scoped when the page pins a `silo_id`, else the assembler's site-wide fallback.
 * - **Location pages** pinned to a §1 Location (`location_id`) ground on that record — its city or
 *   served towns (the generate-location guard); market-era pages need ≥1 §1 Market for the site.
 * - **Home / utility** (the standard-page composer's brand-narrative pages) ground on the brand
 *   itself — its identity (branding) and/or its real services. Either is enough to write honestly.
 *   (Kit presence is a separate gate the surfaces apply; a standard page with no kit stays held
 *   regardless of grounding.)
 *
 * NOTE (the spoke→Service gap): the guided flow materializes pages without a `silo_id` and does not
 * yet create §1 Service/Market entities (those come from §7a intake), so a pure guided-flow site reads
 * as "grounding pending" until that intake→§1 wiring lands — which is the honest state, not a bug here.
 */
class GroundingReadiness
{
    /** @var array<string, bool> site_id => has any §1 Service (per-request memo) */
    private array $serviceSites = [];

    /** @var array<string, bool> site_id => has any §1 Market (per-request memo) */
    private array $marketSites = [];

    /** @var array<string, bool> site_id => has brand-narrative grounding (per-request memo) */
    private array $narrativeSites = [];

    public function ready(Content $page): bool
    {
        return match ($page->page_type) {
            PageType::Service, PageType::Hub, PageType::Pillar, PageType::Cluster => $this->hasServices($page),
            PageType::Location => $this->hasLocationGrounding($page) || $this->hasMarkets($page),
            PageType::Home, PageType::Utility => $this->hasNarrativeGrounding($page),
            default => false,
        };
    }

    private function hasServices(Content $page): bool
    {
        $siteId = (string) $page->site_id;

        // A silo-scoped match is the real, page-specific grounding; the site-wide fallback mirrors
        // the assembler so a page is still "grounded" when no silo is pinned but services exist.
        if ($page->silo_id !== null
            && Service::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)
                ->whereHas('silos', fn ($q) => $q->withoutGlobalScope(SiteScope::class)->whereKey($page->silo_id))
                ->exists()
        ) {
            return true;
        }

        return $this->serviceSites[$siteId] ??= Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->exists();
    }

    /**
     * A PINNED location page grounds on its own §1 Location: a resolvable city or ≥1 served town —
     * the same bar the generate-location command's guard sets.
     */
    private function hasLocationGrounding(Content $page): bool
    {
        if ($page->location_id === null) {
            return false;
        }

        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $page->site_id)
            ->find($page->location_id);
        if ($location === null) {
            return false;
        }

        if (trim($location->cityState()['city']) !== '' || trim((string) $location->name) !== '') {
            return true;
        }

        foreach ($location->served_towns ?? [] as $town) {
            if (trim((string) ($town['name'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function hasMarkets(Content $page): bool
    {
        $siteId = (string) $page->site_id;

        return $this->marketSites[$siteId] ??= Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->exists();
    }

    /**
     * A brand-narrative page (home / about / why-us / faq) grounds on the brand itself: its identity
     * (a SiteBranding row) and/or its real services. Either is enough to write honestly; neither →
     * still pending.
     */
    private function hasNarrativeGrounding(Content $page): bool
    {
        // A Core page also needs its REQUIRED brand-narrative intake (About→story, Why-Choose-Us→
        // differentiators). Absent → held "needs intake", never drafted from thin air.
        if (StandardPageIntake::missingRequired($page) !== []) {
            return false;
        }

        $siteId = (string) $page->site_id;

        if (isset($this->narrativeSites[$siteId])) {
            return $this->narrativeSites[$siteId];
        }

        $has = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->exists()
            || Service::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->exists();

        return $this->narrativeSites[$siteId] = $has;
    }
}
